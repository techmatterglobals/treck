<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentOrganization;
use App\Exceptions\Tenancy\CurrentOrganizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class OrganizationSelectionController extends Controller
{
    public function index(Request $request, CurrentOrganization $currentOrganization): View
    {
        $current = null;

        try {
            $current = $currentOrganization->resolve($request->user());
        } catch (CurrentOrganizationException) {
            $currentOrganization->clear();
        }

        return view('organizations.select', [
            'organizations' => $request->user()->activeOrganizations()->orderBy('name')->get(),
            'currentOrganization' => $current,
        ]);
    }

    public function update(Request $request, CurrentOrganization $currentOrganization): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'integer'],
        ]);

        try {
            $organization = $currentOrganization->select($request->user(), (int) $validated['organization_id']);
        } catch (CurrentOrganizationException) {
            abort(Response::HTTP_FORBIDDEN, 'You cannot select that organization.');
        }

        $request->session()->regenerate();
        $request->session()->put(CurrentOrganization::SESSION_KEY, $organization->id);

        $redirect = $this->safeRedirect($request);

        return redirect($redirect)->with('status', "Switched to {$organization->name}.");
    }

    private function safeRedirect(Request $request): string
    {
        $redirect = (string) $request->input('redirect', route('dashboard', absolute: false));

        if ($redirect === '' || str_starts_with($redirect, route('organizations.select', absolute: false))) {
            return route('dashboard');
        }

        if (str_starts_with($redirect, '/') && ! str_starts_with($redirect, '//')) {
            return $redirect;
        }

        throw ValidationException::withMessages([
            'redirect' => 'The redirect destination is not allowed.',
        ]);
    }
}
