<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OneDriveAdvisorInfoService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class AdvisorInfoController extends Controller
{
    public function index(OneDriveAdvisorInfoService $oneDrive): JsonResponse
    {
        try {
            return response()->json($oneDrive->listProviders());
        } catch (RuntimeException $error) {
            return response()->json([
                'message' => $error->getMessage(),
            ], 502);
        }
    }

    public function provider(string $providerId, OneDriveAdvisorInfoService $oneDrive): JsonResponse
    {
        try {
            return response()->json($oneDrive->listProviderFiles($providerId));
        } catch (RuntimeException $error) {
            return response()->json([
                'message' => $error->getMessage(),
            ], 502);
        }
    }

    public function file(string $itemId, OneDriveAdvisorInfoService $oneDrive): JsonResponse
    {
        try {
            return response()->json($oneDrive->file($itemId));
        } catch (RuntimeException $error) {
            return response()->json([
                'message' => $error->getMessage(),
            ], 502);
        }
    }

    public function content(string $itemId, OneDriveAdvisorInfoService $oneDrive)
    {
        try {
            return $oneDrive->contentResponse($itemId);
        } catch (RuntimeException $error) {
            return response()->json([
                'message' => $error->getMessage(),
            ], 502);
        }
    }
}
