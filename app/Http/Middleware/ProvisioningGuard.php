<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protège l'API de provisioning par un jeton statique (env PROVISION_TOKEN).
 * Sans jeton configuré, la fonctionnalité est invisible (404).
 */
class ProvisioningGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('services.provisioning.token');

        if ($token === '') {
            abort(404);
        }

        if (! hash_equals($token, (string) $request->bearerToken())) {
            abort(401, 'Invalid provisioning token.');
        }

        return $next($request);
    }
}
