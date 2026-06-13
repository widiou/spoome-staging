<?php

class UserSessionUtils
{
    public static function checkAuthenticated()
    {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_tipo'])) {
            header("Location: /network/uac/login.php");
            exit;
        }
    }
}
