<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\MessageStatus;
use App\Models\Contact;
use App\Models\Message;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class MessageControllerTest extends ApiTestCase
{
    private const array TEMPLATE_PAYLOAD = [
        'to' => '+55 (67) 99999-0000',
        'name' => 'Maria Souza',
        'type' => 'template',
        'template' => [
            'name' => 'rf_link',
            'language' => 'pt_BR',
            'parameters' => ['Maria', 'https://exemplo.com/rf/abc123'],
        ],
    ];

    public function test_template_is_sent_to_a_new_number_and_returns_201_with_the_message(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses(['messages' => [['id' => 'wamid.TEMPLATE']]]));

        $response = $this->postJson(route('api.v1.messages.store'), self::TEMPLATE_PAYLOAD);

        $response->assertCreated()
            ->assertJsonPath('data.wamid', 'wamid.TEMPLATE')
            ->assertJsonPath('data.direction', 'outbound')
            ->assertJsonPath('data.phone', '5567999990000')
            ->assertJsonPath('data.type', 'template')
            ->assertJsonPath('data.template', 'rf_link')
            ->assertJsonPath('data.body', 'Olá, Maria! Segue o link: https://exemplo.com/rf/abc123')
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.error', null);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::MESSAGES_URL
            && $request['to'] === '5567999990000'
            && $request['template']['components'][0]['parameters'] === [
                ['type' => 'text', 'text' => 'Maria'],
                ['type' => 'text', 'text' => 'https://exemplo.com/rf/abc123'],
            ]);
        $this->assertDatabaseHas('contacts', ['name' => 'Maria Souza', 'phone' => '5567999990000']);
        $this->assertDatabaseHas('messages', ['wamid' => 'wamid.TEMPLATE', 'api_token_id' => $this->apiToken->id]);
    }

    public function test_text_is_sent_while_the_service_window_is_open(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses(['messages' => [['id' => 'wamid.TEXT']]]));
        Contact::factory()->withOpenServiceWindow()->create(['phone' => '5567999990000']);

        $this->postJson(route('api.v1.messages.store'), ['to' => '5567999990000', 'type' => 'text', 'text' => 'Olá!'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'text')
            ->assertJsonPath('data.body', 'Olá!');

        Http::assertSent(fn (Request $request): bool => $request->url() === self::MESSAGES_URL
            && $request['text'] === ['body' => 'Olá!']);
    }

    public function test_text_returns_409_and_is_not_sent_while_the_service_window_is_closed(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses());
        Contact::factory()->withClosedServiceWindow()->create(['phone' => '5567999990000']);

        $this->postJson(route('api.v1.messages.store'), ['to' => '5567999990000', 'type' => 'text', 'text' => 'Olá!'])
            ->assertConflict()
            ->assertJsonPath('error', 'service_window_closed');

        Http::assertNothingSent();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_text_returns_409_for_a_number_that_never_wrote(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses());

        $this->postJson(route('api.v1.messages.store'), ['to' => '5567999990000', 'type' => 'text', 'text' => 'Olá!'])
            ->assertConflict();

        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_returns_422_when_the_template_is_not_approved_for_the_account(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses());

        $this->postJson(route('api.v1.messages.store'), [
            ...self::TEMPLATE_PAYLOAD,
            'template' => ['name' => 'inexistente', 'language' => 'pt_BR'],
        ])->assertUnprocessable()->assertJsonValidationErrorFor('template.name');

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_returns_422_when_the_number_of_template_parameters_is_wrong(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses());

        $this->postJson(route('api.v1.messages.store'), [
            ...self::TEMPLATE_PAYLOAD,
            'template' => ['name' => 'rf_link', 'language' => 'pt_BR', 'parameters' => ['Maria']],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'template.parameters' => 'Este template exige 2 variável(is) e foram enviadas 1.',
        ]);

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_returns_422_when_required_fields_are_missing(): void
    {
        $this->postJson(route('api.v1.messages.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to', 'type']);
    }

    public function test_returns_422_when_the_number_is_too_short(): void
    {
        $this->postJson(route('api.v1.messages.store'), ['to' => '99999', 'type' => 'text', 'text' => 'Olá!'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to' => 'Informe o número com DDI e DDD, entre 10 e 15 dígitos.']);
    }

    public function test_returns_422_with_the_meta_error_when_meta_rejects_the_message(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses(['error' => ['code' => 131030, 'message' => 'Recipient phone number not in allowed list']], 400));

        $this->postJson(route('api.v1.messages.store'), self::TEMPLATE_PAYLOAD)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'meta_rejected')
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error.code', 131030)
            ->assertJsonPath('data.error.message', 'Recipient phone number not in allowed list');

        $this->assertDatabaseHas('messages', ['status' => MessageStatus::Failed, 'api_token_id' => $this->apiToken->id]);
    }

    public function test_returns_502_when_the_whatsapp_api_cannot_be_reached(): void
    {
        Http::preventStrayRequests();
        Http::fake([...$this->metaResponses(), self::MESSAGES_URL => Http::failedConnection()]);

        $this->postJson(route('api.v1.messages.store'), self::TEMPLATE_PAYLOAD)
            ->assertStatus(502)
            ->assertJsonPath('error', 'meta_unavailable');
    }

    public function test_repeated_request_with_the_same_idempotency_key_returns_the_first_message_without_sending_again(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses(['messages' => [['id' => 'wamid.TEMPLATE']]]));

        $first = $this->postJson(route('api.v1.messages.store'), self::TEMPLATE_PAYLOAD, ['Idempotency-Key' => 'pedido-123']);
        $second = $this->postJson(route('api.v1.messages.store'), self::TEMPLATE_PAYLOAD, ['Idempotency-Key' => 'pedido-123']);

        $first->assertCreated();
        $second->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        Http::assertSentCount(2);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_failed_message_can_be_retried_with_the_same_idempotency_key(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses(['error' => ['code' => 131030, 'message' => 'Recipient phone number not in allowed list']], 400));

        $this->postJson(route('api.v1.messages.store'), self::TEMPLATE_PAYLOAD, ['Idempotency-Key' => 'pedido-123']);
        $this->postJson(route('api.v1.messages.store'), self::TEMPLATE_PAYLOAD, ['Idempotency-Key' => 'pedido-123'])
            ->assertUnprocessable();

        $this->assertDatabaseCount('messages', 2);
    }

    public function test_shows_a_message_with_its_current_status(): void
    {
        $message = Message::factory()->create(['wamid' => 'wamid.SHOW', 'status' => MessageStatus::Delivered]);

        $this->getJson(route('api.v1.messages.show', $message))
            ->assertOk()
            ->assertJsonPath('data.id', $message->id)
            ->assertJsonPath('data.wamid', 'wamid.SHOW')
            ->assertJsonPath('data.status', 'delivered');
    }

    public function test_returns_404_for_a_message_that_does_not_exist(): void
    {
        $this->getJson(route('api.v1.messages.show', 999))->assertNotFound();
    }
}
