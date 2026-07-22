<?php

namespace Spoome\Domain\Maintenance;

use PDO;
use Spoome\Core\Config;
use Spoome\Core\Db;
use Spoome\Core\Logger;
use Spoome\Core\Mailer;
use Spoome\Domain\Auth\EmailVerificationService;
use Spoome\Domain\Auth\PasswordResetService;
use Spoome\Domain\Auth\RateLimiter;
use Spoome\Domain\Auth\TokenService;
use Spoome\Domain\Events\EventRepository;
use Spoome\Domain\Feed\ActivityRepository;
use Spoome\Domain\Links\LinkPreviewRepository;
use Spoome\Domain\Notifications\NotificationRepository;
use Spoome\Support\Str;

/**
 * Manutenzione schedulabile (cron SiteGround via jobs/maintenance.php):
 *  1) PURGE — pulisce le tabelle che altrimenti crescono illimitate (GDPR + costo storage/indice):
 *     tentativi di login vecchi, token scaduti/revocati/usati, log applicativi, eventi e attività di
 *     feed oltre retention, cache anteprime link scadute. Ogni DELETE è parametrizzato e cancella a BATCH (LIMIT in loop)
 *     per non tenere lock lunghi.
 *  2) RECONCILE — riallinea i contatori denormalizzati alla source-of-truth (COUNT). Il mantenimento
 *     incrementale è già atomico e live (drift atteso = 0); questa è difesa in profondità, NON distruttiva:
 *     se un percorso sfuggisse, il drift si auto-ripara. rowCount() (senza CLIENT_FOUND_ROWS) riporta
 *     SOLO le righe effettivamente cambiate = drift corretto.
 *
 * Idempotente e sicuro a ri-esecuzione: rieseguirlo non fa danni (le condizioni sono sempre le stesse).
 */
final class MaintenanceService
{
    /** Finestre di retention (default; sovrascrivibili dal chiamante). */
    public const LOGIN_ATTEMPTS_HOURS = 720;  // ~30 giorni
    public const APP_LOGS_DAYS        = 90;
    public const USER_EVENTS_DAYS     = 30;
    public const NOTIFICATIONS_DAYS   = 90;   // solo notifiche già lette
    public const ACTIVITIES_DAYS      = 365;  // attività di feed (contenuto visibile: finestra ampia)

    /** Soglia di default per l'alert spike errori/24h (sovrascrivibile via ALERT_ERROR_THRESHOLD_24H). */
    public const DEFAULT_ERROR_THRESHOLD_24H = 50;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /**
     * Esegue purge + reconcile + rilevazione spike errori (con invio digest se presenti)
     * e ritorna il riepilogo per lo stdout del job.
     * @return array{purged:array<string,int>, reconciled:array<string,int>, alerts:array<int,array{fingerprint:string,count:int,exception_class:?string,sample:string,last:string}>}
     */
    public function run(int $batch = 5000): array
    {
        $alerts = $this->detectErrorSpikes();
        if ($alerts !== []) {
            $this->sendErrorSpikeAlert($alerts);
        }

        return [
            'purged'     => $this->purge($batch),
            'reconciled' => $this->reconcileCounters(),
            'alerts'     => $alerts,
        ];
    }

