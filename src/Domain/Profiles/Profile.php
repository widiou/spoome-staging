<?php

namespace Spoome\Domain\Profiles;

/**
 * Entità Profilo: l'identità pubblica di un utente (di un certo tipo). In F1 è minimale
 * (creata alla registrazione); i campi ricchi e l'editing arrivano in F2.
 */
final class Profile
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly int $profileTypeId,
        public readonly string $handle,
        public readonly string $displayName,
        public readonly ?string $headline = null,
        public readonly ?string $bio = null,
        public readonly ?int $sportId = null,
        public readonly ?string $verifiedAt = null,
        public readonly string $visibility = 'public',
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            id:            (int) $row['id'],
            userId:        (int) $row['user_id'],
            profileTypeId: (int) $row['profile_type_id'],
            handle:        (string) $row['handle'],
            displayName:   (string) $row['display_name'],
            headline:      $row['headline'] ?? null,
            bio:           $row['bio'] ?? null,
            sportId:       isset($row['sport_id']) ? (int) $row['sport_id'] : null,
            verifiedAt:    $row['verified_at'] ?? null,
            visibility:    (string) ($row['visibility'] ?? 'public'),
        );
    }

    public function isVerified(): bool
    {
        return $this->verifiedAt !== null;
    }
}
