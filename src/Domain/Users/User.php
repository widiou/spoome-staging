<?php

namespace Spoome\Domain\Users;

/**
 * Entità utente (solo autenticazione). Nessuna logica di dominio "social" qui: quella sta nei Profili.
 */
final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $passwordHash,
        public readonly string $role,      // member | moderator | admin
        public readonly string $status,    // pending | active | suspended
        public readonly ?string $emailVerifiedAt = null,
        // Generazione di sessione: incrementata ad ogni cambio password. Una sessione web con
        // epoch più vecchio è invalidata (vedi CurrentUser). Default 0: fail-safe se la colonna
        // manca (pre-migrazione 0032) → fromRow() legge 0 e nessuna sessione viene sloggata.
        public readonly int $sessionEpoch = 0,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            id:              (int) $row['id'],
            email:           (string) $row['email'],
            passwordHash:    (string) $row['password_hash'],
            role:            (string) $row['role'],
            status:          (string) $row['status'],
            emailVerifiedAt: $row['email_verified_at'] ?? null,
            sessionEpoch:    (int) ($row['session_epoch'] ?? 0),
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
