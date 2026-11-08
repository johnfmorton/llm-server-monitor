<?php

namespace App\Models;

use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'is_client', 'notes'])]
#[Hidden(['token_hash'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_client' => 'boolean',
            'last_report_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<DailySummary, $this>
     */
    public function dailySummaries(): HasMany
    {
        return $this->hasMany(DailySummary::class);
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * @return HasMany<Alert, $this>
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /**
     * Issue a new API token for this server and return the plain text.
     *
     * Only a SHA-256 hash is stored. The plain token is shown once, when it's issued.
     */
    public function issueToken(): string
    {
        $token = 'lw_'.Str::random(48);
        $this->forceFill(['token_hash' => self::hashToken($token)])->save();

        return $token;
    }

    public static function findByToken(string $token): ?self
    {
        return self::where('token_hash', self::hashToken($token))->first();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
