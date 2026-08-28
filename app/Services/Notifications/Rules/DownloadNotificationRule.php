<?php

namespace App\Services\Notifications\Rules;

use App\Enums\NotificationEventType;
use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\NotificationDraft;
use App\Services\Notifications\RuleSet;
use Illuminate\Support\Str;

/**
 * File-download alert rules (Phase 12). Evaluates a completed download against
 * the configurable rules and yields a draft per matching condition:
 *
 *   - download.executable  an executable was downloaded (.exe/.msi/…)
 *   - download.archive     an archive was downloaded (.zip/.rar/…)
 *   - download.large       the file is at/over the large-file threshold
 *   - download.restricted  the extension is on the admin's restricted list
 *                          (rule config `extensions`)
 *
 * Thresholds/lists come from config/treck.php and the rule row's `config`, so
 * behavior is tunable without code changes. Metadata only — never file contents.
 */
class DownloadNotificationRule implements NotificationRuleContract
{
    public function supports(NotificationContext $context): bool
    {
        return $context->source === 'download';
    }

    public function evaluate(NotificationContext $context, RuleSet $rules): iterable
    {
        $extension = strtolower(ltrim((string) $context->get('file_extension', ''), '.'));
        $fileName = (string) $context->get('file_name', 'a file');
        $size = (int) $context->get('file_size', 0);

        $executables = (array) config('treck.downloads.executable_extensions', []);
        $archives = (array) config('treck.downloads.archive_extensions', []);
        $largeBytes = (int) config('treck.downloads.large_file_bytes', 104857600);

        if ($extension !== '' && in_array($extension, $executables, true)) {
            yield $this->draft(NotificationEventType::DownloadExecutable, $context, $fileName, $extension);
        }

        if ($extension !== '' && in_array($extension, $archives, true)) {
            yield $this->draft(NotificationEventType::DownloadArchive, $context, $fileName, $extension);
        }

        if ($largeBytes > 0 && $size >= $largeBytes) {
            yield $this->draft(NotificationEventType::DownloadLarge, $context, $fileName, $extension);
        }

        // Restricted extensions are configured per-rule (admin-editable list).
        $restricted = array_map(
            fn ($e) => strtolower(ltrim((string) $e, '.')),
            (array) $rules->config(NotificationEventType::DownloadRestricted, 'extensions', []),
        );
        if ($extension !== '' && in_array($extension, $restricted, true)) {
            yield $this->draft(NotificationEventType::DownloadRestricted, $context, $fileName, $extension);
        }
    }

    private function draft(NotificationEventType $type, NotificationContext $context, string $fileName, string $extension): NotificationDraft
    {
        $who = $context->employee?->name ?? ($context->computer?->hostname ?? 'A user');
        $app = trim((string) $context->get('application_name', ''));
        $via = $app !== '' ? " via {$app}" : '';

        return new NotificationDraft(
            type: $type,
            title: $type->label(),
            message: "{$who} downloaded \"".Str::limit($fileName, 120)."\"{$via}.",
            // One alert per (type, computer, file) so re-syncs don't duplicate.
            dedupeKey: $type->value.':'.($context->computer?->id ?? '0').':'.strtolower($fileName),
            computer: $context->computer,
            employee: $context->employee,
            metadata: array_filter([
                'file_name' => $fileName,
                'file_extension' => $extension ?: null,
                'application_name' => $app ?: null,
            ], fn ($v) => $v !== null),
        );
    }
}
