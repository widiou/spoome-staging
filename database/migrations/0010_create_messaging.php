<?php
/**
 * Messaggistica diretta 1:1. Una `conversations` per coppia (ordinata: profile_a_id < profile_b_id).
 * I `messages` appartengono a una conversazione; `read_at` traccia la lettura del destinatario.
 * L'autorizzazione (solo tra profili connessi) è imposta a livello applicativo. Idempotente.
 */
return new class {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS conversations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                profile_a_id INT NOT NULL,
                profile_b_id INT NOT NULL,
                last_message_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_conv_a FOREIGN KEY (profile_a_id) REFERENCES profiles(id) ON DELETE CASCADE,
                CONSTRAINT fk_conv_b FOREIGN KEY (profile_b_id) REFERENCES profiles(id) ON DELETE CASCADE,
                UNIQUE KEY uniq_conv_pair (profile_a_id, profile_b_id),
                INDEX idx_conv_a (profile_a_id, last_message_at),
                INDEX idx_conv_b (profile_b_id, last_message_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                conversation_id INT NOT NULL,
                sender_id INT NOT NULL,
                body VARCHAR(4000) NOT NULL,
                read_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
                CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id) REFERENCES profiles(id) ON DELETE CASCADE,
                INDEX idx_msg_conv (conversation_id, id),
                INDEX idx_msg_unread (conversation_id, read_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS messages');
        $pdo->exec('DROP TABLE IF EXISTS conversations');
    }
};
