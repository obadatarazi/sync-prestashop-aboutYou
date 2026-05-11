<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Sync\SyncRunner;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class LegacyActionController extends Controller
{
    use ApiResponseTrait;

    /**
     * @OA\Get(
     *     path="/api/legacy",
     *     operationId="legacyHandleGet",
     *     tags={"Legacy"},
     *     summary="Legacy endpoint (GET)",
     *     description="Legacy compatibility endpoint. Uses action query parameter to route behavior.",
     *     @OA\Parameter(
     *         name="action",
     *         in="query",
     *         required=true,
     *         @OA\Schema(type="string", enum={"status","products","orders","settings","settings_save","sync"})
     *     ),
     *     @OA\Parameter(name="command", in="query", @OA\Schema(type="string", example="products")),
     *     @OA\Response(response=200, description="Legacy action succeeded", @OA\JsonContent(type="object")),
     *     @OA\Response(response=404, description="Unsupported legacy action", @OA\JsonContent(ref="#/components/schemas/ApiError")),
     *     @OA\Response(response=500, description="Legacy sync failed", @OA\JsonContent(ref="#/components/schemas/ApiError"))
     * )
     *
     * @OA\Post(
     *     path="/api/legacy",
     *     operationId="legacyHandlePost",
     *     tags={"Legacy"},
     *     summary="Legacy endpoint (POST)",
     *     description="Legacy compatibility endpoint. Accepts body payload for action dispatching.",
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="action", type="string", enum={"status","products","orders","settings","settings_save","sync"}),
     *             @OA\Property(property="command", type="string", example="products"),
     *             @OA\Property(property="settings", type="object", additionalProperties=true)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Legacy action succeeded", @OA\JsonContent(type="object")),
     *     @OA\Response(response=404, description="Unsupported legacy action", @OA\JsonContent(ref="#/components/schemas/ApiError")),
     *     @OA\Response(response=500, description="Legacy sync failed", @OA\JsonContent(ref="#/components/schemas/ApiError"))
     * )
     */
    public function handle(Request $request, SyncRunner $runner)
    {
        $action = (string) $request->input('action', '');

        return match ($action) {
            'status' => $this->success(['status' => 'ok']),
            'products' => app(ProductController::class)->index(app(\App\Http\Requests\ProductIndexRequest::class)),
            'orders' => app(OrderController::class)->index($request),
            'settings' => app(SettingsController::class)->index(),
            'settings_save' => app(SettingsController::class)->save($request, app(\App\Services\SettingsService::class)),
            'sync' => $this->runSyncFromLegacy($request, $runner),
            default => $this->error('Unsupported legacy action: ' . $action, 404),
        };
    }

    private function runSyncFromLegacy(Request $request, SyncRunner $runner)
    {
        $command = (string) $request->input('command', 'status');
        $payload = $request->all();
        $payload['command'] = $command;
        $result = $runner->run($command, $payload);

        return ($result['ok'] ?? false)
            ? $this->success(['run_id' => $result['run_id'] ?? null, 'result' => $result['result'] ?? []])
            : $this->error((string) ($result['error'] ?? 'sync failed'), 500);
    }
}
