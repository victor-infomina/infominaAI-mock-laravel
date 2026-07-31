<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ApiClient extends Model
{
    protected $fillable = [
        'label',
        'key',
        'secret_hash',
        'expires_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return array{0: self, 1: string} the created client and its plaintext secret
     */
    public static function createWithSecret(string $label, ?int $expiresInDays): array
    {
        $secret = Str::random(40);

        $apiClient = static::create([
            'label' => $label,
            'key' => Str::random(24),
            'secret_hash' => Hash::make($secret),
            'expires_at' => $expiresInDays !== null ? now()->addDays($expiresInDays) : null,
        ]);

        return [$apiClient, $secret];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }
}
