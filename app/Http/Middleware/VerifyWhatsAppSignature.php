<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWhatsAppSignature
{
    /**
     * Reject requests whose payload was not signed by Meta with the app secret.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appSecret = (string) config('services.whatsapp.app_secret');

        abort_if($appSecret === '', Response::HTTP_UNAUTHORIZED);

        $expectedSignature = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

        abort_unless(
            hash_equals($expectedSignature, (string) $request->header('X-Hub-Signature-256')),
            Response::HTTP_UNAUTHORIZED,
        );

        return $next($request);
    }
}
