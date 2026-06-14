<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Uses Claude AI to parse natural language WhatsApp messages into
 * structured report requests.
 *
 * Supported report types: sales, inventory, customers, expenses,
 * top_products, revenue, loyalty
 */
class ReportParser
{
    private const ANTHROPIC_URL  = 'https://api.anthropic.com/v1/messages';
    private const MODEL          = 'claude-haiku-4-5-20251001';
    private const MAX_TOKENS     = 300;

    private const REPORT_DESCRIPTIONS = [
        'sales'        => 'Daily sales summary — total revenue, number of transactions, average order value',
        'inventory'    => 'Current stock levels — products, quantities, low stock alerts',
        'customers'    => 'Customer list — top customers, new customers, spending summary',
        'expenses'     => 'Expense summary by category — rent, utilities, salary, etc.',
        'top_products' => 'Best selling products — by quantity and revenue',
        'revenue'      => 'Revenue and profit summary — gross revenue, costs, profit margin',
        'loyalty'      => 'Loyalty points — earned, redeemed, top earners',
    ];

    public function parse(string $message, string $storeName): ?array
    {
        $apiKey = config('services.anthropic.api_key');
        if (! $apiKey) {
            Log::warning('WhatsApp ReportParser: ANTHROPIC_API_KEY not set.');
            return null;
        }

        $today  = now()->toDateString();
        $prompt = $this->buildPrompt($message, $storeName, $today);

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(15)->post(self::ANTHROPIC_URL, [
                'model'      => self::MODEL,
                'max_tokens' => self::MAX_TOKENS,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ]);

            if (! $response->successful()) {
                Log::error('ReportParser Anthropic API error', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            $text = $response->json('content.0.text', '');
            // Extract JSON from the response (Claude might wrap in markdown)
            if (preg_match('/\{.*\}/s', $text, $matches)) {
                $parsed = json_decode($matches[0], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $this->normalise($parsed, $today);
                }
            }

            Log::warning('ReportParser: could not parse JSON from Claude response', ['text' => $text]);
            return null;
        } catch (\Throwable $e) {
            Log::error('ReportParser exception', ['message' => $e->getMessage()]);
            return null;
        }
    }

    private function buildPrompt(string $message, string $storeName, string $today): string
    {
        $reportList = collect(self::REPORT_DESCRIPTIONS)
            ->map(fn ($desc, $type) => "- \"{$type}\": {$desc}")
            ->implode("\n");

        return <<<PROMPT
You are a report assistant for "{$storeName}", a retail store using a POS system.
A store user has sent this WhatsApp message:

"{$message}"

Today's date: {$today}

Your task: Parse the message and determine if it is a report request.

Available report types:
{$reportList}

Common date range examples (relative to today {$today}):
- "today" → same start and end date
- "this week" → start of Monday to today
- "this month" → 1st of current month to today
- "last month" → full previous calendar month
- "last 7 days" / "last week" → 7 days ago to today
- "last 30 days" → 30 days ago to today
- "20 May to now" → 2026-05-20 to {$today}
- "from Jan 1" → 2026-01-01 to {$today}
- "2025" → 2025-01-01 to 2025-12-31

Respond ONLY with valid JSON (no markdown, no explanation):
{
  "is_report_request": true,
  "report_type": "sales",
  "date_from": "YYYY-MM-DD",
  "date_to": "YYYY-MM-DD",
  "period_label": "human readable e.g. '20 May – Today'",
  "clarification_needed": false,
  "clarification_question": null
}

OR if NOT a report request:
{
  "is_report_request": false,
  "friendly_reply": "a short, helpful reply explaining what reports you can provide"
}

OR if the message is a report request but dates/type are unclear:
{
  "is_report_request": true,
  "clarification_needed": true,
  "clarification_question": "Could you please specify..."
}
PROMPT;
    }

    private function normalise(array $parsed, string $today): array
    {
        if (! ($parsed['is_report_request'] ?? false)) {
            return $parsed;
        }

        // Validate dates — fall back to sensible defaults
        try {
            $from = Carbon::parse($parsed['date_from'] ?? $today)->toDateString();
        } catch (\Throwable) {
            $from = now()->startOfMonth()->toDateString();
        }

        try {
            $to = Carbon::parse($parsed['date_to'] ?? $today)->toDateString();
        } catch (\Throwable) {
            $to = $today;
        }

        // Clamp date_to to today
        if ($to > $today) $to = $today;

        // Validate report type
        if (! array_key_exists($parsed['report_type'] ?? '', self::REPORT_DESCRIPTIONS)) {
            $parsed['report_type'] = 'sales'; // default
        }

        return array_merge($parsed, [
            'date_from'    => $from,
            'date_to'      => $to,
            'period_label' => $parsed['period_label'] ?? "{$from} to {$to}",
        ]);
    }
}
