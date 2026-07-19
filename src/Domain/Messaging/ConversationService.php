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
     * @return ServiceResult ok con {conversation_id, messages[]} o fail 403 se non connessi.
     */
    public function thread(int $actorId, int $targetId, int $page = 1, int $perPage = self::PER_PAGE): ServiceResult
    {
        if ($targetId === $actorId) {
            return ServiceResult::fail(I18n::t('dm.error.self'), 422);
        }
        if (!$this->connections->areConnected($actorId, $targetId)) {
            return ServiceResult::fail(I18n::t('dm.error.not_connected'), 403);
        }
        $convId = $this->conversations->findOrCreate($actorId, $targetId);
        $this->messages->markRead($convId, $actorId);

        $perPage = max(1, min(100, $perPage));
        $offset = max(0, $page - 1) * $perPage;
        $rows = array_reverse($this->messages->thread($convId, $perPage, $offset));

        return ServiceResult::ok([
            'conversation_id' => $convId,
            'messages'        => array_map(static fn ($m) => MessagePresenter::item($m, $actorId), $rows),
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
