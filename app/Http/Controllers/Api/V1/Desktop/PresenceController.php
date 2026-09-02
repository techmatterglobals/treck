<?php

namespace App\Http\Controllers\Api\V1\Desktop;

use App\Http\Controllers\Controller;
use App\Services\Desktop\DesktopPresenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PresenceController extends Controller
{
    public function __invoke(Request $request, DesktopPresenceService $presence): JsonResponse
    {
        return response()->json(['data' => $presence->forUser(
            $request->user(),
            (int) $request->attributes->get('desktop_organization_id'),
        )]);
    }
}
