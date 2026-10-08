<?php

namespace Tests\Feature\Messages;

use App\Enums\MessageStatus;
use App\Models\Contact;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MessagesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('messages.index'))->assertRedirect(route('login'));
    }

    public function test_page_lists_messages_of_every_contact_with_the_newest_first(): void
    {
        $this->actingAs(User::factory()->create());
        $maria = Contact::factory()->create(['name' => 'Maria Souza']);
        $joao = Contact::factory()->create(['name' => 'João Lima']);
        Message::factory()->for($maria)->create(['body' => 'Primeira mensagem']);
        Message::factory()->for($joao)->inbound()->create(['body' => 'Segunda mensagem']);

        $this->get(route('messages.index'))
            ->assertOk()
            ->assertSeeInOrder(['João Lima', 'Segunda mensagem', 'Maria Souza', 'Primeira mensagem']);
    }

    public function test_message_time_is_shown_in_the_display_timezone(): void
    {
        config(['app.display_timezone' => 'America/Campo_Grande']);
        $this->actingAs(User::factory()->create());
        Message::factory()->create(['sent_at' => '2026-10-08 18:22:08']);

        $this->get(route('messages.index'))->assertSee('08/10/2026 14:22');
    }

    public function test_failed_message_shows_the_error_reported_by_meta(): void
    {
        $this->actingAs(User::factory()->create());
        Message::factory()->create([
            'status' => MessageStatus::Failed,
            'error_code' => 131005,
            'error_message' => 'Access denied',
        ]);

        $this->get(route('messages.index'))
            ->assertSee('Falhou')
            ->assertSee('Erro 131005:')
            ->assertSee('Access denied');
    }

    public function test_contact_filter_shows_only_the_messages_of_that_contact(): void
    {
        $this->actingAs(User::factory()->create());
        $maria = Contact::factory()->create();
        Message::factory()->for($maria)->create(['body' => 'Mensagem da Maria']);
        Message::factory()->create(['body' => 'Mensagem de outro contato']);

        Livewire::test('pages::messages.index')
            ->set('contactId', (string) $maria->id)
            ->assertSee('Mensagem da Maria')
            ->assertDontSee('Mensagem de outro contato');
    }

    public function test_status_filter_shows_only_sent_messages_with_that_status(): void
    {
        $this->actingAs(User::factory()->create());
        Message::factory()->create(['body' => 'Mensagem lida', 'status' => MessageStatus::Read]);
        Message::factory()->create(['body' => 'Mensagem aceita', 'status' => MessageStatus::Accepted]);
        Message::factory()->inbound()->create(['body' => 'Mensagem recebida']);

        Livewire::test('pages::messages.index')
            ->set('status', MessageStatus::Read->value)
            ->assertSee('Mensagem lida')
            ->assertDontSee('Mensagem aceita')
            ->assertDontSee('Mensagem recebida');
    }

    public function test_received_filter_shows_only_messages_from_contacts(): void
    {
        $this->actingAs(User::factory()->create());
        Message::factory()->create(['body' => 'Mensagem enviada']);
        Message::factory()->inbound()->create(['body' => 'Mensagem recebida']);

        Livewire::test('pages::messages.index')
            ->set('status', 'received')
            ->assertSee('Mensagem recebida')
            ->assertDontSee('Mensagem enviada');
    }

    public function test_only_the_25_newest_messages_are_on_the_first_page(): void
    {
        $this->actingAs(User::factory()->create());
        $contact = Contact::factory()->create();
        Message::factory()->for($contact)->create(['body' => 'Mensagem mais antiga']);
        Message::factory()->for($contact)->count(25)->create(['body' => 'Mensagem recente']);

        Livewire::test('pages::messages.index')
            ->assertDontSee('Mensagem mais antiga')
            ->call('gotoPage', 2)
            ->assertSee('Mensagem mais antiga');
    }

    public function test_changing_a_filter_returns_to_the_first_page(): void
    {
        $this->actingAs(User::factory()->create());
        $contact = Contact::factory()->create();
        Message::factory()->for($contact)->count(26)->create();

        Livewire::test('pages::messages.index')
            ->call('gotoPage', 2)
            ->set('contactId', (string) $contact->id)
            ->assertSet('paginators.page', 1);
    }
}
