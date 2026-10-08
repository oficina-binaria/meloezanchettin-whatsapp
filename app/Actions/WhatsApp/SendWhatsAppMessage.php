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
     * Send free-form text to the contact and record the outcome.
     *
     * Meta only delivers free-form text while the contact's service window is open.
     */
    public function sendText(Contact $contact, string $text): Message
    {
        return $this->send(
            $contact,
            new Message(['type' => 'text', 'body' => $text]),
            ['type' => 'text', 'text' => ['body' => $text]],
        );
    }

    /**
     * Send an approved template to the contact and record the outcome.
     *
     * @param  array{name: string, language: string, body: string}  $template
     * @param  list<string>  $parameters  Values for the numbered body variables, in order.
     */
    public function sendTemplate(Contact $contact, array $template, array $parameters = []): Message
    {
        $payload = ['name' => $template['name'], 'language' => ['code' => $template['language']]];

        if ($parameters !== []) {
            $payload['components'] = [[
                'type' => 'body',
                'parameters' => array_map(fn (string $value): array => ['type' => 'text', 'text' => $value], $parameters),
            ]];
        }

        return $this->send(
            $contact,
            new Message([
                'type' => 'template',
                'template_name' => $template['name'],
                'body' => self::renderTemplate($template['body'], $parameters),
            ]),
            ['type' => 'template', 'template' => $payload],
        );
    }

    /**
     * Replace the numbered variables of a template body with the given values.
     *
     * @param  list<string>  $parameters
     */
    public static function renderTemplate(string $body, array $parameters): string
    {
        return preg_replace_callback(
            '/\{\{(\d+)\}\}/',
            fn (array $matches): string => filled($parameters[(int) $matches[1] - 1] ?? null) ? $parameters[(int) $matches[1] - 1] : $matches[0],
            $body,
        );
    }

    /**
     * Call the Cloud API and store the message with the result.
     *
     * @param  array<string, mixed>  $content
     */
    private function send(Contact $contact, Message $message, array $content): Message
    {
        $message->fill([
            'contact_id' => $contact->id,
            'direction' => MessageDirection::Outbound,
            'status_at' => now(),
        ]);

        try {
            $response = Http::whatsapp()->post(
                config('services.whatsapp.phone_number_id').'/messages',
                ['messaging_product' => 'whatsapp', 'to' => $contact->phone, ...$content],
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
                'error_message' => $response->json('error.error_data.details') ?? $response->json('error.message', 'HTTP '.$response->status()),
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
}
