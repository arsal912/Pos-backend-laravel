<?php

namespace App\Http\Middleware;

use App\Models\ApiLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ApiLogger
{
    /**
     * Sensitive fields that should be masked in logs.
     */
    protected array $sensitiveFields = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'token',
        'api_key',
        'api_secret',
        'secret',
        'card_number',
        'cvv',
        'cvc',
        'authorization',
        'x-api-key',
        'cookie',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (!config('api-logging.enabled', true)) {
            return $next($request);
        }

        $startTime = microtime(true);
        $exception = null;
        $stackTrace = null;

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $exception = $e->getMessage();
            $stackTrace = $e->getTraceAsString();
            throw $e;
        } finally {
            $duration = (int) ((microtime(true) - $startTime) * 1000);
            $this->logRequest($request, $response ?? null, $duration, $exception, $stackTrace);
        }

        return $response;
    }

    protected function logRequest(
        Request $request,
        ?Response $response,
        int $duration,
        ?string $exception = null,
        ?string $stackTrace = null
    ): void {
        try {
            // Skip excluded routes
            if ($this->shouldSkip($request)) {
                return;
            }

            $user = $request->user();
            $maxBodySize = config('api-logging.max_body_size', 10000);

            ApiLog::create([
                'user_id' => $user?->id,
                'store_id' => $user?->store_id,
                'method' => $request->method(),
                'endpoint' => $request->fullUrl(),
                'route_name' => optional($request->route())->getName(),
                'request_headers' => $this->maskSensitiveData($request->headers->all()),
                'request_payload' => $this->maskSensitiveData($request->all()),
                'response_status' => $response?->getStatusCode(),
                'response_body' => $this->getResponseBody($response, $maxBodySize),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'duration_ms' => $duration,
                'exception' => $exception,
                'stack_trace' => $stackTrace,
            ]);
        } catch (Throwable $e) {
            // Never let logging break the application
            Log::error('API Logger failed: ' . $e->getMessage());
        }
    }

    protected function shouldSkip(Request $request): bool
    {
        $excluded = config('api-logging.excluded_routes', []);
        $path = $request->path();

        foreach ($excluded as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    protected function maskSensitiveData(array $data): array
    {
        array_walk_recursive($data, function (&$value, $key) {
            if (in_array(strtolower((string) $key), $this->sensitiveFields, true)) {
                $value = '***MASKED***';
            }
        });

        return $data;
    }

    protected function getResponseBody(?Response $response, int $maxSize): ?array
    {
        if (!$response) {
            return null;
        }

        $content = $response->getContent();
        if (empty($content)) {
            return null;
        }

        // Truncate large responses
        if (strlen($content) > $maxSize) {
            return ['_truncated' => true, '_size' => strlen($content)];
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['_non_json' => substr($content, 0, 500)];
        }

        return is_array($decoded) ? $this->maskSensitiveData($decoded) : ['data' => $decoded];
    }
}
