<?php

/**
 * Aggiunge `users.session_epoch`: contatore monotòno che invalida le sessioni WEB emesse PRIMA
 * di un cambio password (reset via token o, in futuro, cambio volontario). Al cambio password si
 * incrementa nella STESSA UPDATE della password; CurrentUser confronta l'epoch salvato in sessione
 * al login con questo valore → una sessione con epoch più vecchio è "stale" e viene sloggata.
 *
 * Idempotente. Default 0: nessun impatto sulle sessioni esistenti al deploy (fail-safe — vedi
 * CurrentUser::sessionEpochIsCurrent, che tratta 0 >= 0 come sessione ancora valida).
 *
 * PREREQUISITO DI RILASCIO: applicare QUESTA migrazione PRIMA (o insieme) al deploy del codice.
 * Il read-path (CurrentUser) è fail-safe a colonna assente, ma il WRITE-path
 * UserRepository::updatePassword scrive `session_epoch + 1`: se il codice va live prima della
 * colonna, il primo reset password fallisce con "Unknown column" (500). Mai codice-prima-di-migrazione.
 */
return new class () {
    public function up(\PDO $pdo): void
    {
        $exists = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
               AND COLUMN_NAME = 'session_epoch'"
        )->fetchColumn();
        if ((int) $exists === 0) {
            $pdo->exec(
                'ALTER TABLE users
                 ADD COLUMN session_epoch INT UNSIGNED NOT NULL DEFAULT 0 AFTER status'
            );
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE users DROP COLUMN session_epoch');
    }
};
