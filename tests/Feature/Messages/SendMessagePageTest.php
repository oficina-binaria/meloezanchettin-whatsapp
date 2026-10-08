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

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.graph_version' => 'v25.0',
            'services.whatsapp.phone_number_id' => '1234567890',
            'services.whatsapp.access_token' => 'fake-access-token',
        ]);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('messages.send'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_open_the_page(): void
    {
        $this->actingAs(User::factory()->create());
        Contact::factory()->create(['name' => 'Maria Souza']);

        $this->get(route('messages.send'))
            ->assertOk()
            ->assertSee('Maria Souza');
    }

    public function test_text_is_sent_and_the_message_id_is_shown_while_the_service_window_is_open(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::MESSAGES_URL => Http::response(['messages' => [['id' => 'wamid.ACCEPTED']]]),
        ]);
        $this->actingAs(User::factory()->create());
        $contact = Contact::factory()->withOpenServiceWindow()->create();

        Livewire::test('pages::messages.send')
            ->set('contactId', $contact->id)
            ->assertSee('Janela de 24 horas aberta')
            ->set('body', 'Olá, tudo bem?')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSee('Mensagem aceita pela Meta')
            ->assertSee('wamid.ACCEPTED')
            ->assertSet('body', '');

        Http::assertSent(fn (Request $request): bool => $request['type'] === 'text'
            && $request['text'] === ['body' => 'Olá, tudo bem?']);
    }

    public function test_message_is_not_sent_without_text_while_the_service_window_is_open(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->actingAs(User::factory()->create());
        $contact = Contact::factory()->withOpenServiceWindow()->create();

        Livewire::test('pages::messages.send')
            ->set('contactId', $contact->id)
            ->call('send')
            ->assertHasErrors(['body' => 'required'])
            ->assertSee('Escreva a mensagem.');

        Http::assertNothingSent();
    }

    public function test_template_is_sent_instead_of_text_while_the_service_window_is_closed(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::MESSAGES_URL => Http::response(['messages' => [['id' => 'wamid.TEMPLATE']]]),
        ]);
        $this->actingAs(User::factory()->create());
        $contact = Contact::factory()->withClosedServiceWindow()->create();

        Livewire::test('pages::messages.send')
            ->set('contactId', $contact->id)
            ->assertSee('Janela de 24 horas fechada')
            ->set('body', 'Texto que não pode ser enviado')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSee('wamid.TEMPLATE');

        Http::assertSent(fn (Request $request): bool => $request['type'] === 'template');
        Http::assertNotSent(fn (Request $request): bool => $request['type'] === 'text');
    }

    public function test_error_returned_by_meta_is_shown_and_the_text_is_kept(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::MESSAGES_URL => Http::response(['error' => ['code' => 131030, 'message' => 'Recipient phone number not in allowed list']], 400),
        ]);
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
        Http::fake();
        $this->actingAs(User::factory()->create());
        Contact::factory()->create();

        Livewire::test('pages::messages.send')
            ->call('send')
            ->assertHasErrors('contactId')
            ->assertSee('Escolha um destinatário.');

        Http::assertNothingSent();
    }

    public function test_history_shows_the_messages_exchanged_with_the_selected_contact_only(): void
    {
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
}
