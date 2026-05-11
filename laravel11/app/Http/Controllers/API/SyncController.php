<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductSyncRequest;
use App\Http\Requests\SyncCommandRequest;
use App\Services\Sync\SyncRunner;
use App\Traits\ApiResponseTrait;

class SyncController extends Controller
{
    use ApiResponseTrait;

    private function allowLongRunningRequest(): void
    {
        // Sync operations can take longer than the default PHP web timeout.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/sync",
     *     operationId="syncRun",
     *     tags={"Sync"},
     *     summary="Run sync command",
     *     description="Executes a supported sync command and returns run metadata.",
     *     security={{"bearerAuth":{}}, {"apiTokenHeader":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/SyncCommandRequest")),
     *     @OA\Response(
     *         response=200,
     *         description="Sync command executed",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/ApiSuccessBase"),
     *                 @OA\Schema(
     *                     @OA\Property(property="run_id", type="integer", nullable=true, example=1001),
     *                     @OA\Property(property="result", type="object", additionalProperties=true)
     *                 )
     *             }
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ApiError")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ApiError")),
     *     @OA\Response(response=500, description="Sync execution failure", @OA\JsonContent(ref="#/components/schemas/ApiError"))
     * )
     */
    public function run(SyncCommandRequest $request, SyncRunner $runner)
    {
        $this->allowLongRunningRequest();
        $result = $runner->run($request->string('command')->value(), $request->validated());
        return ($result['ok'] ?? false)
            ? $this->success(['run_id' => $result['run_id'] ?? null, 'result' => $result['result'] ?? []], 'sync executed')
            : $this->error((string) ($result['error'] ?? 'sync failed'), 500);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/sync/products",
     *     operationId="syncRunProductsByIds",
     *     tags={"Sync"},
     *     summary="Run product sync for specific product IDs",
     *     description="Runs products sync for the provided PrestaShop product IDs. Defaults to incremental mode.",
     *     security={{"bearerAuth":{}}, {"apiTokenHeader":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/ProductSyncRequest")),
     *     @OA\Response(
     *         response=200,
     *         description="Product sync command executed",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/ApiSuccessBase"),
     *                 @OA\Schema(
     *                     @OA\Property(property="run_id", type="string", nullable=true, example="f0e1d2c3b4a59687"),
     *                     @OA\Property(property="result", type="object", additionalProperties=true)
     *                 )
     *             }
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ApiError")),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ApiError")),
     *     @OA\Response(response=500, description="Sync execution failure", @OA\JsonContent(ref="#/components/schemas/ApiError"))
     * )
     */
    public function runProducts(ProductSyncRequest $request, SyncRunner $runner)
    {
        $this->allowLongRunningRequest();
        $payload = $request->validated();
        $command = (string) ($payload['sync_command'] ?? 'products:inc');
        unset($payload['sync_command']);

        $result = $runner->run($command, $payload);

        return ($result['ok'] ?? false)
            ? $this->success(['run_id' => $result['run_id'] ?? null, 'result' => $result['result'] ?? []], 'product sync executed')
            : $this->error((string) ($result['error'] ?? 'sync failed'), 500);
    }
}
