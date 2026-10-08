<?php

namespace App\Actions\WhatsApp;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Contact;
use App\Models\Message;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class SendWhatsAppMessage
{
    /**
     * The pre-approved template sent when free-form text is not allowed.
     */
    public const string FALLBACK_TEMPLATE = 'hello_world';

    /**
     * Send a message to the contact and record the outcome.
     *
     * Free-form text is sent when given; otherwise the fallback template is sent.
     */
    public function handle(Contact $contact, ?string $text = null): Message
    {
        $message = new Message([
            'contact_id' => $contact->id,
            'direction' => MessageDirection::Outbound,
            'type' => $text === null ? 'template' : 'text',
            'body' => $text ?? self::FALLBACK_TEMPLATE,
            'status_at' => now(),
        ]);

        try {
            $response = Http::whatsapp()->post(
                config('services.whatsapp.phone_number_id').'/messages',
                $this->payload($contact, $text),
            );
        } catch (ConnectionException $exception) {
            $message->fill([
                'status' => MessageStatus::Failed,
                'error_message' => 'Não foi possível conectar à API do WhatsApp: '.$exception->getMessage(),
            ])->save();

            return $message;
        }

        if ($response->failed()) {
            $message->fill([
                'status' => MessageStatus::Failed,
                'error_code' => $response->json('error.code'),
                'error_message' => $response->json('error.message', 'HTTP '.$response->status()),
            ])->save();

            return $message;
        }

        $message->fill([
            'wamid' => $response->json('messages.0.id'),
            'status' => MessageStatus::Accepted,
            'sent_at' => now(),
        ])->save();

        if (filled($waId = $response->json('contacts.0.wa_id')) && $contact->wa_id !== $waId) {
            $contact->update(['wa_id' => $waId]);
        }

        return $message;
    }

    /**
     * Build the Cloud API payload for the message.
     *
     * @return array{messaging_product: string, to: string, type: string, text?: array{body: string}, template?: array{name: string, language: array{code: string}}}
     */
    private function payload(Contact $contact, ?string $text): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $contact->phone,
        ];

        if ($text !== null) {
            return [...$payload, 'type' => 'text', 'text' => ['body' => $text]];
        }

        return [...$payload, 'type' => 'template', 'template' => [
            'name' => self::FALLBACK_TEMPLATE,
            'language' => ['code' => 'en_US'],
        ]];
    }
}
