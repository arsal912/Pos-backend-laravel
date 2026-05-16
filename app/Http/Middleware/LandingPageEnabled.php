<?php

namespace App\Http\Middleware;

use App\Models\LandingPageSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class LandingPageEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $settings = Cache::remember('landing_page_settings', 300, function () {
            return LandingPageSetting::current();
        });

        if (!$settings->is_enabled) {
            return response()->json([
                'success' => false,
                'enabled' => false,
                'message' => $settings->maintenance_message ?? 'Landing page is currently unavailable.',
                'redirect' => $settings->redirect_when_disabled,
            ], 503);
        }

        return $next($request);
    }
}
