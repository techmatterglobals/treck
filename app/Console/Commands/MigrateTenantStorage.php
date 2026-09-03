<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Screenshot;
use App\Services\Screenshots\ScreenshotStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MigrateTenantStorage extends Command
{
    protected $signature = 'treck:migrate-tenant-storage
        {--organization= : Organization id or slug}
        {--chunk=200 : Rows to process per batch}
        {--dry-run : Report planned copies without writing}
        {--verify : Read-only verification that tenant-owned screenshots have tenant paths and bytes}';

    protected $description = 'Copy legacy screenshot files into organization-scoped storage paths.';

    public function handle(ScreenshotStorageService $storage): int
    {
        $organization = $this->resolveOrganization();

        if (! $organization) {
            $this->error('Target organization was not found.');

            return self::FAILURE;
        }

        if ($organization->isSuspended()) {
            $this->error('Target organization is suspended.');

            return self::FAILURE;
        }

        $summary = [
            'planned' => 0,
            'copied' => 0,
            'already_tenant' => 0,
            'missing_source' => 0,
            'target_exists' => 0,
            'verification_failures' => 0,
        ];

        $query = Screenshot::query()
            ->where('organization_id', $organization->id)
            ->orderBy('id');

        $query->chunkById(max(1, (int) $this->option('chunk')), function ($screenshots) use ($storage, &$summary) {
            foreach ($screenshots as $screenshot) {
                $this->process($screenshot, $storage, $summary);
            }
        });

        $this->line('organization_id='.$organization->id);
        foreach ($summary as $key => $value) {
            $this->line($key.'='.$value);
        }
        $this->line('platform_super_admin_assignments=0');

        if ($this->option('verify')) {
            return ($summary['planned'] === 0 && $summary['missing_source'] === 0 && $summary['verification_failures'] === 0)
                ? self::SUCCESS
                : self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->line('Dry run only; no files or rows were changed.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,int>  $summary
     */
    private function process(Screenshot $screenshot, ScreenshotStorageService $storage, array &$summary): void
    {
        $tenantPath = $storage->expectedTenantPath($screenshot);
        $legacyPath = $storage->expectedLegacyPath($screenshot);
        $disk = $screenshot->disk ?: $storage->disk();
        $filesystem = Storage::disk($disk);

        if ($tenantPath === null || $legacyPath === null) {
            $summary['verification_failures']++;

            return;
        }

        if ($screenshot->path === $tenantPath && $filesystem->exists($tenantPath)) {
            $summary['already_tenant']++;

            return;
        }

        if (! $filesystem->exists($legacyPath)) {
            $summary['missing_source']++;

            return;
        }

        $summary['planned']++;

        if ($this->option('dry-run') || $this->option('verify')) {
            return;
        }

        if ($filesystem->exists($tenantPath)) {
            $summary['target_exists']++;
        } else {
            $filesystem->put($tenantPath, $filesystem->get($legacyPath));
            $summary['copied']++;
        }

        if (! $filesystem->exists($tenantPath)
            || $filesystem->size($tenantPath) !== $filesystem->size($legacyPath)
            || $this->checksum($filesystem, $tenantPath) !== $this->checksum($filesystem, $legacyPath)) {
            $summary['verification_failures']++;

            return;
        }

        $screenshot->forceFill(['path' => $tenantPath])->save();
    }

    private function checksum($filesystem, string $path): string
    {
        return hash('sha256', $filesystem->get($path));
    }

    private function resolveOrganization(): ?Organization
    {
        $identifier = trim((string) $this->option('organization'));

        if ($identifier === '') {
            return null;
        }

        if (filter_var($identifier, FILTER_VALIDATE_INT) !== false) {
            return Organization::find((int) $identifier);
        }

        return Organization::query()
            ->where('slug', Str::slug($identifier))
            ->first();
    }
}
