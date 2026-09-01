<?php

namespace App\Services\Agent;

use App\Models\Computer;
use App\Models\ComputerUser;
use App\Models\Employee;
use App\Services\Notifications\NotificationEngine;

/**
 * Resolves which employee an incoming agent event belongs to (Phase 11).
 *
 * A single physical computer may be shared by several employees across shifts.
 * The agent reports only the Windows identity (never an employee id); this
 * service maps `computer + windows_username → employee` via the
 * `computer_users` table:
 *
 *   - No/blank/system username  → the computer's assigned employee (the legacy,
 *     single-user path — fully backward compatible, no mapping row touched).
 *   - Known Windows account      → its mapped employee.
 *   - First account on a computer that already has an assigned employee →
 *     adopt that employee (so existing single-user machines keep attributing
 *     correctly the moment they start reporting a username).
 *   - Unrecognized account       → recorded as a *pending* mapping (employee_id
 *     null) so events are never lost, and the Super Admin is notified to map it.
 *
 * All lookups are indexed (unique on computer_id + windows_username); it never
 * scans historical events.
 */
class EmployeeResolver
{
    /**
     * Windows accounts that are never an interactive employee identity. Events
     * stamped with these (e.g. the session-0 service account) fall back to the
     * computer's assigned employee instead of creating a mapping.
     */
    private const NON_INTERACTIVE = [
        'system', 'localsystem', 'local service', 'network service',
        'nt authority', 'defaultapppool',
    ];

    public function __construct(
        private readonly NotificationEngine $engine,
        private readonly AgentSecurityLogger $security,
    ) {}

    public function resolve(Computer $computer, ?string $windowsUsername): ResolvedIdentity
    {
        $username = $this->normalize($windowsUsername);

        // Legacy / non-interactive account → attribute to the computer's owner.
        if ($username === null) {
            return new ResolvedIdentity($this->tenantEmployeeId($computer, $computer->employee_id));
        }

        $mapping = ComputerUser::firstOrNew([
            'computer_id' => $computer->id,
            'windows_username' => $username,
        ]);

        if (! $mapping->exists) {
            // First time we've seen this Windows account on this computer. If it
            // is the only account and the computer already has an assigned
            // employee, adopt it (the classic single-user machine). Otherwise it
            // stays pending until the Super Admin maps it.
            $firstOnComputer = ! ComputerUser::where('computer_id', $computer->id)->exists();

            if ($firstOnComputer) {
                $mapping->employee_id = $this->tenantEmployeeId($computer, $computer->employee_id);
            }
        }

        $mapping->is_active = true;
        $mapping->last_seen_at = now();
        $mapping->save();

        if ($mapping->employee_id === null) {
            $this->notifyPending($computer, $mapping);

            return new ResolvedIdentity(null, $mapping);
        }

        if ($this->tenantEmployeeId($computer, $mapping->employee_id) === null) {
            $this->security->event('agent_employee_mapping_organization_mismatch', $computer->organization, $computer, context: [
                'computer_user_id' => $mapping->id,
            ]);

            return new ResolvedIdentity(null, $mapping);
        }

        return new ResolvedIdentity($mapping->employee_id, $mapping);
    }

    private function tenantEmployeeId(Computer $computer, ?int $employeeId): ?int
    {
        if ($employeeId === null) {
            return null;
        }

        $belongsToComputerOrganization = Employee::query()
            ->whereKey($employeeId)
            ->where('organization_id', $computer->organization_id)
            ->exists();

        if (! $belongsToComputerOrganization) {
            $this->security->event('agent_employee_organization_mismatch', $computer->organization, $computer);

            return null;
        }

        return $employeeId;
    }

    /**
     * Trim, strip any DOMAIN\ prefix, and reject blank / machine ($) / known
     * service accounts. Returns null when the value is not a usable interactive
     * identity.
     */
    private function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (str_contains($value, '\\')) {
            $value = substr((string) strrchr($value, '\\'), 1);
        }

        if ($value === '' || str_ends_with($value, '$')) {
            return null;
        }

        $lower = strtolower($value);
        foreach (self::NON_INTERACTIVE as $reserved) {
            if ($lower === $reserved) {
                return null;
            }
        }

        return $value;
    }

    private function notifyPending(Computer $computer, ComputerUser $mapping): void
    {
        $this->engine->report(
            source: 'system',
            event: 'unknown_user',
            computer: $computer,
            data: ['windows_username' => $mapping->windows_username],
        );
    }
}
