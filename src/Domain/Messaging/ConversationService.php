<?php

namespace Spoome\Domain\Messaging;

use Spoome\Core\I18n;
use Spoome\Core\ServiceResult;
use Spoome\Domain\Connections\ConnectionRepository;
use Spoome\Domain\Profiles\ProfilePresenter;
use Spoome\Domain\Profiles\ProfileRepository;

/**
 * Lettura conversazioni: thread (con marcatura come letti) e inbox. Stesso vincolo del MessageService:
 * si può aprire/leggere una conversazione solo con un profilo connesso.
 */
final class ConversationService
{
    public const PER_PAGE = 40;

    private ConversationRepository $conversations;
    private MessageRepository $messages;
    private ConnectionRepository $connections;
    private ProfileRepository $profiles;

    public function __construct(
        ?ConversationRepository $conversations = null,
        ?MessageRepository $messages = null,
        ?ConnectionRepository $connections = null,
        ?ProfileRepository $profiles = null
    ) {
        $this->conversations = $conversations ?? new ConversationRepository();
        $this->messages = $messages ?? new MessageRepository();
        $this->connections = $connections ?? new ConnectionRepository();
        $this->profiles = $profiles ?? new ProfileRepository();
    }

    /**
     * Thread con un profilo (crea la conversazione se serve), marca letti i messaggi ricevuti.
     *
     * Paginazione seek/keyset: passa $before = id del messaggio più vecchio già visto per caricare quelli
     * ancora più vecchi (scroll all'indietro), senza OFFSET (costo O(pagina), non O(offset)). Senza cursore
     * la prima pagina resta identica a prima. Il vecchio parametro $page (paginazione a numero, offset) è
     * mantenuto solo per retro-compatibilità dei client API legacy (?pagina=N) quando NON si passa $before.
     * @return ServiceResult ok con {conversation_id, messages[], has_more, next_cursor} o fail 403 se non connessi.
     */
    public function thread(int $actorId, int $targetId, int $page = 1, int $perPage = self::PER_PAGE, ?int $before = null): ServiceResult
    {
        if ($targetId === $actorId) {
            return ServiceResult::fail(I18n::t('dm.error.self'), 422);
        }
        if (!$this->connections->areConnected($actorId, $targetId)) {
            return ServiceResult::fail(I18n::t('dm.error.not_connected'), 403);
        }
        $convId = $this->conversations->findOrCreate($actorId, $targetId);
        // markRead solo sulla prima pagina (before === null): lo scroll all'indietro carica storia già
        // letta → ri-eseguirlo sarebbe un UPDATE inutile (0 righe utili dopo la prima volta).
        if ($before === null) {
            $this->messages->markRead($convId, $actorId);
        }

        $perPage = max(1, min(100, $perPage));
        // +1 riga sentinella per sapere se c'è una pagina più vecchia senza un COUNT.
        if ($before !== null || $page <= 1) {
            $rows = $this->messages->threadBefore($convId, $before, $perPage + 1);
        } else {
            // Fallback legacy: paginazione a numero di pagina via OFFSET (nessun cursore fornito, page > 1).
            $offset = ($page - 1) * $perPage;
            $rows = $this->messages->thread($convId, $perPage + 1, $offset);
        }

        $hasMore = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);
        // Le righe sono id DESC (più recenti prima): il cursore per la pagina più vecchia è l'id minimo = ultimo.
        $nextCursor = ($hasMore && $rows !== []) ? (int) $rows[count($rows) - 1]['id'] : null;
        $rows = array_reverse($rows); // cronologico crescente per la vista

        return ServiceResult::ok([
            'conversation_id' => $convId,
            'messages'        => array_map(static fn ($m) => MessagePresenter::item($m, $actorId), $rows),
            'has_more'        => $hasMore,
            'next_cursor'     => $nextCursor,
        ]);
    }

    /**
     * Nuovi messaggi (id > $afterId) per il polling del thread. Stesso vincolo: solo tra connessi.
     * Marca letti i ricevuti (il thread è aperto).
     * @return ServiceResult ok con {messages[]} o fail 403/422.
     */
    public function newMessages(int $actorId, int $targetId, int $afterId): ServiceResult
    {
        if ($targetId === $actorId) {
            return ServiceResult::fail(I18n::t('dm.error.self'), 422);
        }
        if (!$this->connections->areConnected($actorId, $targetId)) {
            return ServiceResult::fail(I18n::t('dm.error.not_connected'), 403);
        }
        $convId = $this->conversations->findOrCreate($actorId, $targetId);
        $rows = $this->messages->after($convId, max(0, $afterId));
        if ($rows) {
            $this->messages->markRead($convId, $actorId);
        }
        return ServiceResult::ok([
            'messages' => array_map(static fn ($m) => MessagePresenter::item($m, $actorId), $rows),
        ]);
    }

    /** Inbox: conversazioni con altro partecipante, ultimo messaggio e non letti. */
    public function inbox(int $actorId): array
    {
        $rows = $this->conversations->inbox($actorId);
        $otherIds = array_map(static fn ($r) => (int) $r['other_id'], $rows);
        $cards = $this->profiles->cardsByIds($otherIds);
        $unread = $this->messages->unreadByConversation($actorId);

        $items = [];
        foreach ($rows as $r) {
            $convId = (int) $r['id'];
            $other = $cards[(int) $r['other_id']] ?? null;
            if ($other === null) {
                continue;
            }
            $last = $this->messages->lastMessage($convId);
            $items[] = [
                'conversation_id' => $convId,
                'other'           => ProfilePresenter::card($other),
                'last'            => $last === null ? null : [
                    'body'       => $last['body'],
                    'created_at' => $last['created_at'],
                    'from_me'    => (int) $last['sender_id'] === $actorId,
                ],
                'unread'          => (int) ($unread[$convId] ?? 0),
            ];
        }
        return $items;
    }
}
