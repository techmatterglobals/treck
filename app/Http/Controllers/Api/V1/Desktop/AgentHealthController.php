<?php

namespace App\Http\Controllers\Api\V1\Desktop;

use App\Http\Controllers\Controller;
use App\Services\Desktop\DesktopAgentHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentHealthController extends Controller
{
    public function __invoke(Request $request, DesktopAgentHealthService $health): JsonResponse
    {
        return response()->json(['data' => $health->forUser(
            $request->user(),
            (int) $request->attributes->get('desktop_organization_id'),
        )]);
    }
}
