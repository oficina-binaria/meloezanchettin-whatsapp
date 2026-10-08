<?php

namespace Tests\Feature\Actions\WhatsApp;

use App\Actions\WhatsApp\SendWhatsAppMessage;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendWhatsAppMessageTest extends TestCase
{
    use RefreshDatabase;

    private const string MESSAGES_URL = 'https://graph.facebook.com/v25.0/1234567890/messages';

    private const array LINK_TEMPLATE = [
        'name' => 'rf_link',
        'language' => 'pt_BR',
        'body' => 'Olá, {{1}}! Segue o link: {{2}}',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.graph_version' => 'v25.0',
            'services.whatsapp.phone_number_id' => '1234567890',
            'services.whatsapp.access_token' => 'fake-access-token',
        ]);
    }

    public function test_sends_text_and_records_the_accepted_message(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::MESSAGES_URL => Http::response([
                'contacts' => [['input' => '5567999990000', 'wa_id' => '556799990000']],
                'messages' => [['id' => 'wamid.ACCEPTED']],
            ]),
        ]);
        $contact = Contact::factory()->create(['phone' => '5567999990000']);

        $message = app(SendWhatsAppMessage::class)->sendText($contact, 'Olá!');

        Http::assertSent(fn (Request $request): bool => $request->url() === self::MESSAGES_URL
            && $request->data() === [
                'messaging_product' => 'whatsapp',
                'to' => '5567999990000',
                'type' => 'text',
                'text' => ['body' => 'Olá!'],
            ]);
        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'contact_id' => $contact->id,
            'direction' => MessageDirection::Outbound,
            'wamid' => 'wamid.ACCEPTED',
            'type' => 'text',
            'template_name' => null,
            'body' => 'Olá!',
            'status' => MessageStatus::Accepted,
        ]);
        $this->assertSame('556799990000', $contact->refresh()->wa_id);
    }

    public function test_sends_a_template_with_its_variables_and_records_the_rendered_text(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::MESSAGES_URL => Http::response(['messages' => [['id' => 'wamid.TEMPLATE']]]),
        ]);
        $contact = Contact::factory()->create();

        $message = app(SendWhatsAppMessage::class)
            ->sendTemplate($contact, self::LINK_TEMPLATE, ['Maria', 'https://exemplo.com/rf/abc123']);

        Http::assertSent(fn (Request $request): bool => $request['type'] === 'template'
            && $request['template'] === [
                'name' => 'rf_link',
                'language' => ['code' => 'pt_BR'],
                'components' => [[
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => 'Maria'],
                        ['type' => 'text', 'text' => 'https://exemplo.com/rf/abc123'],
                    ],
                ]],
            ]);
        $this->assertSame('template', $message->type);
        $this->assertSame('rf_link', $message->template_name);
        $this->assertSame('Olá, Maria! Segue o link: https://exemplo.com/rf/abc123', $message->body);
    }

    public function test_sends_a_template_without_components_when_it_has_no_variables(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::MESSAGES_URL => Http::response(['messages' => [['id' => 'wamid.TEMPLATE']]]),
        ]);
        $contact = Contact::factory()->create();

        app(SendWhatsAppMessage::class)
            ->sendTemplate($contact, ['name' => 'hello_world', 'language' => 'en_US', 'body' => 'Hello World']);

        Http::assertSent(fn (Request $request): bool => $request['template'] === [
            'name' => 'hello_world',
            'language' => ['code' => 'en_US'],
        ]);
    }

    public function test_records_a_failed_message_with_the_api_error_when_the_api_rejects_it(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::MESSAGES_URL => Http::response(['error' => ['code' => 131030, 'message' => 'Recipient phone number not in allowed list']], 400),
        ]);
        $contact = Contact::factory()->create();

        $message = app(SendWhatsAppMessage::class)->sendText($contact, 'Olá!');

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'wamid' => null,
            'status' => MessageStatus::Failed,
            'error_code' => 131030,
            'error_message' => 'Recipient phone number not in allowed list',
        ]);
    }

    public function test_records_a_failed_message_when_the_api_is_unreachable(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::MESSAGES_URL => Http::failedConnection(),
        ]);
        $contact = Contact::factory()->create();

        $message = app(SendWhatsAppMessage::class)->sendText($contact, 'Olá!');

        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertStringContainsString('Não foi possível conectar', $message->error_message);
    }
}
