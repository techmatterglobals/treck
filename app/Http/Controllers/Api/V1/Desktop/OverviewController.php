<?php

namespace App\Http\Controllers\Api\V1\Desktop;

use App\Http\Controllers\Controller;
use App\Services\Desktop\DesktopOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OverviewController extends Controller
{
    public function __invoke(Request $request, DesktopOverviewService $overview): JsonResponse
    {
        return response()->json(['data' => $overview->forUser(
            $request->user(),
            (int) $request->attributes->get('desktop_organization_id'),
        )]);
    }
}
