<?php

namespace Spoome\Controllers\Api;

use Spoome\Athletes\AthleteRepository;
use Spoome\Http\Response;

/**
 * Primo controller MVC (read-only JSON), per provare la pipeline end-to-end:
 * front controller → Router → Controller → Repository → Response.
 */
final class AthleteController
{
    /** GET /athletes/search?q=... → autocomplete nomi atleti. */
    public function search(array $params): void
    {
        $term = \trim((string) ($_GET['q'] ?? ''));
        if (\mb_strlen($term) < 2) {
            Response::json([]);
            return;
        }
        Response::json((new AthleteRepository())->searchByName($term, 10));
    }

    /** GET /athletes/{id} → scheda atleta (campi base) in JSON. */
    public function show(array $params): void
    {
        $athlete = (new AthleteRepository())->findById((int) ($params['id'] ?? 0));
        if ($athlete === null) {
            Response::json(['error' => 'Atleta non trovato'], 404);
            return;
        }
        Response::json([
            'id'          => $athlete->getId(),
            'title'       => $athlete->title,
            'sport'       => $athlete->sport,
            'nationality' => $athlete->nationality,
            'photo'       => $athlete->photo,
        ]);
    }
}
