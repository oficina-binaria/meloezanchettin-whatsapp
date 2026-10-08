<?php

namespace App\Actions\WhatsApp;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Contact;
use App\Models\Message;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;

class ProcessWhatsAppWebhook
{
    /**
     * Store the inbound messages and delivery statuses carried by a webhook notification.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        foreach (Arr::wrap($payload['entry'] ?? []) as $entry) {
            foreach (Arr::wrap($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? null) !== 'messages' || ! $this->isForConfiguredNumber($change['value'] ?? [])) {
                    continue;
                }

                $profileNames = Arr::pluck($change['value']['contacts'] ?? [], 'profile.name', 'wa_id');

                foreach ($change['value']['messages'] ?? [] as $message) {
                    $this->storeInboundMessage($message, $profileNames[$message['from'] ?? ''] ?? null);
                }

                foreach ($change['value']['statuses'] ?? [] as $status) {
                    $this->applyStatus($status);
                }
            }
        }
    }

    /**
     * Determine whether the notification targets the business number this app operates.
     *
     * @param  array<string, mixed>  $value
     */
    private function isForConfiguredNumber(array $value): bool
    {
        $configured = config('services.whatsapp.phone_number_id');

        return blank($configured) || ($value['metadata']['phone_number_id'] ?? null) === $configured;
    }

    /**
     * Store a message sent by a WhatsApp user, creating the contact when it is new.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeInboundMessage(array $data, ?string $profileName): void
    {
        if (blank($data['from'] ?? null) || blank($data['id'] ?? null)) {
            return;
        }

        $sentAt = Date::createFromTimestamp((int) ($data['timestamp'] ?? now()->timestamp));

        $contact = Contact::query()->forWhatsAppId($data['from'])->first()
            ?? Contact::create(['name' => $profileName ?? $data['from'], 'phone' => $data['from']]);

        $contact->update([
            'wa_id' => $data['from'],
            'last_inbound_at' => $contact->last_inbound_at?->greaterThan($sentAt) ? $contact->last_inbound_at : $sentAt,
        ]);

        Message::firstOrCreate(['wamid' => $data['id']], [
            'contact_id' => $contact->id,
            'direction' => MessageDirection::Inbound,
            'type' => $data['type'] ?? 'unknown',
            'body' => $data['text']['body'] ?? null,
            'sent_at' => $sentAt,
        ]);
    }

    /**
     * Apply a delivery status to the outbound message it refers to.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyStatus(array $data): void
    {
        $status = MessageStatus::tryFrom($data['status'] ?? '');
        $message = Message::query()->where('wamid', $data['id'] ?? null)->first();

        if ($status === null || $message === null || ! $status->canReplace($message->status)) {
            return;
        }

        $message->update([
            'status' => $status,
            'status_at' => Date::createFromTimestamp((int) ($data['timestamp'] ?? now()->timestamp)),
            'error_code' => $data['errors'][0]['code'] ?? null,
            'error_message' => $data['errors'][0]['error_data']['details']
                ?? $data['errors'][0]['message']
                ?? $data['errors'][0]['title']
                ?? null,
        ]);
    }
}
