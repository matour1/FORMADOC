<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réserve l'accès à l'espace d'administration.
 *
 * **404 et non 403.** Le choix est délibéré et hérite de la règle du projet pour
 * les documents : un 403 confirmerait l'EXISTENCE de l'espace admin à un
 * utilisateur qui n'a rien à y faire. Un 404 ne révèle rien — la page est
 * simplement inexistante pour lui.
 *
 * **Un invité est redirigé, pas rejeté.** Sans session, la réponse utile est
 * « connectez-vous » : le renvoyer vers un 404 serait déroutant pour quelqu'un qui
 * suit un lien légitime.
 *
 * Ce middleware ne remplace pas le `auth` : il est appliqué APRÈS, dans le groupe
 * de routes, précisément pour que la distinction invité / non-admin soit possible.
 */
class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = Auth::user();

        if ($utilisateur === null) {
            return redirect()->guest(route('login'));
        }

        if (! $utilisateur->is_admin) {
            abort(404, 'Page introuvable.');
        }

        return $next($request);
    }
}
