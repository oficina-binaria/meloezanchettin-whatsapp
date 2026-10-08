<?php

namespace Tests\Feature\Messages;

use App\Enums\MessageStatus;
use App\Models\Contact;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SendMessagePageTest extends TestCase
{
    use RefreshDatabase;

    private const string MESSAGES_URL = 'https://graph.facebook.com/v25.0/1234567890/messages';

    private const string TEMPLATES_URL = 'https://graph.facebook.com/v25.0/9876543210/message_templates*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.graph_version' => 'v25.0',
            'services.whatsapp.phone_number_id' => '1234567890',
            'services.whatsapp.business_account_id' => '9876543210',
            'services.whatsapp.access_token' => 'fake-access-token',
        ]);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('messages.send'))->assertRedirect(route('login'));
    }

    public function test_page_lists_the_contacts_and_the_approved_templates_before_a_recipient_is_chosen(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses());
        $this->actingAs(User::factory()->create());
        Contact::factory()->create(['name' => 'Maria Souza']);

        $this->get(route('messages.send'))
            ->assertOk()
            ->assertSee('Maria Souza')
            ->assertSee('Template rf_link (pt_BR)')
            ->assertDontSee('Texto livre');
    }

    public function test_template_chosen_before_the_recipient_is_kept_and_greets_the_contact(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses());
        $this->actingAs(User::factory()->create());
        $contact = Contact::factory()->withOpenServiceWindow()->create(['name' => 'Maria Souza']);

        Livewire::test('pages::messages.send')
            ->set('kind', 'rf_link|pt_BR')
            ->set('contactId', $contact->id)
            ->assertSet('kind', 'rf_link|pt_BR')
            ->assertSet('parameters.1', 'Maria');
    }

    public function test_text_is_sent_and_the_message_id_is_shown_while_the_service_window_is_open(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses(['messages' => [['id' => 'wamid.ACCEPTED']]]));
        $this->actingAs(User::factory()->create());
        $contact = Contact::factory()->withOpenServiceWindow()->create();

        Livewire::test('pages::messages.send')
            ->set('contactId', $contact->id)
            ->assertSee('Janela de 24 horas aberta')
            ->assertSet('kind', 'text')
            ->set('body', 'Olá, tudo bem?')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSee('Mensagem aceita pela Meta')
            ->assertSee('wamid.ACCEPTED')
            ->assertSet('body', '');

        Http::assertSent(fn (Request $request): bool => $request->url() === self::MESSAGES_URL
            && $request['type'] === 'text'
            && $request['text'] === ['body' => 'Olá, tudo bem?']);
    }

    public function test_message_is_not_sent_without_text_while_the_service_window_is_open(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses());
        $this->actingAs(User::factory()->create());
        $contact = Contact::factory()->withOpenServiceWindow()->create();

        Livewire::test('pages::messages.send')
            ->set('contactId', $contact->id)
            ->call('send')
            ->assertHasErrors(['body' => 'required'])
            ->assertSee('Escreva a mensagem.');

        $this->assertNoMessageWasSent();
    }

    public function test_free_text_is_refused_while_the_service_window_is_closed(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses());
        $this->actingAs(User::factory()->create());
        $contact = Contact::factory()->withClosedServiceWindow()->create();

        Livewire::test('pages::messages.send')
            ->set('contactId', $contact->id)
            ->assertSee('Janela de 24 horas fechada')
            ->assertDontSee('Texto livre</option>', false)
            ->set('kind', 'text')
            ->set('body', 'Texto que não pode ser enviado')
            ->call('send')
            ->assertHasErrors('kind')
            ->assertSee('A janela de 24 horas está fechada. Escolha um template.');

        $this->assertNoMessageWasSent();
    }

    public function test_template_is_sent_with_the_typed_variables_while_the_service_window_is_closed(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses(['messages' => [['id' => 'wamid.TEMPLATE']]]));
        $this->actingAs(User::factory()->create());
        $contact = Contact::factory()->withClosedServiceWindow()->create(['name' => 'Maria Souza']);

        Livewire::test('pages::messages.send')
            ->set('contactId', $contact->id)
            ->set('kind', 'rf_link|pt_BR')
            ->assertSet('parameters.1', 'Maria')
            ->set('parameters.2', 'https://exemplo.com/rf/abc123')
            ->assertSee('Olá, Maria! Segue o link: https://exemplo.com/rf/abc123')
            ->assertSee('Concluído')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSee('wamid.TEMPLATE');

        Http::assertSent(fn (Request $request): bool => $request->url() === self::MESSAGES_URL
            && $request['template']['name'] === 'rf_link'
            && $request['template']['language'] === ['code' => 'pt_BR']
            && $request['template']['components'][0]['parameters'] === [
                ['type' => 'text', 'text' => 'Maria'],
                ['type' => 'text', 'text' => 'https://exemplo.com/rf/abc123'],
            ]);
    }

    public function test_template_is_not_sent_while_a_variable_is_empty(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses());
        $this->actingAs(User::factory()->create());
        $contact = Contact::factory()->create();

        Livewire::test('pages::messages.send')
            ->set('contactId', $contact->id)
            ->set('kind', 'rf_link|pt_BR')
            ->call('send')
            ->assertHasErrors(['parameters.2' => 'required'])
            ->assertSee('Preencha esta variável.');

        $this->assertNoMessageWasSent();
    }

    public function test_template_is_not_sent_when_a_variable_has_a_line_break(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses());
        $this->actingAs(User::factory()->create());
        $contact = Contact::factory()->create();

        Livewire::test('pages::messages.send')
            ->set('contactId', $contact->id)
            ->set('kind', 'rf_link|pt_BR')
            ->set('parameters.2', "https://exemplo.com\nsegunda linha")
            ->call('send')
            ->assertHasErrors(['parameters.2' => 'not_regex']);

        $this->assertNoMessageWasSent();
    }

    public function test_nothing_is_sent_when_no_message_type_is_chosen(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses());
        $this->actingAs(User::factory()->create());
        $contact = Contact::factory()->create();

        Livewire::test('pages::messages.send')
            ->set('contactId', $contact->id)
            ->call('send')
            ->assertHasErrors('kind')
            ->assertSee('Escolha o que enviar.');

        $this->assertNoMessageWasSent();
    }

    public function test_error_returned_by_meta_is_shown_and_the_text_is_kept(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses(['error' => ['code' => 131030, 'message' => 'Recipient phone number not in allowed list']], 400));
        $this->actingAs(User::factory()->create());
        $contact = Contact::factory()->withOpenServiceWindow()->create();

        Livewire::test('pages::messages.send')
            ->set('contactId', $contact->id)
            ->set('body', 'Olá!')
            ->call('send')
            ->assertSee('A mensagem não foi enviada')
            ->assertSee('131030')
            ->assertSee('Recipient phone number not in allowed list')
            ->assertSet('body', 'Olá!');
    }

    public function test_message_is_not_sent_without_a_recipient(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses());
        $this->actingAs(User::factory()->create());
        Contact::factory()->create();

        Livewire::test('pages::messages.send')
            ->call('send')
            ->assertHasErrors('contactId')
            ->assertSee('Escolha um destinatário.');

        $this->assertNoMessageWasSent();
    }

    public function test_warning_is_shown_when_the_templates_cannot_be_loaded(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TEMPLATES_URL => Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token.']], 401),
        ]);
        $this->actingAs(User::factory()->create());
        $contact = Contact::factory()->create();

        Livewire::test('pages::messages.send')
            ->set('contactId', $contact->id)
            ->assertSee('Não foi possível carregar os templates da Meta agora.');
    }

    public function test_history_shows_the_messages_exchanged_with_the_selected_contact_only(): void
    {
        Http::preventStrayRequests();
        Http::fake($this->metaResponses());
        $this->actingAs(User::factory()->create());
        $contact = Contact::factory()->create();
        Message::factory()->for($contact)->inbound()->create(['body' => 'Mensagem do cliente']);
        Message::factory()->for($contact)->create(['body' => 'Resposta da empresa', 'status' => MessageStatus::Read]);
        Message::factory()->create(['body' => 'Conversa de outro contato']);

        Livewire::test('pages::messages.send')
            ->set('contactId', $contact->id)
            ->assertSee('Mensagem do cliente')
            ->assertSee('Resposta da empresa')
            ->assertSee('Lida')
            ->assertDontSee('Conversa de outro contato');
    }

    /**
     * Fake Meta's template list and, when given, its answer to a send request.
     *
     * @param  array<string, mixed>|null  $sendResponse
     * @return array<string, mixed>
     */
    private function metaResponses(?array $sendResponse = null, int $sendStatus = 200): array
    {
        $responses = [
            self::TEMPLATES_URL => Http::response(['data' => [[
                'name' => 'rf_link',
                'status' => 'APPROVED',
                'language' => 'pt_BR',
                'components' => [
                    ['type' => 'BODY', 'text' => 'Olá, {{1}}! Segue o link: {{2}}'],
                    ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Concluído']]],
                ],
            ]]]),
        ];

        if ($sendResponse !== null) {
            $responses[self::MESSAGES_URL] = Http::response($sendResponse, $sendStatus);
        }

        return $responses;
    }

    private function assertNoMessageWasSent(): void
    {
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::MESSAGES_URL);
        $this->assertDatabaseCount('messages', 0);
    }
}
