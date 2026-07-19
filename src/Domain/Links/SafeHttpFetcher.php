<?php

namespace Spoome\Domain\Links;

use RuntimeException;

/**
 * Fetch HTTP a prova di SSRF — barriera UNICA e CENTRALE per OGNI richiesta verso URL esterni
 * (unfurl pagina, endpoint oEmbed, image-proxy). Nessun altro codice deve aprire socket verso l'esterno:
 * tutto passa da qui, così i controlli vivono una volta sola e non si possono aggirare.
 *
 * Ogni regola è motivata (il "perché" è il punto: un SSRF nasce da un controllo mancante):
 *  - SCHEMA allow-list: solo http/https. `file://`,`gopher://`,`dict://`,`data:` permetterebbero
 *    lettura file locali / attacchi a servizi interni via curl → rifiutati.
 *  - PORTA allow-list: solo 80/443. Blocca il pivot verso Redis(6379)/SMTP(25)/SSH(22)/ecc.
 *  - DNS esplicito + blocco IP privati/riservati: si risolve l'host PRIMA di connettere e si
 *    rifiuta se QUALSIASI IP risolto cade in un range interno (10/8,127/8,169.254/16 incl. metadata
 *    cloud 169.254.169.254, 172.16/12, 192.168/16, ::1, fc00::/7, fe80::/10, IPv4-mapped IPv6, ecc.).
 *  - PIN dell'IP validato (CURLOPT_RESOLVE) + Host header preservato: curl si connette ESATTAMENTE
 *    all'IP che abbiamo validato → chiude la finestra TOCTOU/DNS-rebinding (una seconda risoluzione
 *    malevola non può dirottare la connessione).
 *  - RI-VALIDAZIONE ad ogni redirect: FOLLOWLOCATION è OFF; gestiamo i 3xx a mano e ogni hop ripassa
 *    schema+porta+IP. Un redirect verso 127.0.0.1 / 169.254.169.254 viene bloccato.
 *  - Cap redirect (≤3), timeout stretti (connect+totale), max byte in streaming (abort appena superato).
 *  - Nessuna credenziale propagata: niente cookie, niente Authorization, User-Agent dedicato e onesto.
 *  - Content-Type gate a carico del chiamante (HTML vs image/*), qui esposto in FetchResult.
 */
final class SafeHttpFetcher
{
    private const MAX_REDIRECTS   = 3;
    private const CONNECT_TIMEOUT = 4;   // secondi
    private const TOTAL_TIMEOUT   = 6;   // secondi (connect + trasferimento)
    private const ALLOWED_PORTS   = [80, 443];
    private const USER_AGENT      = 'SpoomeLinkBot/1.0 (+https://spoome.it)';

