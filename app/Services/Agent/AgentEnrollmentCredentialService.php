<?php

namespace App\Services\Agent;

use App\Models\AgentEnrollmentCredential;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class AgentEnrollmentCredentialService
{
    public const PREFIX = 'treck_enroll';

    public function __construct(private readonly AgentSecurityLogger $security) {}

    /**
     * @return array{credential:AgentEnrollmentCredential,secret:string}
     */
    public function create(
        Organization $organization,
        string $name,
        ?Carbon $expiresAt = null,
        ?int $maxUses = 1,
        ?User $actor = null,
    ): array {
        $publicId = $this->uniquePublicId();
        $secret = $this->randomSecret();
        $plain = self::PREFIX.'_'.$publicId.'_'.$secret;

        $credential = AgentEnrollmentCredential::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'public_id' => $publicId,
            'secret_hash' => Hash::make($secret),
            'expires_at' => $expiresAt,
            'max_uses' => $maxUses,
            'uses_count' => 0,
            'created_by' => $actor?->id,
        ]);

        $this->security->event('enrollment_credential_created', $organization, actor: $actor, context: [
            'credential_id' => $credential->id,
            'public_id' => $credential->public_id,
            'max_uses' => $credential->max_uses,
            'expires_at' => $credential->expires_at?->toIso8601String(),
        ]);

        return ['credential' => $credential, 'secret' => $plain];
    }

    public function revoke(AgentEnrollmentCredential $credential, ?User $actor = null): AgentEnrollmentCredential
    {
        if ($credential->revoked_at === null) {
            $credential->forceFill([
                'revoked_at' => now(),
                'revoked_by' => $actor?->id,
            ])->save();

            $this->security->event('enrollment_credential_revoked', $credential->organization, actor: $actor, context: [
                'credential_id' => $credential->id,
                'public_id' => $credential->public_id,
            ]);
        }

        return $credential;
    }

    public function consumeForRegistration(string $plainSecret, Closure $callback): mixed
    {
        return DB::transaction(function () use ($plainSecret, $callback) {
            $credential = $this->credentialForSecret($plainSecret, lock: true);

            if ($credential === null) {
                $this->security->event('enrollment_credential_invalid_attempt');

                throw new RuntimeException('invalid_enrollment_credential');
            }

            if (! $credential->isActive()) {
                $this->security->event('enrollment_credential_'.$credential->status().'_attempt', $credential->organization, context: [
                    'credential_id' => $credential->id,
                    'public_id' => $credential->public_id,
                ]);

                throw new RuntimeException('invalid_enrollment_credential');
            }

            $result = $callback($credential);

            $credential->forceFill([
                'uses_count' => $credential->uses_count + 1,
                'last_used_at' => now(),
            ])->save();

            $this->security->event('enrollment_credential_used', $credential->organization, context: [
                'credential_id' => $credential->id,
                'public_id' => $credential->public_id,
                'uses_count' => $credential->uses_count,
            ]);

            return $result;
        });
    }

    public function credentialForSecret(string $plainSecret, bool $lock = false): ?AgentEnrollmentCredential
    {
        $parts = $this->parse($plainSecret);

        if ($parts === null) {
            return null;
        }

        [$publicId, $secret] = $parts;

        $query = AgentEnrollmentCredential::query()
            ->with('organization')
            ->where('public_id', $publicId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $credential = $query->first();

        if ($credential === null || ! Hash::check($secret, $credential->secret_hash)) {
            return null;
        }

        return $credential;
    }

    /**
     * @return array{0:string,1:string}|null
     */
    public function parse(string $plainSecret): ?array
    {
        $plainSecret = trim($plainSecret);
        $prefix = preg_quote(self::PREFIX, '/');

        if (! preg_match('/^'.$prefix.'_([a-z0-9]{16})_([A-Za-z0-9\-_]{43})$/', $plainSecret, $matches)) {
            return null;
        }

        return [$matches[1], $matches[2]];
    }

    private function uniquePublicId(): string
    {
        do {
            $publicId = Str::lower(Str::random(16));
        } while (AgentEnrollmentCredential::where('public_id', $publicId)->exists());

        return $publicId;
    }

    private function randomSecret(): string
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        if (strlen($secret) !== 43) {
            throw new RuntimeException('Could not generate enrollment credential.');
        }

        return $secret;
    }
}
