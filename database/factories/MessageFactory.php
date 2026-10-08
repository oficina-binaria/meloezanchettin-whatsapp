<?php

namespace Database\Factories;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Contact;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'direction' => MessageDirection::Outbound,
            'wamid' => 'wamid.'.fake()->unique()->regexify('[A-Za-z0-9]{40}'),
            'type' => 'text',
            'template_name' => null,
            'body' => fake()->sentence(),
            'status' => MessageStatus::Accepted,
            'status_at' => now(),
            'error_code' => null,
            'error_message' => null,
            'sent_at' => now(),
        ];
    }

    /**
     * Indicate that the message was received from the contact.
     */
    public function inbound(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => MessageDirection::Inbound,
            'status' => null,
            'status_at' => null,
        ]);
    }
}
