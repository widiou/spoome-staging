<?php

namespace Spoome\Domain\Events;

use PDO;
use Spoome\Core\Db;
use Spoome\Core\Logger;

/**
 * Dispatcher sottile degli eventi realtime (Phase 1). Persiste un evento nell'inbox durevole
 * `user_events` — l'unica fonte del cursore che il client interroga via GET /stream/since.
 *
 * INVARIANTE DI SICUREZZA: un evento è scritto per ESATTAMENTE un destinatario (`$recipientUserId`);
 * mai una riga condivisa/globale che possa trapelare tra utenti. L'audience è già stata autorizzata
 * dal Service chiamante (owner del post, partecipante della conversazione, destinatario notifica).
 *
 * SOFT / FIRE-AND-FORGET: l'INSERT è avvolto in try/catch. Un fallimento realtime viene loggato ma
 * MAI propagato: non deve rompere né rallentare l'azione sottostante (invio DM, notifica, follow...).
 */
final class EventBus
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /**
     * Scrive un evento nell'inbox del singolo utente destinatario.
     *
     * @param int         $recipientUserId destinatario (identità canale). <=0 → no-op.
     * @param string      $type            es. 'message.created', 'notification.created'
     * @param int|null    $actorProfileId  profilo attore (dato pubblico), opzionale
     * @param array       $payload         id di riferimento + preview troncata — NESSUNA PII completa
     */
    public function emit(int $recipientUserId, string $type, ?int $actorProfileId, array $payload = []): void
    {
        if ($recipientUserId <= 0 || $type === '') {
            return;
        }
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO user_events (user_id, type, actor_profile_id, payload) VALUES (:u, :t, :a, :p)'
            );
            $stmt->execute([
                'u' => $recipientUserId,
                't' => mb_substr($type, 0, 40),
                'a' => $actorProfileId,
                'p' => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable $e) {
            // Fallimento realtime: swallow + log. Mai propagato (non deve rompere l'azione sottostante).
            Logger::error('EventBus emit failed', ['type' => $type, 'exception' => $e->getMessage()]);
        }
    }
}
