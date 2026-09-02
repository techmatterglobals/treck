<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time (by default) enrollment code used by the installer to enroll a
 * computer. The plaintext code is shown to an admin exactly once at creation and
 * is stored here only as a SHA-256 hash ({@see hashOf()}); it can never be
 * recovered from the database. A code is usable while it is not revoked, not
 * expired, and has remaining uses.
 */
class AgentEnrollmentCode extends Model
{
    use HasFactory;

    /** Unambiguous alphabet (no 0/O/1/I) for human-typed codes. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    protected $fillable = [
        'code_hash',
        'code_last_four',
        'label',
        'max_uses',
        'uses',
        'expires_at',
        'revoked_at',
        'created_by',
        'last_used_at',
        'last_used_ip',
        'last_computer_id',
    ];

    protected function casts(): array
    {
        return [
            'max_uses' => 'integer',
            'uses' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    // ---- Code generation / hashing ----------------------------------------

    /**
     * A fresh cryptographically-random plaintext code, formatted
     * "TRK-XXXX-XXXX-XXXX". Returned to the caller once; only its hash is stored.
     */
    public static function generatePlaintext(): string
    {
        $groups = [];
        for ($g = 0; $g < 3; $g++) {
            $chunk = '';
            for ($i = 0; $i < 4; $i++) {
                $chunk .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $groups[] = $chunk;
        }

        return 'TRK-'.implode('-', $groups);
    }

    /** Normalize user input (uppercase, strip spaces) before hashing/compare. */
    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', $code) ?? '');
    }

    /** Deterministic lookup hash of a code. Never store the plaintext. */
    public static function hashOf(string $code): string
    {
        return hash('sha256', self::normalize($code));
    }

    /** The trailing group of a plaintext code, for admin display only. */
    public static function lastFourOf(string $code): string
    {
        $norm = self::normalize($code);

        return substr($norm, -4);
    }

    // ---- State ------------------------------------------------------------

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasUsesLeft(): bool
    {
        return $this->uses < $this->max_uses;
    }

    /** Usable = not revoked, not expired, and with remaining uses. */
    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired() && $this->hasUsesLeft();
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereColumn('uses', '<', 'max_uses');
    }

    // ---- Relationships ----------------------------------------------------

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lastComputer(): BelongsTo
    {
        return $this->belongsTo(Computer::class, 'last_computer_id');
    }

    /** Human-readable status for admin listings. */
    public function statusLabel(): string
    {
        return match (true) {
            $this->isRevoked() => 'revoked',
            $this->isExpired() => 'expired',
            ! $this->hasUsesLeft() => 'used',
            default => 'active',
        };
    }
}
