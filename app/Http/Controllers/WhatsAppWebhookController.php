<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    /**
     * Answer Meta's subscription verification request with the challenge.
     */
    public function verify(Request $request): Response
    {
        $verifyToken = (string) config('services.whatsapp.webhook_verify_token');

        abort_unless(
            $verifyToken !== ''
                && $request->query('hub_mode') === 'subscribe'
                && hash_equals($verifyToken, (string) $request->query('hub_verify_token')),
            Response::HTTP_FORBIDDEN,
        );

        return response((string) $request->query('hub_challenge'))
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Receive a webhook notification sent by the WhatsApp Cloud API.
     */
    public function store(Request $request): Response
    {
        Log::info('WhatsApp webhook received.', ['payload' => $request->json()->all()]);

        return response()->noContent(Response::HTTP_OK);
    }
}
