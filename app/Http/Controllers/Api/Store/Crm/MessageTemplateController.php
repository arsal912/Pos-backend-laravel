<?php

namespace App\Http\Controllers\Api\Store\Crm;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\MessageTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageTemplateController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = MessageTemplate::query();

        if ($request->filled('channel')) {
            $query->where('channel', $request->input('channel'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('search')) {
            $term = $request->input('search');
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('body', 'like', "%{$term}%");
            });
        }
        if ($request->boolean('active_only')) {
            $query->active();
        }

        $templates = $query->orderBy('is_system', 'desc')
                           ->orderBy('channel')
                           ->orderBy('name')
                           ->paginate($request->input('per_page', 50));

        return $this->paginatedResponse($templates);
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse(MessageTemplate::findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                    => 'required|string|max:100',
            'description'             => 'nullable|string|max:255',
            'channel'                 => 'required|in:sms,email,whatsapp',
            'type'                    => 'required|in:transactional,marketing,reminder,birthday,manual',
            'subject'                 => 'nullable|string|max:255',
            'body'                    => 'required|string|max:5000',
            'variables'               => 'nullable|array',
            'variables.*.key'         => 'required|string|max:50',
            'variables.*.label'       => 'required|string|max:100',
            'variables.*.example'     => 'nullable|string|max:100',
            'is_active'               => 'boolean',
            'whatsapp_template_name'  => 'nullable|string|max:100',
        ]);

        $template = MessageTemplate::create(array_merge($validated, ['is_system' => false]));

        return $this->successResponse($template, 'Template created.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $template = MessageTemplate::findOrFail($id);

        $validated = $request->validate([
            'name'                    => 'sometimes|string|max:100',
            'description'             => 'nullable|string|max:255',
            // System templates: allow body/name/is_active/description edits; channel & type are locked
            'channel'                 => $template->is_system ? 'prohibited' : 'sometimes|in:sms,email,whatsapp',
            'type'                    => $template->is_system ? 'prohibited' : 'sometimes|in:transactional,marketing,reminder,birthday,manual',
            'subject'                 => 'nullable|string|max:255',
            'body'                    => 'sometimes|string|max:5000',
            'variables'               => 'nullable|array',
            'variables.*.key'         => 'required_with:variables|string|max:50',
            'variables.*.label'       => 'required_with:variables|string|max:100',
            'variables.*.example'     => 'nullable|string|max:100',
            'is_active'               => 'boolean',
            'whatsapp_template_name'  => 'nullable|string|max:100',
        ]);

        $template->update($validated);

        return $this->successResponse($template->fresh(), 'Template updated.');
    }

    public function destroy(int $id): JsonResponse
    {
        $template = MessageTemplate::findOrFail($id);

        if ($template->is_system) {
            return $this->errorResponse('System templates cannot be deleted. Duplicate it to create an editable copy.', 422);
        }

        $template->delete();

        return $this->successResponse(null, 'Template deleted.');
    }

    public function duplicate(int $id): JsonResponse
    {
        $original = MessageTemplate::findOrFail($id);

        $copy = $original->replicate();
        $copy->name      = $original->name.' (copy)';
        $copy->is_system = false;
        $copy->save();

        return $this->successResponse($copy, 'Template duplicated.', 201);
    }
}
