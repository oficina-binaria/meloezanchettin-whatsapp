<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Contact;
use App\Models\Message;

class NumberMessageControllerTest extends ApiTestCase
{
    public function test_lists_sent_and_received_messages_of_the_number_with_the_newest_first(): void
    {
        $contact = Contact::factory()->create(['phone' => '5567999990000']);
        $sent = Message::factory()->for($contact)->create(['body' => 'Enviada']);
        $received = Message::factory()->for($contact)->inbound()->create(['body' => 'Recebida']);
        Message::factory()->create(['body' => 'De outro número']);

        $this->getJson(route('api.v1.numbers.messages.index', '5567999990000'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $received->id)
            ->assertJsonPath('data.0.direction', 'inbound')
            ->assertJsonPath('data.1.id', $sent->id)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_number_is_found_with_or_without_the_brazilian_ninth_digit(): void
    {
        $contact = Contact::factory()->create(['phone' => '5567999990000']);
        Message::factory()->for($contact)->create();

        $this->getJson(route('api.v1.numbers.messages.index', '556799990000'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_returns_an_empty_list_for_a_number_without_messages(): void
    {
        $this->getJson(route('api.v1.numbers.messages.index', '5567999990000'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_direction_filter_returns_only_received_messages(): void
    {
        $contact = Contact::factory()->create(['phone' => '5567999990000']);
        Message::factory()->for($contact)->create();
        $received = Message::factory()->for($contact)->inbound()->create();

        $this->getJson(route('api.v1.numbers.messages.index', ['phone' => '5567999990000', 'direction' => 'inbound']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $received->id);
    }

    public function test_after_id_with_ascending_order_returns_only_newer_messages_oldest_first(): void
    {
        $contact = Contact::factory()->create(['phone' => '5567999990000']);
        [$first, $second, $third] = Message::factory()->for($contact)->count(3)->create();

        $this->getJson(route('api.v1.numbers.messages.index', ['phone' => '5567999990000', 'after_id' => $first->id, 'order' => 'asc']))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $third->id);
    }

    public function test_since_filter_leaves_out_older_messages(): void
    {
        $contact = Contact::factory()->create(['phone' => '5567999990000']);
        Message::factory()->for($contact)->create(['created_at' => '2026-10-01 10:00:00']);
        $recent = Message::factory()->for($contact)->create(['created_at' => '2026-10-08 10:00:00']);

        $this->getJson(route('api.v1.numbers.messages.index', ['phone' => '5567999990000', 'since' => '2026-10-05T00:00:00Z']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recent->id);
    }

    public function test_per_page_limits_the_page_size(): void
    {
        $contact = Contact::factory()->create(['phone' => '5567999990000']);
        Message::factory()->for($contact)->count(3)->create();

        $this->getJson(route('api.v1.numbers.messages.index', ['phone' => '5567999990000', 'per_page' => 2]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_returns_422_when_per_page_is_above_the_limit(): void
    {
        $this->getJson(route('api.v1.numbers.messages.index', ['phone' => '5567999990000', 'per_page' => 101]))
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('per_page');
    }

    public function test_returns_404_when_the_number_is_not_made_of_10_to_15_digits(): void
    {
        $this->getJson('/api/v1/numbers/abc/messages')->assertNotFound();
    }
}
