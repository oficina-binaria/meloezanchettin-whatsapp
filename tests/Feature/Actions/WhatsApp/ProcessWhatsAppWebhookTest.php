<?php

namespace Tests\Feature\Actions\WhatsApp;

use App\Actions\WhatsApp\ProcessWhatsAppWebhook;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Contact;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProcessWhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const string PHONE_NUMBER_ID = '1234567890';

    private const int RECEIVED_AT = 1791475717;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp.phone_number_id' => self::PHONE_NUMBER_ID]);
    }

    public function test_inbound_message_creates_the_contact_and_opens_the_service_window(): void
    {
        $this->travelTo(Date::createFromTimestamp(self::RECEIVED_AT)->addMinute());

        app(ProcessWhatsAppWebhook::class)->handle($this->inboundPayload('556799990000', 'wamid.IN1', 'Bom dia'));

        $contact = Contact::query()->sole();
        $this->assertSame('Maria Souza', $contact->name);
        $this->assertSame('556799990000', $contact->phone);
        $this->assertSame('556799990000', $contact->wa_id);
        $this->assertTrue($contact->hasOpenServiceWindow());
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'direction' => MessageDirection::Inbound,
            'wamid' => 'wamid.IN1',
            'type' => 'text',
            'body' => 'Bom dia',
            'sent_at' => Date::createFromTimestamp(self::RECEIVED_AT),
        ]);
    }

    public function test_inbound_message_matches_a_brazilian_contact_registered_with_the_ninth_digit(): void
    {
        $contact = Contact::factory()->create(['name' => 'Cliente Cadastrado', 'phone' => '5567999990000']);

        app(ProcessWhatsAppWebhook::class)->handle($this->inboundPayload('556799990000', 'wamid.IN1', 'Oi'));

        $this->assertDatabaseCount('contacts', 1);
        $contact->refresh();
        $this->assertSame('Cliente Cadastrado', $contact->name);
        $this->assertSame('5567999990000', $contact->phone);
        $this->assertSame('556799990000', $contact->wa_id);
        $this->assertNotNull($contact->last_inbound_at);
    }

    public function test_quick_reply_button_press_is_stored_with_the_button_text(): void
    {
        $payload = $this->payload(self::PHONE_NUMBER_ID, [
            'contacts' => [['profile' => ['name' => 'Maria Souza'], 'wa_id' => '556799990000']],
            'messages' => [[
                'from' => '556799990000',
                'id' => 'wamid.BUTTON',
                'timestamp' => (string) self::RECEIVED_AT,
                'type' => 'button',
                'button' => ['payload' => 'Concluído', 'text' => 'Concluído'],
            ]],
        ]);

        app(ProcessWhatsAppWebhook::class)->handle($payload);

        $this->assertDatabaseHas('messages', ['wamid' => 'wamid.BUTTON', 'type' => 'button', 'body' => 'Concluído']);
    }

    public function test_redelivered_notification_does_not_duplicate_the_message(): void
    {
        $payload = $this->inboundPayload('556799990000', 'wamid.IN1', 'Oi');

        app(ProcessWhatsAppWebhook::class)->handle($payload);
        app(ProcessWhatsAppWebhook::class)->handle($payload);

        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('contacts', 1);
    }

    public function test_notification_for_another_business_number_is_ignored(): void
    {
        $payload = $this->inboundPayload('556799990000', 'wamid.IN1', 'Oi', phoneNumberId: '9999999999');

        app(ProcessWhatsAppWebhook::class)->handle($payload);

        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    #[TestWith([MessageStatus::Accepted, 'delivered', MessageStatus::Delivered])]
    #[TestWith([MessageStatus::Read, 'delivered', MessageStatus::Read])]
    #[TestWith([MessageStatus::Failed, 'delivered', MessageStatus::Failed])]
    public function test_delivery_status_only_moves_a_message_forward(MessageStatus $current, string $received, MessageStatus $expected): void
    {
        $message = Message::factory()->create(['wamid' => 'wamid.OUT1', 'status' => $current]);

        app(ProcessWhatsAppWebhook::class)->handle($this->statusPayload('wamid.OUT1', $received));

        $this->assertSame($expected, $message->refresh()->status);
    }

    public function test_failed_status_records_the_error_reported_by_meta(): void
    {
        $message = Message::factory()->create(['wamid' => 'wamid.OUT1', 'status' => MessageStatus::Sent]);

        app(ProcessWhatsAppWebhook::class)->handle($this->statusPayload('wamid.OUT1', 'failed', [[
            'code' => 131047,
            'title' => 'Re-engagement message',
            'error_data' => ['details' => 'More than 24 hours have passed since the customer last replied.'],
        ]]));

        $message->refresh();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertSame(131047, $message->error_code);
        $this->assertSame('More than 24 hours have passed since the customer last replied.', $message->error_message);
    }

    public function test_status_for_an_unknown_message_is_ignored(): void
    {
        app(ProcessWhatsAppWebhook::class)->handle($this->statusPayload('wamid.UNKNOWN', 'delivered'));

        $this->assertDatabaseCount('messages', 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundPayload(string $from, string $wamid, string $text, string $phoneNumberId = self::PHONE_NUMBER_ID): array
    {
        return $this->payload($phoneNumberId, [
            'contacts' => [['profile' => ['name' => 'Maria Souza'], 'wa_id' => $from]],
            'messages' => [[
                'from' => $from,
                'id' => $wamid,
                'timestamp' => (string) self::RECEIVED_AT,
                'type' => 'text',
                'text' => ['body' => $text],
            ]],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     * @return array<string, mixed>
     */
    private function statusPayload(string $wamid, string $status, array $errors = []): array
    {
        return $this->payload(self::PHONE_NUMBER_ID, [
            'statuses' => [array_filter([
                'id' => $wamid,
                'status' => $status,
                'timestamp' => (string) self::RECEIVED_AT,
                'recipient_id' => '556799990000',
                'errors' => $errors,
            ])],
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function payload(string $phoneNumberId, array $value): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '4529573614035882',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '15550000000', 'phone_number_id' => $phoneNumberId],
                        ...$value,
                    ],
                ]],
            ]],
        ];
    }
}
