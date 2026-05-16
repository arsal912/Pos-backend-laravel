<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\LandingPageSetting;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class LandingController extends Controller
{
    use ApiResponse;

    /**
     * Get landing page data (settings + sections + plans).
     * Public endpoint.
     */
    public function index(): JsonResponse
    {
        $data = Cache::remember('landing_page_data', 300, function () {
            $settings = LandingPageSetting::with(['sections' => function ($q) {
                $q->where('is_enabled', true)->orderBy('sort_order');
            }])->first() ?? LandingPageSetting::current();

            $plans = Plan::where('is_active', true)
                ->with('modules:id,name,slug')
                ->orderBy('sort_order')
                ->orderBy('price')
                ->get();

            return [
                'settings' => [
                    'is_enabled' => $settings->is_enabled,
                    'site_title' => $settings->site_title,
                    'site_description' => $settings->site_description,
                    'meta_keywords' => $settings->meta_keywords,
                    'og_image' => $settings->og_image,
                    'favicon' => $settings->favicon,
                    'logo' => $settings->logo,
                    'primary_color' => $settings->primary_color,
                    'maintenance_message' => $settings->maintenance_message,
                    'redirect_when_disabled' => $settings->redirect_when_disabled,
                ],
                'sections' => $settings->sections->groupBy('section_key')->map(function ($items) {
                    return $items->map(fn ($s) => [
                        'key' => $s->section_key,
                        'title' => $s->title,
                        'subtitle' => $s->subtitle,
                        'content' => $s->content,
                        'is_enabled' => $s->is_enabled,
                        'sort_order' => $s->sort_order,
                    ]);
                }),
                'plans' => $plans->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'description' => $p->description,
                    'price' => $p->price,
                    'currency' => $p->currency,
                    'billing_cycle' => $p->billing_cycle,
                    'trial_days' => $p->trial_days,
                    'features' => $p->features,
                    'is_featured' => $p->is_featured,
                    'limits' => [
                        'max_products' => $p->max_products,
                        'max_users' => $p->max_users,
                        'max_branches' => $p->max_branches,
                    ],
                ]),
            ];
        });

        return $this->successResponse($data, 'Landing page data retrieved');
    }

    /**
     * Just return whether landing page is enabled (fast check).
     */
    public function status(): JsonResponse
    {
        $settings = Cache::remember('landing_page_status', 300, function () {
            $s = LandingPageSetting::current();
            return [
                'is_enabled' => $s->is_enabled,
                'maintenance_message' => $s->maintenance_message,
                'redirect_when_disabled' => $s->redirect_when_disabled,
            ];
        });

        return $this->successResponse($settings);
    }

    /**
     * Get only the active pricing plans.
     */
    public function plans(): JsonResponse
    {
        $plans = Cache::remember('public_plans', 300, function () {
            return Plan::where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('price')
                ->get();
        });

        return $this->successResponse($plans, 'Plans retrieved');
    }
}
