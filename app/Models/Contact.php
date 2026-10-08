<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $phone
 * @property string|null $wa_id
 * @property Carbon|null $last_inbound_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'phone', 'wa_id', 'last_inbound_at'])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    /**
     * The number of hours a customer service window stays open after an inbound message.
     */
    public const int SERVICE_WINDOW_HOURS = 24;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_inbound_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Determine whether free-form messages can currently be sent to the contact.
     */
    public function hasOpenServiceWindow(): bool
    {
        return $this->serviceWindowClosesAt()?->isFuture() ?? false;
    }

    /**
     * Get the moment the customer service window closes, if it was ever opened.
     */
    public function serviceWindowClosesAt(): ?CarbonInterface
    {
        return $this->last_inbound_at?->addHours(self::SERVICE_WINDOW_HOURS);
    }

    /**
     * Get the number of hours, rounded up, until the customer service window closes.
     */
    public function serviceWindowHoursLeft(): int
    {
        if (! $this->hasOpenServiceWindow()) {
            return 0;
        }

        return (int) ceil(now()->diffInMinutes($this->serviceWindowClosesAt()) / 60);
    }

    /**
     * Limit the query to the contact identified by the given WhatsApp ID.
     *
     * @param  Builder<Contact>  $query
     */
    #[Scope]
    protected function forWhatsAppId(Builder $query, string $waId): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('wa_id', $waId)
            ->orWhereIn('phone', self::phoneVariants($waId)));
    }

    /**
     * Find the contact that owns the given phone number, in any form WhatsApp reports it.
     */
    public static function findByPhone(string $phone): ?self
    {
        return self::query()->forWhatsAppId($phone)->first();
    }

    /**
     * Find the contact that owns the given phone number, creating it when it is new.
     */
    public static function findOrCreateByPhone(string $phone, ?string $name = null): self
    {
        return self::findByPhone($phone) ?? self::create(['name' => $name ?? $phone, 'phone' => $phone]);
    }

    /**
     * Get the forms under which WhatsApp may identify the given phone number.
     *
     * Brazilian mobile numbers are reported with or without the ninth digit.
     *
     * @return list<string>
     */
    public static function phoneVariants(string $phone): array
    {
        if (! str_starts_with($phone, '55')) {
            return [$phone];
        }

        if (strlen($phone) === 13 && $phone[4] === '9') {
            return [$phone, substr($phone, 0, 4).substr($phone, 5)];
        }

        if (strlen($phone) === 12) {
            return [$phone, substr($phone, 0, 4).'9'.substr($phone, 4)];
        }

        return [$phone];
    }
}