    /**
     * Esegue un GET sicuro. Ritorna FetchResult con status, content-type, body (eventualmente troncato
     * al cap) e URL finale. Solleva RuntimeException a QUALSIASI violazione (mai eseguire il fetch).
     */
    public function get(string $url, int $maxBytes): FetchResult
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('curl_unavailable');
        }

        $redirects = 0;
        while (true) {
            $parts = $this->parseAndValidate($url);
            $result = $this->request($parts, $maxBytes);

            // Redirect: gestito a mano per RI-VALIDARE ogni hop (schema+porta+IP).
            if ($result->status >= 300 && $result->status < 400 && $result->location !== null) {
                if (++$redirects > self::MAX_REDIRECTS) {
                    throw new RuntimeException('too_many_redirects');
                }
                $next = $this->resolveLocation($url, $result->location);
                $url = $next;
                continue;
            }
            return $result;
        }
    }

    /**
     * Valida schema/porta/host, risolve il DNS e valida OGNI IP, restituendo l'IP da "pinnare".
     * @return array{scheme:string,host:string,port:int,ip:string,url:string}
     */
    private function parseAndValidate(string $url): array
    {
        $p = parse_url($url);
        if ($p === false || empty($p['scheme']) || empty($p['host'])) {
            throw new RuntimeException('malformed_url');
        }
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new RuntimeException('scheme_blocked'); // no file/gopher/dict/data/ftp...
        }
        $host = $p['host'];
        // strip eventuali parentesi degli IPv6 literal [::1]
        $bareHost = trim($host, '[]');
        $port = isset($p['port']) ? (int) $p['port'] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, self::ALLOWED_PORTS, true)) {
            throw new RuntimeException('port_blocked');
        }

        $ip = $this->resolveAndValidate($bareHost);

        return ['scheme' => $scheme, 'host' => $bareHost, 'port' => $port, 'ip' => $ip, 'url' => $url];
    }

    /**
     * Risolve l'host (A + AAAA) e rifiuta se un QUALSIASI IP è privato/riservato (conservativo:
     * anche un solo IP interno tra i risolti blocca l'host → niente split-horizon/rebinding).
     * Ritorna l'IP validato da pinnare in connessione.
     */
    private function resolveAndValidate(string $host): string
    {
        // Host già IP literal (incluso IPv6): valida direttamente.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if ($this->isBlockedIp($host)) {
                throw new RuntimeException('ip_blocked');
            }
            return $host;
        }

        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }
        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $rec) {
                if (!empty($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }
        $ips = array_values(array_unique($ips));
        if ($ips === []) {
            throw new RuntimeException('dns_failed');
        }
        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new RuntimeException('ip_blocked');
            }
        }
        // Preferisci un IPv4 (curl+CURLOPT_RESOLVE più prevedibile), altrimenti il primo.
        foreach ($ips as $ip) {
            if (strpos($ip, ':') === false) {
                return $ip;
            }
        }
        return $ips[0];
    }

    /**
     * True se l'IP è in un range privato/loopback/link-local/riservato che NON deve mai essere contattato.
     */
    private function isBlockedIp(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return true; // non parsabile → blocca per sicurezza
        }

        // IPv4-mapped IPv6 (::ffff:a.b.c.d): estrai l'IPv4 sottostante e rivaluta.
        if (strlen($bin) === 16) {
            $prefix = substr($bin, 0, 12);
            if ($prefix === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
                return $this->isBlockedIpv4(substr($bin, 12));
            }
            return $this->isBlockedIpv6($bin);
        }
        return $this->isBlockedIpv4($bin);
    }

    /** @param string $bin 4 byte IPv4 in network order */
    private function isBlockedIpv4(string $bin): bool
    {
        if (strlen($bin) !== 4) {
            return true;
        }
        $long = unpack('N', $bin)[1];
        $ranges = [
            ['0.0.0.0',        8],   // "questo" host / non instradabile
            ['10.0.0.0',       8],   // privato
            ['100.64.0.0',    10],   // CGNAT
            ['127.0.0.0',      8],   // loopback
            ['169.254.0.0',   16],   // link-local (INCLUDE 169.254.169.254 metadata cloud)
            ['172.16.0.0',    12],   // privato
            ['192.0.0.0',     24],   // IETF protocol assignments
            ['192.0.2.0',     24],   // TEST-NET-1
            ['192.168.0.0',   16],   // privato
            ['198.18.0.0',    15],   // benchmarking
            ['198.51.100.0',  24],   // TEST-NET-2
            ['203.0.113.0',   24],   // TEST-NET-3
            ['224.0.0.0',      4],   // multicast
            ['240.0.0.0',      4],   // riservato / broadcast
        ];
        foreach ($ranges as [$net, $bits]) {
            $netLong = ip2long($net);
            // $bits qui è sempre uno dei prefissi CIDR letterali sopra (mai 0): la formula generale
            // produce comunque 0 se $bits fosse 0, quindi niente caso speciale da distinguere.
            $mask = (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;
            if (($long & $mask) === ($netLong & $mask)) {
                return true;
            }
        }
        return false;
    }

    /** @param string $bin 16 byte IPv6 in network order */
    private function isBlockedIpv6(string $bin): bool
    {
        // ::  (unspecified) e ::1 (loopback)
        if ($bin === str_repeat("\x00", 16)) {
            return true;
        }
        if ($bin === str_repeat("\x00", 15) . "\x01") {
            return true;
        }
        $b0 = ord($bin[0]);
        $b1 = ord($bin[1]);
        $b2 = ord($bin[2]);
        $b3 = ord($bin[3]);
        // fc00::/7 (ULA): primi 7 bit = 1111110
        if (($b0 & 0xFE) === 0xFC) {
            return true;
        }
        // fe80::/10 (link-local): fe80..febf
        if ($b0 === 0xFE && ($b1 & 0xC0) === 0x80) {
            return true;
        }
        // 2001:db8::/32 (documentazione)
        if ($b0 === 0x20 && $b1 === 0x01 && $b2 === 0x0D && $b3 === 0xB8) {
            return true;
        }
        // Teredo 2001:0000::/32 — tunnel IPv6-in-UDP: incapsula IP arbitrari (anche interni) → rifiuta in blocco.
        if ($b0 === 0x20 && $b1 === 0x01 && $b2 === 0x00 && $b3 === 0x00) {
            return true;
        }
        // 6to4 2002::/16 — l'IPv4 di destinazione è EMBEDDED nei byte 2..5: estrai e rivaluta come IPv4
        // (altrimenti 2002:7f00:0001::/48 dirotterebbe verso 127.0.0.1 aggirando i blocchi IPv4).
        if ($b0 === 0x20 && $b1 === 0x02) {
            return $this->isBlockedIpv4(substr($bin, 2, 4));
        }
        // NAT64 well-known 64:ff9b::/96 — IPv4 embedded negli ultimi 4 byte: estrai e rivaluta.
        if (substr($bin, 0, 12) === "\x00\x64\xFF\x9B\x00\x00\x00\x00\x00\x00\x00\x00") {
            return $this->isBlockedIpv4(substr($bin, 12, 4));
        }
        // IPv4-compatible ::/96 (deprecato, es. ::7f00:1 = 127.0.0.1) — IPv4 embedded negli ultimi 4 byte.
        // (::0 e ::1 sono già gestiti sopra; qui restano i ::a.b.c.d non nulli.)
        if (substr($bin, 0, 12) === str_repeat("\x00", 12)) {
            return $this->isBlockedIpv4(substr($bin, 12, 4));
        }
        return false;
    }

    /** Esegue la singola richiesta curl verso l'IP pinnato, con cap byte in streaming e Location inline. */
    private function request(array $parts, int $maxBytes): FetchResult
    {
        $buffer = '';
        $truncated = false;
        $location = null;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $parts['url'],
            // PIN: forza host:port → IP validato. curl NON ri-risolve il DNS (anti-rebinding/TOCTOU).
            CURLOPT_RESOLVE        => [$parts['host'] . ':' . $parts['port'] . ':' . $parts['ip']],
            CURLOPT_FOLLOWLOCATION => false,          // redirect gestiti a mano (ri-validazione per hop)
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
            CURLOPT_HEADER         => false,
            CURLOPT_HTTPGET        => true,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            // Nessuna credenziale: niente cookie jar, niente Authorization (rimosso), niente auto-referer.
            CURLOPT_COOKIE         => '',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml,application/json,application/rss+xml,application/atom+xml,application/xml;q=0.9,text/xml;q=0.9,image/*;q=0.8,*/*;q=0.7', 'Authorization:'],
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$location) {
                if (stripos($line, 'location:') === 0) {
                    $location = trim(substr($line, 9));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$buffer, &$truncated, $maxBytes) {
                $len = strlen($chunk);
                if (strlen($buffer) + $len > $maxBytes) {
                    $buffer .= substr($chunk, 0, max(0, $maxBytes - strlen($buffer)));
                    $truncated = true;
                    return 0; // abort: supera il cap byte
                }
                $buffer .= $chunk;
                return $len;
            },
        ]);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ctype  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        // IP REALE verso cui curl si è connesso su QUESTO hop (leggi PRIMA di curl_close).
        $primaryIp = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        curl_close($ch);

        // Un abort per cap byte (CURLE_WRITE_ERROR=23) NON è fatale: usiamo il buffer parziale.
        if ($ok === false && $errno !== 0 && $errno !== CURLE_WRITE_ERROR) {
            throw new RuntimeException('fetch_failed:' . $errno);
        }
        if ($status === 0) {
            throw new RuntimeException('no_response');
        }

        // BELT-AND-SUSPENDERS anti-SSRF (host-differential): il PIN via CURLOPT_RESOLVE è chiavato sull'host
        // di parse_url, ma CURLOPT_URL è la stringa GREZZA che libcurl ri-parsa. Se i due parser dissentono
        // sull'host, libcurl potrebbe risolvere per conto suo un host diverso e AGGIRARE il pin (raggiungendo
        // IP interni/metadata). Verifichiamo quindi che l'IP EFFETTIVO della connessione sia ESATTAMENTE
        // l'IP validato+pinnato per questo hop; se differisce (o non è validato) → blocca, niente dati.
        if ($primaryIp === ''
            || @inet_pton($primaryIp) !== @inet_pton($parts['ip'])
            || $this->isBlockedIp($primaryIp)) {
            throw new RuntimeException('ip_pin_mismatch');
        }

        return new FetchResult($status, strtolower(trim(explode(';', $ctype)[0])), $buffer, $truncated, $location);
    }

    /** Risolve una Location (assoluta o relativa) contro l'URL di base. */
    private function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $b = parse_url($base);
        if ($b === false || empty($b['scheme']) || empty($b['host'])) {
            throw new RuntimeException('malformed_url');
        }
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = isset($b['path']) ? preg_replace('#/[^/]*$#', '/', $b['path']) : '/';
        return $origin . $path . $location;
    }
}
