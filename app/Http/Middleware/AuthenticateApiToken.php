<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    /**
     * Allow the request only when it carries an active API token as a bearer token.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = filled($request->bearerToken()) ? ApiToken::findActive($request->bearerToken()) : null;

        if ($token === null) {
            return response()->json(
                ['message' => 'Token de acesso ausente, inválido ou revogado.', 'error' => 'unauthenticated'],
                Response::HTTP_UNAUTHORIZED,
                ['WWW-Authenticate' => 'Bearer'],
            );
        }

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('apiToken', $token);

        return $next($request);
    }
}
