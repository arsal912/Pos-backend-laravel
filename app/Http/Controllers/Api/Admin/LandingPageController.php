<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\LandingPageSection;
use App\Models\LandingPageSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LandingPageController extends Controller
{
    use ApiResponse;

    /**
     * Get all landing page settings + sections (admin view).
     */
    public function index(): JsonResponse
    {
        $settings = LandingPageSetting::with('sections')->first() ?? LandingPageSetting::current();

        return $this->successResponse([
            'settings' => $settings,
            'sections' => $settings->sections()->orderBy('sort_order')->get(),
        ]);
    }

    /**
     * Toggle landing page ON/OFF (master switch).
     */
    public function toggle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled' => 'required|boolean',
            'maintenance_message' => 'nullable|string|max:500',
            'redirect_when_disabled' => 'nullable|url',
        ]);

        $settings = LandingPageSetting::current();
        $settings->update($validated);

        $this->clearCache();

        return $this->successResponse(
            ['is_enabled' => $settings->is_enabled],
            $settings->is_enabled ? 'Landing page enabled' : 'Landing page disabled'
        );
    }

    /**
     * Update global landing page settings.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_title' => 'nullable|string|max:255',
            'site_description' => 'nullable|string|max:500',
            'meta_keywords' => 'nullable|string|max:500',
            'logo' => 'nullable|string',
            'favicon' => 'nullable|string',
            'og_image' => 'nullable|string',
            'primary_color' => 'nullable|string|max:20',
        ]);

        $settings = LandingPageSetting::current();
        $settings->update($validated);

        $this->clearCache();

        return $this->successResponse($settings, 'Settings updated');
    }

    /**
     * Update / toggle a specific section.
     */
    public function updateSection(Request $request, string $sectionKey): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string',
            'subtitle' => 'nullable|string',
            'content' => 'nullable|array',
            'is_enabled' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $settings = LandingPageSetting::current();

        $section = LandingPageSection::updateOrCreate(
            [
                'setting_id' => $settings->id,
                'section_key' => $sectionKey,
            ],
            $validated
        );

        $this->clearCache();

        return $this->successResponse($section, 'Section updated');
    }

    /**
     * Toggle a specific section's visibility.
     */
    public function toggleSection(Request $request, string $sectionKey): JsonResponse
    {
        $validated = $request->validate(['is_enabled' => 'required|boolean']);

        $settings = LandingPageSetting::current();

        $section = LandingPageSection::updateOrCreate(
            ['setting_id' => $settings->id, 'section_key' => $sectionKey],
            ['is_enabled' => $validated['is_enabled']]
        );

        $this->clearCache();

        return $this->successResponse(
            ['section_key' => $sectionKey, 'is_enabled' => $section->is_enabled],
            $section->is_enabled ? 'Section enabled' : 'Section disabled'
        );
    }

    /**
     * Reorder sections.
     */
    public function reorderSections(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sections' => 'required|array',
            'sections.*.section_key' => 'required|string',
            'sections.*.sort_order' => 'required|integer',
        ]);

        $settings = LandingPageSetting::current();

        foreach ($validated['sections'] as $item) {
            LandingPageSection::where('setting_id', $settings->id)
                ->where('section_key', $item['section_key'])
                ->update(['sort_order' => $item['sort_order']]);
        }

        $this->clearCache();

        return $this->successResponse(null, 'Sections reordered');
    }

    protected function clearCache(): void
    {
        Cache::forget('landing_page_data');
        Cache::forget('landing_page_status');
        Cache::forget('landing_page_settings');
        Cache::forget('public_plans');
    }
}
