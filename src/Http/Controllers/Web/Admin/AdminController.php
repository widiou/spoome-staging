<?php

namespace Spoome\Http\Controllers\Web\Admin;

use Spoome\Core\View;
use Spoome\Domain\Claims\ClaimRepository;
use Spoome\Http\Controllers\Controller;

/**
 * Base dei controller dell'area amministrativa. Centralizza il rendering della shell `admin`:
 * inietta i dati condivisi della sidebar (il badge "rivendicazioni pendenti") così la VIEW non
 * tocca più il DB. Comportamento identico: stesso conteggio, stesso badge su ogni pagina admin.
 */
abstract class AdminController extends Controller
{
    /**
     * Rende una pagina dentro il layout `admin`, iniettando `pendingClaims` per il badge di nav
     * se il chiamante non l'ha già fornito. Sostituisce la query in-view di views/layouts/admin.php.
     *
     * @param array<string,mixed> $data
     */
    protected function renderAdmin(string $page, array $data): void
    {
        $data['pendingClaims'] ??= (new ClaimRepository())->countPending();
        View::render($page, $data, 'admin');
    }
}
