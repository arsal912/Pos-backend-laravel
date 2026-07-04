<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\CommunicationProvider;
use App\Services\Communications\CommunicationsManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommunicationsProviderController extends Controller
{
    use ApiResponse;

    public function __construct(private CommunicationsManager $manager) {}

    public function index(): JsonResponse
    {
        $providers = CommunicationProvider::orderBy('channel')->orderBy('sort_order')->get()
            ->map(fn ($p) => array_merge($p->toArraySafe(), ['has_credentials' => ! empty($p->credentials)]));

        return $this->successResponse([
            'providers' => $providers->groupBy('channel'),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $provider = CommunicationProvider::findOrFail($id);

        $validated = $request->validate([
            'is_active'               => 'sometimes|boolean',
            'is_default_for_channel'  => 'sometimes|boolean',
            'credentials'             => 'sometimes|array',
            'config'                  => 'sometimes|array',
            'test_recipient'          => 'nullable|string',
            'name'                    => 'sometimes|string|max:100',
        ]);

        if (array_key_exists('is_active', $validated)) {
            $provider->is_active = filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('credentials', $validated)) {
            $provider->credentials = $validated['credentials'];
        }
        if (array_key_exists('config', $validated)) {
            $provider->config = $validated['config'];
        }
        if (array_key_exists('test_recipient', $validated)) {
            $provider->test_recipient = $validated['test_recipient'];
        }
        if (isset($validated['name'])) {
            $provider->name = $validated['name'];
        }

        $provider->save();

        if (filter_var($validated['is_default_for_channel'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $provider->setAsDefault();
        }

        // Flush manager cache so next request picks up new config
        $this->manager->flushCache($provider->channel);

        return $this->successResponse(['provider' => $provider->toArraySafe()], 'Provider updated.');
    }

    public function setDefault(int $id): JsonResponse
    {
        $provider = CommunicationProvider::findOrFail($id);
        $provider->update(['is_active' => true]);
        $provider->setAsDefault();
        $this->manager->flushCache($provider->channel);

        return $this->successResponse(['provider' => $provider->toArraySafe()], "Set as default {$provider->channel} provider.");
    }

    public function test(int $id): JsonResponse
    {
        $provider = CommunicationProvider::findOrFail($id);

        if (empty($provider->credentials)) {
            return $this->errorResponse('No credentials configured for this provider.', 400);
        }

        try {
            $instance = $this->manager->make($provider->channel, $provider->provider_slug);
            $success  = $instance->testConnection();

            if (! $success) {
                return $this->errorResponse('Connection test failed. Check credentials.', 400);
            }
        } catch (\Throwable $e) {
            return $this->errorResponse('Connection failed: ' . $e->getMessage(), 400);
        }

        // Optionally send a test message if test_recipient is configured
        $testMessage = null;
        if ($provider->test_recipient) {
            try {
                $recipient = $provider->test_recipient;
                $instance  = $this->manager->make($provider->channel, $provider->provider_slug);

                $result = match ($provider->channel) {
                    'sms'      => $instance->send($recipient, 'POS System: test message ✓'),
                    'email'    => $instance->send($recipient, 'POS System Test', '<p>Test connection successful ✓</p>'),
                    'whatsapp' => $instance->sendMessage($recipient, 'POS System: test message ✓'),
                };

                $testMessage = $result->success
                    ? "Test message sent to {$recipient} (ID: {$result->providerMessageId})"
                    : "Credentials valid but test send failed: {$result->error}";
            } catch (\Throwable $e) {
                $testMessage = 'Test send failed: ' . $e->getMessage();
            }
        }

        return $this->successResponse([
            'connected'    => true,
            'test_message' => $testMessage,
        ], 'Connection successful.');
    }
}
