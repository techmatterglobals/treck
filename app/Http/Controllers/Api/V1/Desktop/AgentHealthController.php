<?php

namespace App\Http\Controllers\Api\V1\Desktop;

use App\Http\Controllers\Api\V1\Desktop\Concerns\AuthorizesDesktopAccess;
use App\Http\Controllers\Controller;
use App\Services\Desktop\DesktopAgentHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentHealthController extends Controller
{
    use AuthorizesDesktopAccess;

    public function __invoke(Request $request, DesktopAgentHealthService $health): JsonResponse
    {
        $user = $request->user();
        $this->authorizeDesktopAccess($user);

        return response()->json(['data' => $health->forUser($user)]);
    }
}
