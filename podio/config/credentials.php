<?php
// Impedisce accesso diretto via URL
if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    exit("Accesso negato.");
}

return [
    "client_id" => "spoome",
    "client_secret" => "Br3WeDCY3cutu1nCdVr29ZchP1bsEJY6vC4QdnqvnOw7IZO3sY9rqov00JlYw69q",
    "username" => "m.bilancia@jetbit.it",
    "password" => "Carpa2026!"
];
