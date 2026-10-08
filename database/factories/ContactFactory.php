<?php

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '55'.fake()->unique()->numerify('##9########'),
            'wa_id' => null,
            'last_inbound_at' => null,
        ];
    }

    /**
     * Indicate that the contact wrote recently, so free-form messages are allowed.
     */
    public function withOpenServiceWindow(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_inbound_at' => now()->subHour(),
        ]);
    }

    /**
     * Indicate that the contact's last message is too old for free-form messages.
     */
    public function withClosedServiceWindow(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_inbound_at' => now()->subHours(Contact::SERVICE_WINDOW_HOURS + 1),
        ]);
    }
}
