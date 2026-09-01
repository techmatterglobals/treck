<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentOrganization;
use App\Models\AgentEnrollmentCredential;
use App\Services\Agent\AgentEnrollmentCredentialService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AgentEnrollmentCredentialController extends Controller
{
    public function index(CurrentOrganization $currentOrganization): View
    {
        $organization = $currentOrganization->resolve();

        return view('agent-enrollment-credentials.index', [
            'credentials' => AgentEnrollmentCredential::query()
                ->forOrganization($organization)
                ->latest()
                ->get(),
            'organization' => $organization,
            'newSecret' => session('agent_enrollment_secret'),
        ]);
    }

    public function store(
        Request $request,
        CurrentOrganization $currentOrganization,
        AgentEnrollmentCredentialService $service,
    ): RedirectResponse {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $created = $service->create(
            organization: $currentOrganization->resolve(),
            name: $data['name'],
            expiresAt: ! empty($data['expires_at']) ? now()->parse($data['expires_at']) : null,
            maxUses: array_key_exists('max_uses', $data) && $data['max_uses'] !== null ? (int) $data['max_uses'] : 1,
            actor: $request->user(),
        );

        return redirect()
            ->route('agent-enrollment-credentials.index')
            ->with('status', 'Enrollment credential created.')
            ->with('agent_enrollment_secret', $created['secret']);
    }

    public function revoke(
        AgentEnrollmentCredential $credential,
        CurrentOrganization $currentOrganization,
        AgentEnrollmentCredentialService $service,
    ): RedirectResponse {
        $organization = $currentOrganization->resolve();

        abort_unless((int) $credential->organization_id === (int) $organization->id, 404);

        $service->revoke($credential, request()->user());

        return redirect()
            ->route('agent-enrollment-credentials.index')
            ->with('status', 'Enrollment credential revoked.');
    }
}
