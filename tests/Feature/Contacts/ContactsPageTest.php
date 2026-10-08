<?php

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContactsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('contacts.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_see_the_registered_contacts(): void
    {
        $this->actingAs(User::factory()->create());
        Contact::factory()->create(['name' => 'Maria Souza']);

        $this->get(route('contacts.index'))
            ->assertOk()
            ->assertSee('Maria Souza');
    }

    public function test_contact_is_created_with_the_phone_reduced_to_digits(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::contacts.index')
            ->set('name', 'Maria Souza')
            ->set('phone', '+55 (67) 99999-0000')
            ->call('createContact')
            ->assertHasNoErrors()
            ->assertSet('name', '')
            ->assertSet('phone', '');

        $this->assertDatabaseHas('contacts', ['name' => 'Maria Souza', 'phone' => '5567999990000']);
    }

    public function test_contact_is_not_created_when_the_phone_is_too_short(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::contacts.index')
            ->set('name', 'Maria Souza')
            ->set('phone', '99999')
            ->call('createContact')
            ->assertHasErrors(['phone' => 'digits_between'])
            ->assertSee('Informe o número com DDI e DDD, entre 10 e 15 dígitos.');

        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_contact_is_not_created_when_the_phone_is_already_registered(): void
    {
        $this->actingAs(User::factory()->create());
        Contact::factory()->create(['phone' => '5567999990000']);

        Livewire::test('pages::contacts.index')
            ->set('name', 'Outra Pessoa')
            ->set('phone', '5567999990000')
            ->call('createContact')
            ->assertHasErrors(['phone' => 'unique'])
            ->assertSee('Já existe um contato com este número.');

        $this->assertDatabaseCount('contacts', 1);
    }

    public function test_contact_is_not_created_without_a_name(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::contacts.index')
            ->set('phone', '5567999990000')
            ->call('createContact')
            ->assertHasErrors(['name' => 'required']);

        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_deleting_a_contact_removes_it_and_its_messages(): void
    {
        $this->actingAs(User::factory()->create());
        $contact = Contact::factory()->create();
        Message::factory()->for($contact)->create();

        Livewire::test('pages::contacts.index')
            ->call('deleteContact', $contact->id);

        $this->assertModelMissing($contact);
        $this->assertDatabaseCount('messages', 0);
    }
}
