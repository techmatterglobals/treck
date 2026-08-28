<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\StoreScreenshotRequest;
use App\Models\Computer;
use App\Services\Screenshots\ScreenshotService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/agent/screenshots
 *
 * Drains one screenshot from the desktop agent's offline queue (multipart:
 * `image` file + metadata fields). The device authenticates with its Sanctum
 * token (ability `agent:report`); the owning employee is resolved from the
 * Computer, never the body (SEC-1).
 *
 *   - 201 Created  → newly stored
 *   - 200 OK       → duplicate (same device + identical image hash)
 *
 * Both are 2xx so the agent clears the queued item and deletes its local temp
 * file. Anything else keeps it queued for retry, preserving order.
 */
class ScreenshotUploadController extends Controller
{
    public function store(
        StoreScreenshotRequest $request,
        ScreenshotService $service,
    ): JsonResponse {
        /** @var Computer $computer */
        $computer = $request->user();

        [$screenshot, $created] = $service->ingest(
            $computer,
            $request->safe()->except('image'),
            $request->file('image'),
        );

        return response()->json([
            'message' => $created ? 'Screenshot stored.' : 'Screenshot already recorded.',
            'data' => [
                'id' => $screenshot->id,
                'image_hash' => $screenshot->image_hash,
                'duplicate' => ! $created,
            ],
        ], $created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
