<?php

namespace App\Http\Controllers;

use App\Models\MonitoredSite;
use App\Services\Monitoring\SiteRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SiteRecoveryController extends Controller
{
    public function store(Request $request, MonitoredSite $site, SiteRecoveryService $recovery): JsonResponse
    {
        try {
            $recovery->checkAndEnable($site);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->status);
        }

        $message = 'Перевірка успішна. Моніторинг сайту «'.$site->name.'» увімкнено.';
        $request->session()->flash('site_recovery_success', $message);

        return response()->json(['message' => $message]);
    }
}
