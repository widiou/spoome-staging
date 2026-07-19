<?php
/**
 * Helper globali per i template (escaping e URL). Caricati al boot dal front controller.
 */

use Spoome\Core\Config;
use Spoome\Core\Csrf;
use Spoome\Core\I18n;
use Spoome\Core\Session;

if (!function_exists('e')) {
    /** Escape HTML sicuro (contro XSS). Da usare SEMPRE per i dati dinamici nelle view. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /** URL interno prefissato col BASE_PATH (es. url('atleti') → "/beta/atleti"). */
    function url(string $path = ''): string
    {
        return rtrim(Config::basePath(), '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /** URL di un asset statico sotto public/assets, con cache-busting (?v=mtime). */
    function asset(string $path): string
    {
        $rel  = 'assets/' . ltrim($path, '/');
        $file = \dirname(__DIR__, 2) . '/public/' . $rel;
        $ver  = \is_file($file) ? '?v=' . \filemtime($file) : '';
        return url($rel) . $ver;
    }
}

if (!function_exists('t')) {
    /** Traduce una chiave i18n (lang/<locale>.php). Interpolazione con {segnaposto}. */
    function t(string $key, array $replace = []): string
    {
        return I18n::t($key, $replace);
    }
}

if (!function_exists('csrf_field')) {
    /** Campo hidden CSRF per i form. */
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('auth_id')) {
    /** ID dell'utente autenticato via sessione web, o null. Per la UI (nav). */
    function auth_id(): ?int
    {
        return Session::userId();
    }
}

if (!function_exists('is_admin')) {
    /** True se l'utente di sessione è admin (per mostrare il link discreto in nav). Cache per-richiesta. */
    function is_admin(): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $uid = Session::userId();
        if ($uid === null) {
            return $cache = false;
        }
        // Il ruolo è denormalizzato in sessione al login → nessuna query.
        $role = Session::get('role');
        if ($role !== null) {
            return $cache = ($role === 'admin');
        }
        // Fallback per sessioni preesistenti al deploy (prive di 'role').
        $user = (new \Spoome\Domain\Users\UserRepository())->findById($uid);
        return $cache = ($user !== null && $user->isAdmin());
    }
}

if (!function_exists('profile_path')) {
    /**
     * Percorso CANONICO (relativo alla base) della pagina di un profilo, tipizzato come LinkedIn:
     *  - organizzazioni → `/societa|/associazione|/federazione` (segmento = type key)
     *  - persone (atleta, fan) → `/atleti`
     * `$suffix` viene appeso dopo l'handle (es. `/follower`). NON tocca il DB: legge il tipo dalla
     * riga già arricchita (`type_key` | `cp_type_key` | `type.key`; handle da `handle` | `cp_handle`).
     * Per shape prive di tipo ricade su `/atleti`: la pagina profilo fa comunque un 301 al canonico.
     * @param array<string,mixed>|object|null $p
     */
    function profile_path(array|object|null $p, string $suffix = ''): string
    {
        // Unica sede della lista dei prefissi org: deve rispecchiare le rotte tipizzate in routes.php.
        static $orgPrefixes = ['societa', 'associazione', 'federazione'];
        if ($p === null) {
            return 'atleti';
        }
        if (\is_object($p)) {
            $handle  = (string) ($p->handle ?? '');
            $typeKey = $p->typeKey ?? null;
        } else {
            $handle  = (string) ($p['handle'] ?? $p['cp_handle'] ?? '');
            $typeKey = $p['type_key'] ?? $p['cp_type_key'] ?? ($p['type']['key'] ?? null);
        }
        $prefix = ($typeKey !== null && \in_array($typeKey, $orgPrefixes, true)) ? (string) $typeKey : 'atleti';
        return $prefix . '/' . $handle . $suffix;
    }
}

if (!function_exists('profile_url')) {
    /** URL interno (prefissato col BASE_PATH) alla pagina canonica del profilo. Vedi profile_path(). */
    function profile_url(array|object|null $p, string $suffix = ''): string
    {
        return url(profile_path($p, $suffix));
    }
}

if (!function_exists('link_icon')) {
    /** Classe icona Font Awesome per il tipo di link social/web. */
    function link_icon(string $kind): string
    {
        return [
            'website'   => 'fa-solid fa-globe',
            'instagram' => 'fa-brands fa-instagram',
            'x'         => 'fa-brands fa-x-twitter',
            'facebook'  => 'fa-brands fa-facebook',
            'linkedin'  => 'fa-brands fa-linkedin-in',
            'youtube'   => 'fa-brands fa-youtube',
            'tiktok'    => 'fa-brands fa-tiktok',
            'email'     => 'fa-solid fa-envelope',
        ][$kind] ?? 'fa-solid fa-link';
    }
}

if (!function_exists('link_kind_label')) {
    /** Etichetta leggibile del tipo di link. */
    function link_kind_label(string $kind): string
    {
        return [
            'website'   => 'Sito web',
            'instagram' => 'Instagram',
            'x'         => 'X',
            'facebook'  => 'Facebook',
            'linkedin'  => 'LinkedIn',
            'youtube'   => 'YouTube',
            'tiktok'    => 'TikTok',
            'email'     => 'Email',
            'other'     => 'Altro',
        ][$kind] ?? 'Link';
    }
}

if (!function_exists('sport_icon')) {
    /**
     * Classe icona Font Awesome per uno sport. Specchio di link_icon():
     * lookup per slug (override specifico) → fallback per categoria → default fa-solid fa-medal.
     * Ogni icona è verificata presente in FA Free 6.5.2 self-hosted.
     */
    function sport_icon(?string $slug, ?string $category = null): string
    {
        // Override per singolo sport (lo slug è l'identità stabile in DB).
        static $bySlug = [
            // Sport di squadra
            'calcio'                => 'fa-solid fa-futbol',
            'pallacanestro'         => 'fa-solid fa-basketball',
            'pallavolo'             => 'fa-solid fa-volleyball',
            'rugby'                 => 'fa-solid fa-football',
            'football-americano'    => 'fa-solid fa-football',
            'baseball'              => 'fa-solid fa-baseball',
            'softball'              => 'fa-solid fa-baseball',
            'hockey-su-ghiaccio'    => 'fa-solid fa-hockey-puck',
            'hockey-su-prato'       => 'fa-solid fa-hockey-puck',
            'pallanuoto'            => 'fa-solid fa-water',
            // Combattimento
            'scherma'               => 'fa-solid fa-khanda',
            // Atletica
            'marcia'                => 'fa-solid fa-person-walking',
            // Ciclismo
            'bmx'                   => 'fa-solid fa-bicycle',
            'mountain-bike'         => 'fa-solid fa-bicycle',
            // Acquatici
            'nuoto'                 => 'fa-solid fa-person-swimming',
            'nuoto-sincronizzato'   => 'fa-solid fa-person-swimming',
            'vela'                  => 'fa-solid fa-sailboat',
            // Invernali
            'sci-alpino'            => 'fa-solid fa-person-skiing',
            'sci-di-fondo'          => 'fa-solid fa-person-skiing-nordic',
            'biathlon'              => 'fa-solid fa-person-skiing-nordic',
            'snowboard'             => 'fa-solid fa-person-snowboarding',
            'pattinaggio-di-figura' => 'fa-solid fa-person-skating',
            'short-track'           => 'fa-solid fa-person-skating',
            // Forza
            'powerlifting'          => 'fa-solid fa-weight-hanging',
            // Ginnastica
            'trampolino-elastico'   => 'fa-solid fa-person-falling',
            // Motori
            'motociclismo'          => 'fa-solid fa-motorcycle',
            'motocross'             => 'fa-solid fa-motorcycle',
            'automobilismo'         => 'fa-solid fa-car-side',
            // Precisione
            'tiro-a-segno'          => 'fa-solid fa-crosshairs',
            'tiro-a-volo'           => 'fa-solid fa-crosshairs',
            // Altri sport
            'golf'                  => 'fa-solid fa-golf-ball-tee',
            'pattinaggio-a-rotelle' => 'fa-solid fa-person-skating',
            'arrampicata-sportiva'  => 'fa-solid fa-mountain-sun',
            'danza-sportiva'        => 'fa-solid fa-music',
        ];
        // Fallback per categoria (stringhe come in sports.category).
        static $byCategory = [
            'Sport di squadra'       => 'fa-solid fa-people-group',
            'Sport con racchetta'    => 'fa-solid fa-table-tennis-paddle-ball',
            'Sport da combattimento' => 'fa-solid fa-hand-fist',
            'Atletica'               => 'fa-solid fa-person-running',
            'Ciclismo'               => 'fa-solid fa-person-biking',
            'Sport acquatici'        => 'fa-solid fa-water',
            'Sport invernali'        => 'fa-solid fa-snowflake',
            'Forza'                  => 'fa-solid fa-dumbbell',
            'Ginnastica'             => 'fa-solid fa-child-reaching',
            'Motori'                 => 'fa-solid fa-flag-checkered',
            'Sport di precisione'    => 'fa-solid fa-bullseye',
            'Sport equestri'         => 'fa-solid fa-horse',
            'Multidisciplina'        => 'fa-solid fa-medal',
            'Altri sport'            => 'fa-solid fa-medal',
        ];

        $slug = $slug !== null ? trim($slug) : '';
        if ($slug !== '' && isset($bySlug[$slug])) {
            return $bySlug[$slug];
        }
        $category = $category !== null ? trim($category) : '';
        if ($category !== '' && isset($byCategory[$category])) {
            return $byCategory[$category];
        }
        return 'fa-solid fa-medal';
    }
}

if (!function_exists('acting_profile_id')) {
    /**
     * Id del profilo per cui l'utente sta agendo adesso (acting context) o null. Multi-profilo:
     * legge `Session acting_profile_id` e lo RI-VALIDA sempre via canActAs (il valore in sessione
     * non è mai fidato di per sé); altrimenti ricade sul profilo personale (denormalizzato al login).
     * Per un utente mono-profilo = il suo unico profilo (identico a prima). Cache per-richiesta.
     * Difensivo: gira su ogni pagina autenticata → non deve MAI lanciare (un throw = 500 globale).
     */
    function acting_profile_id(): ?int
    {
        static $cache = false;
        if ($cache !== false) {
            return $cache;
        }
        try {
            $uid = Session::userId();
            if ($uid === null) {
                return $cache = null;
            }
            $claim = Session::get('acting_profile_id');
            if ($claim !== null && (int) $claim > 0) {
                $ctx = new \Spoome\Domain\Profiles\ActingContext();
                if ($ctx->canActAs($uid, (int) $claim, 'editor')) {
                    return $cache = (int) $claim;
                }
            }
            // Default: profilo personale, denormalizzato in sessione al login.
            if (Session::has('profile_id') && Session::get('profile_id') !== null) {
                return $cache = (int) Session::get('profile_id');
            }
            $pid = (new \Spoome\Domain\Profiles\ProfileRepository())->findByUserId($uid)?->id;
            return $cache = ($pid !== null ? (int) $pid : null);
        } catch (\Throwable $e) {
            return $cache = null;
        }
    }
}

if (!function_exists('acting_switcher_data')) {
    /**
     * Dati per lo switcher "Agisci come" in nav: identità corrente + elenco (personale + pagine gestite).
     * @return array{current:?int, options:array<int,array<string,mixed>>}|null null se anonimo/errore.
     * Difensivo: mai un throw (gira su ogni pagina autenticata). Cache per-richiesta.
     */
    function acting_switcher_data(): ?array
    {
        static $cache = false;
        if ($cache !== false) {
            return $cache;
        }
        try {
            $uid = Session::userId();
            if ($uid === null) {
                return $cache = null;
            }
            $rows = (new \Spoome\Domain\Profiles\ProfileMemberRepository())->pagesFor($uid);
            if ($rows === []) {
                return $cache = null;
            }
            $ids   = array_map(static fn($r) => (int) $r['profile_id'], $rows);
            $cards = (new \Spoome\Domain\Profiles\ProfileRepository())->cardsByIds($ids);
            $current = acting_profile_id();
            $options = [];
            foreach ($ids as $pid) {
                $c = $cards[$pid] ?? null;
                if ($c === null) {
                    continue;
                }
                $options[] = [
                    'id'          => $pid,
                    'name'        => (string) $c['display_name'],
                    'handle'      => (string) $c['handle'],
                    'is_org'      => !empty($c['is_organization']),
                    'avatar_path' => $c['avatar_path'] ?? null,
                    'current'     => ($pid === $current),
                ];
            }
            // Personale (non-org) in cima, poi le pagine.
            usort($options, static fn($a, $b) => ($a['is_org'] <=> $b['is_org']));
            return $cache = ['current' => $current, 'options' => $options];
        } catch (\Throwable $e) {
            return $cache = null;
        }
    }
}

if (!function_exists('dm_unread')) {
    /** Numero di messaggi non letti dell'ACTING profile (0 se anonimo). Per il badge in nav. */
    function dm_unread(): int
    {
        $pid = acting_profile_id();
        if ($pid === null) {
            return 0;
        }
        try {
            return (new \Spoome\Domain\Messaging\MessageRepository())->unreadTotal($pid);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('notif_unread')) {
    /** Numero di notifiche non lette dell'utente autenticato (0 se anonimo). Per il badge in nav. */
    function notif_unread(): int
    {
        $uid = Session::userId();
        if ($uid === null) {
            return 0;
        }
        try {
            return (new \Spoome\Domain\Notifications\NotificationRepository())->unreadCount($uid);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('time_ago')) {
    /** Tempo relativo leggibile (es. "5 min fa", "2 h fa", "3 g fa") o data se più vecchio. */
    function time_ago(string $datetime): string
    {
        // I timestamp del DB sono in UTC (server SYSTEM=UTC, CURRENT_TIMESTAMP=UTC), ma PHP gira in
        // Europe/Rome: senza forzare UTC, strtotime li interpreterebbe come ora locale → +1/2h di sfasamento.
        $ts = \strtotime($datetime . ' UTC');
        if ($ts === false) {
            $ts = \strtotime($datetime);
        }
        if ($ts === false) {
            return '';
        }
        $diff = \time() - $ts;
        if ($diff < 0) {
            $diff = 0;
        }
        if ($diff < 60) {
            return I18n::t('time.now');
        }
        if ($diff < 3600) {
            return I18n::t('time.minutes', ['n' => (string) \intdiv($diff, 60)]);
        }
        if ($diff < 86400) {
            return I18n::t('time.hours', ['n' => (string) \intdiv($diff, 3600)]);
        }
        if ($diff < 604800) {
            return I18n::t('time.days', ['n' => (string) \intdiv($diff, 86400)]);
        }
        return \date('d/m/Y', $ts);
    }
}

if (!function_exists('initials')) {
    /** Iniziali (max 2) da un nome, per l'avatar segnaposto. Es. "Mario Rossi" → "MR". */
    function initials(string $name): string
    {
        $parts = \preg_split('/\s+/u', \trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return '?';
        }
        $first = \mb_substr($parts[0], 0, 1, 'UTF-8');
        $last  = \count($parts) > 1 ? \mb_substr($parts[\count($parts) - 1], 0, 1, 'UTF-8') : '';
        return \mb_strtoupper($first . $last, 'UTF-8');
    }
}

if (!function_exists('avatar_hue')) {
    /** Tonalità (0-359) stabile derivata da una stringa, per colorare l'avatar segnaposto. */
    function avatar_hue(string $seed): int
    {
        return \crc32($seed) % 360;
    }
}
