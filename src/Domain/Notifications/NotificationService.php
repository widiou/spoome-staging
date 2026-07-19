<?php

namespace Spoome\Domain\Notifications;

use Spoome\Core\I18n;
use Spoome\Domain\Profiles\ProfileRepository;

/**
 * Emette notifiche in-app per gli eventi del network (follow, connessioni, DM), riusando
 * NotificationRepository (col contatore denormalizzato). Risolve attore e destinatario per
 * profile_id; se il destinatario è un profilo NON rivendicato (senza owner) la notifica è saltata.
 */
final class NotificationService
{
    private NotificationRepository $notifs;
    private ProfileRepository $profiles;

    public function __construct(?NotificationRepository $notifs = null, ?ProfileRepository $profiles = null)
    {
        $this->notifs   = $notifs ?? new NotificationRepository();
        $this->profiles = $profiles ?? new ProfileRepository();
    }

    /** Nuovo follower: notifica il proprietario del profilo seguito. */
    public function follow(int $actorPid, int $targetPid): void
    {
        $this->emit(
            $targetPid,
            $actorPid,
            'follow',
            'notif.follow.title',
            'notif.follow.body',
            static fn (array $actor): string => 'atleti/' . $actor['handle']
        );
    }

    /** Richiesta di connessione: notifica il proprietario del profilo destinatario. */
    public function connectionRequest(int $actorPid, int $targetPid): void
    {
        $this->emit(
            $targetPid,
            $actorPid,
            'connection_request',
            'notif.connection_request.title',
            'notif.connection_request.body',
            static fn (array $actor): string => 'rete'
        );
    }

    /** Connessione accettata: chi accetta è $actorPid; si notifica il richiedente originale ($requesterPid). */
    public function connectionAccepted(int $accepterPid, int $requesterPid): void
    {
        $this->emit(
            $requesterPid,
            $accepterPid,
            'connection_accepted',
            'notif.connection_accepted.title',
            'notif.connection_accepted.body',
            static fn (array $actor): string => 'atleti/' . $actor['handle']
        );
    }

    /** Nuovo messaggio: notifica il proprietario del profilo destinatario. */
    public function newMessage(int $senderPid, int $recipientPid): void
    {
        $this->emit(
            $recipientPid,
            $senderPid,
            'new_message',
            'notif.new_message.title',
            'notif.new_message.body',
            static fn (array $actor): string => 'messaggi/' . $actor['handle']
        );
    }

    /** Like a un post: notifica il proprietario del post. Deduplicata (stesso attore→owner) entro 6h anti-spam. */
    public function postLike(int $actorPid, int $ownerPid): void
    {
        $this->emit(
            $ownerPid,
            $actorPid,
            'post_like',
            'notif.post_like.title',
            'notif.post_like.body',
            static fn (array $actor): string => 'feed',
            6
        );
    }

    /** Commento a un post: notifica il proprietario del post. */
    public function postComment(int $actorPid, int $ownerPid): void
    {
        $this->emit(
            $ownerPid,
            $actorPid,
            'post_comment',
            'notif.post_comment.title',
            'notif.post_comment.body',
            static fn (array $actor): string => 'feed'
        );
    }

    /**
     * Endorsement di una competenza: notifica il proprietario del profilo.
     * Deduplicata per attore/24h (il body contiene il nome dell'attore ma NON la label:
     * endorsi multipli dallo stesso attore entro 24h collassano in una sola notifica, anti-spam).
     */
    public function skillEndorsed(int $actorPid, int $ownerPid, string $skillLabel): void
    {
        $this->emit(
            $ownerPid,
            $actorPid,
            'skill_endorsed',
            'notif.skill_endorsed.title',
            'notif.skill_endorsed.body',
            static fn (array $actor): string => 'atleti/' . $actor['handle'],
            24
        );
    }

    /**
     * Richiesta di affiliazione (roster / militanza): notifica il lato che deve CONFERMARE.
     * $actorPid = chi ha proposto (atleta o pagina org); $confirmerPid = chi deve confermare.
     * URL alla pagina dell'attore, così il destinatario può rivederlo prima di confermare.
     */
    public function affiliationRequested(int $actorPid, int $confirmerPid): void
    {
        $this->emit(
            $confirmerPid,
            $actorPid,
            'affiliation_requested',
            'notif.affiliation_requested.title',
            'notif.affiliation_requested.body',
            static fn (array $actor): string => 'atleti/' . $actor['handle']
        );
    }

    /** Affiliazione confermata: $actorPid = chi ha confermato; si notifica il richiedente originale ($requesterPid). */
    public function affiliationConfirmed(int $actorPid, int $requesterPid): void
    {
        $this->emit(
            $requesterPid,
            $actorPid,
            'affiliation_confirmed',
            'notif.affiliation_confirmed.title',
            'notif.affiliation_confirmed.body',
            static fn (array $actor): string => 'atleti/' . $actor['handle']
        );
    }

    /**
     * Raccomandazione ricevuta: $actorPid = l'AUTORE che scrive; $recipientPid = il destinatario che
     * deve approvarla. URL alla pagina dell'autore, così il destinatario la rivede prima di accettarla.
     */
    public function recommendationReceived(int $actorPid, int $recipientPid): void
    {
        $this->emit(
            $recipientPid,
            $actorPid,
            'recommendation_received',
            'notif.recommendation_received.title',
            'notif.recommendation_received.body',
            static fn (array $actor): string => 'atleti/' . $actor['handle']
        );
    }

    /** Raccomandazione accettata: $actorPid = il destinatario che ha accettato; si notifica l'autore ($authorPid). */
    public function recommendationAccepted(int $actorPid, int $authorPid): void
    {
        $this->emit(
            $authorPid,
            $actorPid,
            'recommendation_accepted',
            'notif.recommendation_accepted.title',
            'notif.recommendation_accepted.body',
            static fn (array $actor): string => 'atleti/' . $actor['handle']
        );
    }

    /**
     * @param callable(array):string $urlFn costruisce l'URL a partire dalla riga dell'attore
     * @param int|null $dedupHours se valorizzato, salta se esiste già una notifica identica in quella finestra (anti-spam)
     */
    private function emit(int $recipientPid, int $actorPid, string $type, string $titleKey, string $bodyKey, callable $urlFn, ?int $dedupHours = null): void
    {
        $recipient = $this->profiles->findRawById($recipientPid);
        if ($recipient === null || empty($recipient['user_id'])) {
            return; // profilo senza owner (non rivendicato) → nessun destinatario
        }
        $actor = $this->profiles->findRawById($actorPid);
        if ($actor === null) {
            return;
        }
        $recipientUserId = (int) $recipient['user_id'];
        $body = I18n::t($bodyKey, ['name' => (string) $actor['display_name']]);

        if ($dedupHours !== null && $this->notifs->existsRecentSame($recipientUserId, $type, $body, $dedupHours)) {
            return; // già notificato di recente lo stesso evento dallo stesso attore
        }

        $this->notifs->create($recipientUserId, $type, I18n::t($titleKey), $body, $urlFn($actor));
    }
}
