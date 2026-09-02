<?php

namespace App\Console\Commands;

use App\Models\AgentEnrollmentCode;
use App\Services\Agent\AgentEnrollmentService;
use Illuminate\Console\Command;

/**
 * Admin CLI for one-time agent enrollment codes (installer flow).
 *
 *   php artisan treck:enroll-code                       # generate (default TTL / single use)
 *   php artisan treck:enroll-code --label="Reception PC" --expires-days=7 --uses=1
 *   php artisan treck:enroll-code --list                # list codes (never shows plaintext)
 *   php artisan treck:enroll-code --revoke=12           # revoke code #12
 *
 * The plaintext code is printed once on generation and never stored.
 */
class GenerateEnrollmentCode extends Command
{
    protected $signature = 'treck:enroll-code
        {--label= : Human label for the code (e.g. the computer or location)}
        {--expires-days= : Days until expiry (default: config treck.enrollment.default_ttl_days)}
        {--uses= : Max computers this code may enroll (default: config treck.enrollment.default_max_uses)}
        {--list : List existing codes instead of generating}
        {--revoke= : Revoke the code with this id}';

    protected $description = 'Generate, list, or revoke agent enrollment codes (installer flow).';

    public function handle(AgentEnrollmentService $enrollment): int
    {
        if ($this->option('list')) {
            return $this->listCodes();
        }

        if ($this->option('revoke') !== null) {
            return $this->revokeCode($enrollment, (int) $this->option('revoke'));
        }

        return $this->generateCode($enrollment);
    }

    private function generateCode(AgentEnrollmentService $enrollment): int
    {
        $ttlDays = $this->option('expires-days') !== null
            ? (int) $this->option('expires-days')
            : (int) config('treck.enrollment.default_ttl_days', 14);

        $maxUses = $this->option('uses') !== null
            ? (int) $this->option('uses')
            : (int) config('treck.enrollment.default_max_uses', 1);

        $expiresAt = $ttlDays > 0 ? now()->addDays($ttlDays) : null;

        ['code' => $code, 'model' => $model] = $enrollment->generate(
            creator: null,
            label: $this->option('label') ?: null,
            expiresAt: $expiresAt,
            maxUses: $maxUses,
        );

        $this->newLine();
        $this->info('Enrollment code created — copy it now; it is never shown again:');
        $this->line('');
        $this->line('    '.$code);
        $this->line('');
        $this->table(['Field', 'Value'], [
            ['ID', $model->id],
            ['Label', $model->label ?: '—'],
            ['Max uses', $model->max_uses],
            ['Expires', $expiresAt?->toDateTimeString() ?? 'never'],
        ]);

        return self::SUCCESS;
    }

    private function revokeCode(AgentEnrollmentService $enrollment, int $id): int
    {
        $code = AgentEnrollmentCode::find($id);
        if ($code === null) {
            $this->error("No enrollment code with id {$id}.");

            return self::FAILURE;
        }

        $enrollment->revoke($code);
        $this->info("Enrollment code #{$id} revoked.");

        return self::SUCCESS;
    }

    private function listCodes(): int
    {
        $rows = AgentEnrollmentCode::query()->latest('id')->limit(50)->get()
            ->map(fn (AgentEnrollmentCode $c) => [
                $c->id,
                $c->label ?: '—',
                '…'.($c->code_last_four ?? '????'),
                "{$c->uses}/{$c->max_uses}",
                $c->statusLabel(),
                $c->expires_at?->toDateTimeString() ?? 'never',
                $c->last_used_at?->toDateTimeString() ?? '—',
            ])->all();

        $this->table(
            ['ID', 'Label', 'Ends', 'Uses', 'Status', 'Expires', 'Last used'],
            $rows,
        );

        return self::SUCCESS;
    }
}
