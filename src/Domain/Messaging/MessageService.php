<?php

namespace Spoome\Domain\Messaging;

use Spoome\Core\I18n;
use Spoome\Core\ServiceResult;
use Spoome\Support\Str;
use Spoome\Domain\Auth\RateLimiter;
use Spoome\Domain\Connections\ConnectionRepository;
use Spoome\Domain\Notifications\NotificationService;

/**
 * Invio messaggi. Vincolo di sicurezza cardine: si può scrivere SOLO a un profilo con cui si è
 * connessi (connessione accettata), ricontrollato ad ogni invio. Rate-limit anti-spam.
 */
final class MessageService
{
    private const BODY_MAX = 4000;
    private const MAX_MSGS = 120;
    private const WINDOW_MIN = 10;

    private ConversationRepository $conversations;
    private MessageRepository $messages;
    private ConnectionRepository $connections;
    private RateLimiter $limiter;
    private NotificationService $notifications;

    public function __construct(
        ?ConversationRepository $conversations = null,
        ?MessageRepository $messages = null,
        ?ConnectionRepository $connections = null,
        ?RateLimiter $limiter = null,
        ?NotificationService $notifications = null
    ) {
        $this->conversations = $conversations ?? new ConversationRepository();
        $this->messages = $messages ?? new MessageRepository();
        $this->connections = $connections ?? new ConnectionRepository();
        $this->limiter = $limiter ?? new RateLimiter();
        $this->notifications = $notifications ?? new NotificationService();
    }

    public function send(int $actorId, int $targetId, array $input, string $ip = 'unknown'): ServiceResult
    {
        if ($targetId === $actorId) {
            return ServiceResult::fail(I18n::t('dm.error.self'), 422);
        }
        $body = trim((string) ($input['body'] ?? ''));
        if ($body === '') {
            return ServiceResult::fail(I18n::t('dm.error.empty'), 422, ['body' => I18n::t('dm.error.empty')]);
        }
        if (mb_strlen($body) > self::BODY_MAX) {
            return ServiceResult::fail(I18n::t('dm.error.too_long'), 422, ['body' => I18n::t('dm.error.too_long')]);
        }
        if (!$this->connections->areConnected($actorId, $targetId)) {
            return ServiceResult::fail(I18n::t('dm.error.not_connected'), 403);
        }
        if ($this->limiter->tooManyByKey('msg:' . $actorId, self::MAX_MSGS, self::WINDOW_MIN)) {
            return ServiceResult::fail(I18n::t('dm.error.throttled'), 429);
        }

        $convId = $this->conversations->findOrCreate($actorId, $targetId);
        $id = $this->messages->create($convId, $actorId, $body);
        $this->conversations->touch($convId);
        $this->limiter->hit('msg:' . $actorId, $ip);
        $this->notifications->newMessage($actorId, $targetId);

        // Evento realtime (additivo, SOFT): notifica il canale del destinatario (l'altro partecipante),
        // risolvendo profile_id → user_id. Fire-and-forget: mai propagato, non deve rompere l'invio.
        try {
            $recipient = (new \Spoome\Domain\Profiles\ProfileRepository())->findRawById($targetId);
            $recipientUserId = (int) ($recipient['user_id'] ?? 0);
            if ($recipientUserId > 0) {
                (new \Spoome\Domain\Events\EventBus())->emit($recipientUserId, 'message.created', $actorId, [
                    'conversation_id' => $convId,
                    'message_id'      => $id,
                    'preview'         => Str::clamp($body, 140),
                ]);
            }
        } catch (\Throwable $e) {
            \Spoome\Core\Logger::error('event message.created failed', ['exception' => $e->getMessage()]);
        }

        return ServiceResult::ok(['id' => $id, 'conversation_id' => $convId], [], 201);
    }
}
