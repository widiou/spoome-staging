<?php

class User
{
    private $pdo;

    public function __construct()
    {
        // Usa la connessione PDO da bootstrap.php
        global $pdo;
        $this->pdo = $pdo;
    }

    /* ==========================================================================
       LOGIN & AUTENTICAZIONE
    ========================================================================== */

    public function login($email, $password)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            return (object) $user;
        }

        return false;
    }

    public function createUser($email, $name, $nickname, $password, $tipo, $privacy)
    {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $token = bin2hex(random_bytes(32));

        $stmt = $this->pdo->prepare("
        INSERT INTO users (email, name, nickname, password, tipo, role, active, verification_token, privacy)
        VALUES (?, ?, ?, ?, ?, 'user', 0, ?, ?)
    ");

        return $stmt->execute([$email, $name, $nickname, $hashedPassword, $tipo, $token, $privacy]);
    }


    public function getUserByEmail($email)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /* ==========================================================================
       VERIFICA REGISTRAZIONE EMAIL
    ========================================================================== */

    // In User.php
    public function getUserByToken($token)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE verification_token = ?");
        $stmt->execute([$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }




    public function activateUser($id) {
        $sql = "UPDATE users SET active = 1 WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$id]);
    }

    public function clearVerificationToken($id) {
        $sql = "UPDATE users SET verification_token = NULL WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$id]);
    }



    /* ==========================================================================
       RESET PASSWORD
    ========================================================================== */

    public function setPasswordResetToken($userId, $token)
    {
        $stmt = $this->pdo->prepare("UPDATE users SET password_reset_token = ? WHERE id = ?");
        $stmt->execute([$token, $userId]);
    }

    public function getUserByResetToken($token)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE password_reset_token = ?");
        $stmt->execute([$token]);
        return $stmt->fetch();
    }

    public function updatePassword($userId, $hashedPassword)
    {
        $stmt = $this->pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashedPassword, $userId]);
    }

    public function clearPasswordResetToken($userId)
    {
        $stmt = $this->pdo->prepare("UPDATE users SET password_reset_token = NULL WHERE id = ?");
        $stmt->execute([$userId]);
    }

    /* ==========================================================================
       COMPLETAMENTO PROFILO
    ========================================================================== */

    public function hasCompletedProfile($userId, $tipo)
    {
        $mapping = [
            'societa' => 'societa',
            'atleta' => 'atleti',
            'professionista' => 'professionisti',
            'agenzia' => 'agenzie',
            'fan' => 'fan'
        ];

        $table = $mapping[$tipo] ?? null;

        if (!$table) {
            return false;
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM $table WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() > 0;
    }

    public function getUserByNickname($nickname)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE nickname = ?");
        $stmt->execute([$nickname]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function isValidNickname($nickname): bool
    {
        // Solo lettere, numeri, underscore, minimo 3 max 30 caratteri
        if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $nickname)) {
            return false;
        }

        // Blacklist di parole vietate (aggiungine a piacere)
        $blacklist = ['admin', 'spoome', 'root', 'support', 'test'];

        return !in_array(strtolower($nickname), $blacklist);
    }

    public function getUserById($id)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function isValidPassword($password): bool
    {
        return preg_match('/^(?=.*[A-Z])(?=.*[!@#$%^&*])[A-Za-z\d!@#$%^&*]{8,}$/', $password);
    }




}
