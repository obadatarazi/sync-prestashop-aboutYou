<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    use ApiResponseTrait;

    /**
     * @OA\Get(
     *     path="/api/v1/settings",
     *     operationId="settingsIndex",
     *     tags={"Settings"},
     *     summary="List settings",
     *     description="Returns persisted key/value rows, including safety toggles dry_run and test_mode (see sync status snapshot).",
     *     @OA\Response(
     *         response=200,
     *         description="Settings fetched successfully",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/ApiSuccessBase"),
     *                 @OA\Schema(@OA\Property(property="rows", type="array", @OA\Items(ref="#/components/schemas/Setting")))
     *             }
     *         )
     *     )
     * )
     */
    public function index()
    {
        return $this->success(['rows' => Setting::query()->orderBy('group_name')->orderBy('key')->get()]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/settings",
     *     operationId="settingsSave",
     *     tags={"Settings"},
     *     summary="Save settings",
     *     description="Persists settings used by sync jobs, including dry_run and test_mode safety toggles.",
     *     security={{"bearerAuth":{}}, {"apiTokenHeader":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/SettingsSaveRequest")),
     *     @OA\Response(
     *         response=200,
     *         description="Settings saved",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/ApiSuccessBase"),
     *                 @OA\Schema(@OA\Property(property="saved", type="array", @OA\Items(type="string", example="sync.batch_size")))
     *             }
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthorized", @OA\JsonContent(ref="#/components/schemas/ApiError")),
     *     @OA\Response(response=422, description="Invalid settings payload", @OA\JsonContent(ref="#/components/schemas/ApiError"))
     * )
     */
    public function save(Request $request, SettingsService $settingsService)
    {
        $settings = $request->input('settings', []);
        if (!is_array($settings)) {
            return $this->error('Invalid settings payload');
        }
        $settingsService->setMany($settings);

        return $this->success(['saved' => array_keys($settings)]);
    }
}
