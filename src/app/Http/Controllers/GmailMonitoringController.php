<?php

namespace App\Http\Controllers;

use App\Services\Monitoring\GmailRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class GmailMonitoringController extends Controller
{
    public function show(GmailRecoveryService $recovery): JsonResponse
    {
        return response()->json($recovery->status())->header('Cache-Control', 'no-store');
    }

    public function store(GmailRecoveryService $recovery): JsonResponse
    {
        try {
            $recovery->request();
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->status)->header('Cache-Control', 'no-store');
        }

        return response()->json($recovery->status(), 202)->header('Cache-Control', 'no-store');
    }
}
