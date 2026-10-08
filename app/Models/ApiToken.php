<?php

namespace App\Models;

use Database\Factories\ApiTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $token_hash
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'token_hash', 'last_used_at', 'revoked_at'])]
#[Hidden(['token_hash'])]
class ApiToken extends Model
{
    /** @use HasFactory<ApiTokenFactory> */
    use HasFactory;

    /**
     * The prefix that makes the application's tokens recognizable.
     */
    public const string PREFIX = 'mz_';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Create a token for the named client and return it with its plain-text value.
     *
     * The plain-text value is not stored and cannot be recovered later.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name): array
    {
        $plainText = self::PREFIX.Str::random(48);

        return [
            self::create(['name' => $name, 'token_hash' => self::hash($plainText)]),
            $plainText,
        ];
    }

    /**
     * Find the active token that matches the given plain-text value.
     */
    public static function findActive(string $plainText): ?self
    {
        return self::query()
            ->where('token_hash', self::hash($plainText))
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * Hash a plain-text token the way it is stored.
     */
    public static function hash(string $plainText): string
    {
        return hash('sha256', $plainText);
    }

    /**
     * Determine whether the token can no longer be used.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
