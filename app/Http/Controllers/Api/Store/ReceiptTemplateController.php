<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\PlatformReceiptSetting;
use App\Models\ReceiptTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReceiptTemplateController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        return $this->successResponse(['templates' => ReceiptTemplate::orderBy('type')->orderBy('name')->get()]);
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse(['template' => ReceiptTemplate::findOrFail($id)]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-settings')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'name'               => 'required|string|max:255',
            'type'               => 'required|in:thermal,a4',
            'header_text'        => 'nullable|string',
            'footer_text'        => 'nullable|string',
            'show_logo'          => 'sometimes|boolean',
            'show_tax_breakdown' => 'sometimes|boolean',
            'show_qr_code'       => 'sometimes|boolean',
            'custom_css'         => 'nullable|string',
            'is_default'         => 'sometimes|boolean',
            'is_active'          => 'sometimes|boolean',
        ]);

        $template = ReceiptTemplate::create($validated);

        if ($template->is_default) {
            $template->setAsDefault();
        }

        return $this->successResponse(['template' => $template], 'Template created.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-settings')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $template  = ReceiptTemplate::findOrFail($id);
        $validated = $request->validate([
            'name'               => 'sometimes|string|max:255',
            'header_text'        => 'nullable|string',
            'footer_text'        => 'nullable|string',
            'show_logo'          => 'sometimes|boolean',
            'show_tax_breakdown' => 'sometimes|boolean',
            'show_qr_code'       => 'sometimes|boolean',
            'custom_css'         => 'nullable|string',
            'is_default'         => 'sometimes|boolean',
            'is_active'          => 'sometimes|boolean',
        ]);

        $template->update($validated);

        if ($template->is_default) {
            $template->setAsDefault();
        }

        return $this->successResponse(['template' => $template->fresh()], 'Template updated.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-settings')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        ReceiptTemplate::findOrFail($id)->delete();

        return $this->successResponse(null, 'Template deleted.');
    }

    /**
     * Return a live HTML preview of a receipt using sample data.
     */
    public function preview(Request $request, int $id): \Illuminate\Http\Response
    {
        $template = ReceiptTemplate::findOrFail($id);
        $store    = app('current_store');

        $html = view('pos.receipt-preview', [
            'template'       => $template,
            'store'          => $store,
            'platformFooter' => PlatformReceiptSetting::current(),
        ])->render();

        return response($html, 200, ['Content-Type' => 'text/html']);
    }
}
