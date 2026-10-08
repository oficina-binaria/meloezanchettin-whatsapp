<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;

class ServiceWindowController extends Controller
{
    /**
     * Tell whether free-form text can currently be sent to the phone number.
     */
    public function show(string $phone): JsonResponse
    {
        $contact = Contact::findByPhone($phone);

        return response()->json(['data' => [
            'phone' => $contact->phone ?? $phone,
            'open' => $contact?->hasOpenServiceWindow() ?? false,
            'last_inbound_at' => $contact?->last_inbound_at?->toIso8601ZuluString(),
            'closes_at' => $contact?->serviceWindowClosesAt()?->toIso8601ZuluString(),
        ]]);
    }
}
