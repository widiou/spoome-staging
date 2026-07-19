<?php

namespace Spoome\Domain\Messaging;

/**
 * Forma pubblica di un messaggio, relativa a chi guarda ($actorId).
 */
final class MessagePresenter
{
    public static function item(array $m, int $actorId): array
    {
        return [
            'id'         => (int) $m['id'],
            'body'       => $m['body'],
            'from_me'    => (int) $m['sender_id'] === $actorId,
            'read'       => $m['read_at'] !== null,
            'created_at' => $m['created_at'],
        ];
    }
}
