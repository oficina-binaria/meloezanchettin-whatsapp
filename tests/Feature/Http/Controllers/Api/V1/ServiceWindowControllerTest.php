<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Contact;

class ServiceWindowControllerTest extends ApiTestCase
{
    public function test_window_is_open_with_its_closing_time_after_a_recent_inbound_message(): void
    {
        $this->travelTo('2026-10-08 12:00:00');
        Contact::factory()->create(['phone' => '5567999990000', 'last_inbound_at' => '2026-10-08 10:00:00']);

        $this->getJson(route('api.v1.numbers.window.show', '5567999990000'))
            ->assertOk()
            ->assertExactJson(['data' => [
                'phone' => '5567999990000',
                'open' => true,
                'last_inbound_at' => '2026-10-08T10:00:00Z',
                'closes_at' => '2026-10-09T10:00:00Z',
            ]]);
    }

    public function test_window_is_closed_when_the_last_inbound_message_is_older_than_24_hours(): void
    {
        Contact::factory()->withClosedServiceWindow()->create(['phone' => '5567999990000']);

        $this->getJson(route('api.v1.numbers.window.show', '5567999990000'))
            ->assertOk()
            ->assertJsonPath('data.open', false);
    }

    public function test_window_is_closed_for_a_number_that_never_wrote(): void
    {
        $this->getJson(route('api.v1.numbers.window.show', '5567999990000'))
            ->assertOk()
            ->assertExactJson(['data' => [
                'phone' => '5567999990000',
                'open' => false,
                'last_inbound_at' => null,
                'closes_at' => null,
            ]]);
    }
}
