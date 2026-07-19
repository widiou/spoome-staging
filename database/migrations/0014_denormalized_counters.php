<?php
/**
 * Contatori denormalizzati per eliminare i COUNT(*) live dai percorsi caldi
 * (badge di nav su OGNI pagina, contatori nella hero profilo, statistiche).
 * Mantenuti in modo incrementale dai Service alle mutazioni; backfill iniziale qui.
 * GREATEST(0, …) nei decrementi previene valori negativi in caso di race.
 */
return new class {
    public function up(\PDO $pdo): void
    {
        foreach ([
            'ALTER TABLE profiles ADD COLUMN followers_count INT NOT NULL DEFAULT 0',
            'ALTER TABLE profiles ADD COLUMN following_count INT NOT NULL DEFAULT 0',
            'ALTER TABLE profiles ADD COLUMN connections_count INT NOT NULL DEFAULT 0',
            'ALTER TABLE profiles ADD COLUMN unread_messages INT NOT NULL DEFAULT 0',
            'ALTER TABLE users ADD COLUMN unread_notifications INT NOT NULL DEFAULT 0',
        ] as $sql) {
            try { $pdo->exec($sql); } catch (\PDOException $e) { /* colonna già presente */ }
        }
        $this->backfill($pdo);
    }

    public function backfill(\PDO $pdo): void
    {
        $pdo->exec('UPDATE profiles p SET followers_count =
            (SELECT COUNT(*) FROM follows f WHERE f.followee_id = p.id)');
        $pdo->exec('UPDATE profiles p SET following_count =
            (SELECT COUNT(*) FROM follows f WHERE f.follower_id = p.id)');
        $pdo->exec("UPDATE profiles p SET connections_count =
            (SELECT COUNT(*) FROM connections c WHERE c.status='accepted'
             AND (c.requester_id = p.id OR c.addressee_id = p.id))");
        $pdo->exec("UPDATE profiles p SET unread_messages =
            (SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id
             WHERE (c.profile_a_id = p.id OR c.profile_b_id = p.id)
               AND m.sender_id <> p.id AND m.read_at IS NULL)");
        $pdo->exec('UPDATE users u SET unread_notifications =
            (SELECT COUNT(*) FROM notifications n WHERE n.user_id = u.id AND n.read_at IS NULL)');
    }

    public function down(\PDO $pdo): void
    {
        foreach ([
            'ALTER TABLE profiles DROP COLUMN followers_count',
            'ALTER TABLE profiles DROP COLUMN following_count',
            'ALTER TABLE profiles DROP COLUMN connections_count',
            'ALTER TABLE profiles DROP COLUMN unread_messages',
            'ALTER TABLE users DROP COLUMN unread_notifications',
        ] as $sql) {
            try { $pdo->exec($sql); } catch (\PDOException $e) {}
        }
    }
};