    /**
     * Rileva i fingerprint di app_logs che nelle ultime 24h hanno superato la soglia di errori.
     * Idempotente e SENZA effetti collaterali (nessuna scrittura): solo lettura, così è testabile
     * in isolamento e riusabile fuori dal job di manutenzione (es. una futura dashboard admin).
     *
     * Anti-spam: il job gira 1×/giorno (cron 03:17) → un digest al giorno dei fingerprint
     * ATTUALMENTE sopra soglia è il comportamento accettato (nessun lock/dedup più complesso:
     * se un fingerprint resta sopra soglia per più giorni consecutivi, l'admin riceve un digest
     * ogni giorno finché non rientra — è il segnale voluto, non uno spam da sopprimere).
     *
     * @param int|null $threshold soglia iniettabile per i test; se null usa Config (ALERT_ERROR_THRESHOLD_24H).
     * @return array<int,array{fingerprint:string,count:int,exception_class:?string,sample:string,last:string}>
     */
    public function detectErrorSpikes(?int $threshold = null): array
    {
        if ($threshold === null) {
            $threshold = (int) Config::get('ALERT_ERROR_THRESHOLD_24H', self::DEFAULT_ERROR_THRESHOLD_24H);
        }
        // Clamp difensivo: una soglia <= 0 farebbe scattare l'alert su QUALSIASI errore
        // (rumore/spam — lezione dal #5). Ricadiamo sempre sul default sensato.
        if ($threshold <= 0) {
            $threshold = self::DEFAULT_ERROR_THRESHOLD_24H;
        }

        // level = 'error': è l'UNICO livello di gravità che il Logger persiste in app_logs oltre a
        // 'warning' (vedi Logger::DB_LEVELS). NON esiste 'critical' → non va messo in query (sarebbe
        // codice morto). 'warning' (che include gli eventi di Logger::security, es. spike di login
        // falliti) è ESCLUSO di proposito: l'alert è scoped agli errori applicativi. Un alert dedicato
        // agli spike di warning/sicurezza è un follow-up di prodotto separato, non implicito qui.
        // LIMIT 100: cap difensivo alla dimensione del digest email (un attaccante che generasse molti
        // fingerprint distinti sopra soglia non può gonfiare l'email oltre 100 righe).
        $stmt = $this->pdo->prepare(
            "SELECT fingerprint, COUNT(*) c, MAX(message) sample, MAX(exception_class) klass, MAX(created_at) last
             FROM app_logs
             WHERE level = 'error' AND created_at > NOW() - INTERVAL 24 HOUR
             GROUP BY fingerprint
             HAVING c > :threshold
             ORDER BY c DESC
             LIMIT 100"
        );
        $stmt->bindValue(':threshold', $threshold, PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'fingerprint'      => (string) $row['fingerprint'],
                'count'            => (int) $row['c'],
                'exception_class'  => $row['klass'] !== null ? (string) $row['klass'] : null,
                'sample'           => (string) $row['sample'],
                'last'             => (string) $row['last'],
            ];
        }
        return $out;
    }

    /**
     * Invia UN digest via Mailer con l'elenco dei fingerprint sopra soglia. Canale coerente con
     * il resto dell'app (Mailer::send): in staging logga soltanto (storage/logs/mail.log), in
     * produzione invia via mail(). Sicurezza livello MASSIMO anche in un'email: ogni campo che
     * viene da app_logs (message/exception_class) è dato potenzialmente ostile → sempre e().
     *
     * @param array<int,array{fingerprint:string,count:int,exception_class:?string,sample:string,last:string}> $alerts
     */
    private function sendErrorSpikeAlert(array $alerts): void
    {
        $to = (string) Config::get(
            'ADMIN_ALERT_EMAIL',
            Config::get('MAIL_FROM_ADDRESS', 'no-reply@spoome.it')
        );
        $n = count($alerts);
        $subject = sprintf('[Spoome] %d fingerprint sopra soglia errori/24h', $n);

        $rows = '';
        foreach ($alerts as $a) {
            $sample = Str::clamp($a['sample'], 200);
            $rows .= sprintf(
                '<tr><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                e($a['fingerprint']),
                $a['count'],
                e($a['exception_class'] ?? '—'),
                e($sample),
                e($a['last'])
            );
        }

        $body = '<h2>' . e($subject) . '</h2>'
            . '<p>Fingerprint con oltre soglia errori (level=error) nelle ultime 24 ore (job di manutenzione notturno).</p>'
            . '<table border="1" cellpadding="6" cellspacing="0">'
            . '<thead><tr><th>fingerprint</th><th>count</th><th>exception_class</th><th>sample message</th><th>ultimo timestamp</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>';

        Mailer::send($to, $subject, $body);
    }

    /**
     * Pulizia delle tabelle a crescita illimitata. Ogni voce è la delega al metodo di dominio
     * competente (single source of truth della query), così la logica resta testabile e riusabile.
     * @return array<string,int> tabella => righe eliminate
     */
    public function purge(int $batch = 5000): array
    {
        return [
            'login_attempts'      => (new RateLimiter($this->pdo))->purgeOlderThan(self::LOGIN_ATTEMPTS_HOURS, $batch),
            'auth_tokens'         => (new TokenService($this->pdo))->purgeExpired($batch),
            'email_verifications' => (new EmailVerificationService($this->pdo))->purgeStale($batch),
            'password_resets'     => (new PasswordResetService($this->pdo))->purgeStale($batch),
            'app_logs'            => Logger::purge(self::APP_LOGS_DAYS, $batch),
            'user_events'         => (new EventRepository($this->pdo))->purgeOlderThan(self::USER_EVENTS_DAYS, $batch),
            'activities'          => (new ActivityRepository($this->pdo))->purgeOlderThan(self::ACTIVITIES_DAYS, $batch),
            'link_previews'       => (new LinkPreviewRepository($this->pdo))->purgeExpired($batch),
            'notifications'       => (new NotificationRepository($this->pdo))->purgeRead(self::NOTIFICATIONS_DAYS, $batch),
        ];
    }

    /**
     * Riallinea i contatori denormalizzati dalla source-of-truth. Non distruttivo: aggiorna solo
     * i valori che divergono. Le query rispecchiano esattamente il backfill della migrazione 0014
     * (+ likes/comments dei post, mig. 0015). rowCount() = righe drift-ate corrette.
     * @return array<string,int> contatore => righe riallineate
     */
    public function reconcileCounters(): array
    {
        $out = [];

        $out['profiles.followers_count'] = $this->exec(
            'UPDATE profiles p SET p.followers_count =
                (SELECT COUNT(*) FROM follows f WHERE f.followee_id = p.id)'
        );
        $out['profiles.following_count'] = $this->exec(
            'UPDATE profiles p SET p.following_count =
                (SELECT COUNT(*) FROM follows f WHERE f.follower_id = p.id)'
        );
        $out['profiles.connections_count'] = $this->exec(
            "UPDATE profiles p SET p.connections_count =
                (SELECT COUNT(*) FROM connections c WHERE c.status='accepted'
                 AND (c.requester_id = p.id OR c.addressee_id = p.id))"
        );
        $out['profiles.unread_messages'] = $this->exec(
            'UPDATE profiles p SET p.unread_messages =
                (SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id
                 WHERE (c.profile_a_id = p.id OR c.profile_b_id = p.id)
                   AND m.sender_id <> p.id AND m.read_at IS NULL)'
        );
        $out['users.unread_notifications'] = $this->exec(
            'UPDATE users u SET u.unread_notifications =
                (SELECT COUNT(*) FROM notifications n WHERE n.user_id = u.id AND n.read_at IS NULL)'
        );
        $out['posts.likes_count'] = $this->exec(
            'UPDATE posts p SET p.likes_count =
                (SELECT COUNT(*) FROM post_likes l WHERE l.post_id = p.id)'
        );
        $out['posts.comments_count'] = $this->exec(
            'UPDATE posts p SET p.comments_count =
                (SELECT COUNT(*) FROM post_comments c WHERE c.post_id = p.id)'
        );

        return $out;
    }

    /** Esegue una UPDATE statica (nessun input esterno) e ritorna le righe cambiate. */
    private function exec(string $sql): int
    {
        return (int) $this->pdo->exec($sql);
    }
}
