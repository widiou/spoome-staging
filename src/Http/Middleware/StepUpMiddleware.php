<?php

namespace Spoome\Http\Middleware;

use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\Session;

/**
 * Re-autenticazione "step-up" per l'area admin: anche con sessione admin valida, per entrare
 * (e restare) nell'area serve aver reinserito la password di recente. Riduce la finestra di
 * abuso di una sessione rubata/lasciata aperta su un'area ad alto privilegio.
 * Va montato DOPO AdminMiddleware. Le rotte di verifica stessa NON usano questo middleware.
 */
final class StepUpMiddleware
{
    /** Validità della verifica step-up, in secondi (30 minuti). */
    private const TTL = 1800;

    public function handle(Request $request): bool
    {
        $at = (int) Session::get('admin_stepup_at', 0);
        if ($at > 0 && (time() - $at) < self::TTL) {
            return true;
        }

        // Memorizza la destinazione per tornarci dopo la verifica, poi porta al form password.
        Session::set('admin_stepup_intended', $request->path);
        Response::redirect('admin/verifica');
        return false;
    }
}
