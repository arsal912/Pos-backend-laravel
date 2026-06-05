<?php

namespace App\Reports;

use App\Contracts\ReportInterface;
use App\DTOs\ReportResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

abstract class BaseReport implements ReportInterface
{
    /** Override in subclass to add description shown in report list. */
    public function getDescription(): string { return ''; }

    /** Module slug required to access this report. */
    public function getRequiredModule(): ?string { return null; }

    /** Additional permission beyond view-reports. */
    public function getRequiredPermission(): ?string { return null; }

    /** Show on the reports landing page. */
    public function isVisible(): bool { return true; }

    // ── Default filters ──────────────────────────────────────────────────────

    public function getDefaultFilters(): array
    {
        return [
            'date_range' => 'this_month',
            'branch_id'  => null,
            'compare'    => false,
        ];
    }

    public function getFilterSchema(): array
    {
        return [
            [
                'key'      => 'date_range',
                'type'     => 'date_range',
                'label'    => 'Date Range',
                'default'  => 'this_month',
                'required' => true,
            ],
        ];
    }

    // ── Date helpers ─────────────────────────────────────────────────────────

    /** Parse filters into [start: Carbon, end: Carbon] using store timezone. */
    protected function parseDateRange(array $filters): array
    {
        $tz      = $this->timezone();
        $preset  = $filters['date_range'] ?? 'this_month';
        $now     = Carbon::now($tz);

        $ranges = [
            'today'         => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday'     => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'this_week'     => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'last_week'     => [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()],
            'this_month'    => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_month'    => [$now->copy()->subMonth()->startOfMonth(), $now->copy()->subMonth()->endOfMonth()],
            'this_quarter'  => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()],
            'last_quarter'  => [$now->copy()->subQuarter()->startOfQuarter(), $now->copy()->subQuarter()->endOfQuarter()],
            'this_year'     => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'last_year'     => [$now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear()],
        ];

        if ($preset === 'custom') {
            $start = isset($filters['date_from']) ? Carbon::parse($filters['date_from'], $tz)->startOfDay() : $now->copy()->startOfMonth();
            $end   = isset($filters['date_to'])   ? Carbon::parse($filters['date_to'],   $tz)->endOfDay()   : $now->copy()->endOfDay();
            return [$start, $end];
        }

        return $ranges[$preset] ?? [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
    }

    /** Calculate the previous period of the same length. */
    protected function previousPeriod(Carbon $start, Carbon $end): array
    {
        $days       = $end->diffInDays($start) + 1;
        $prevEnd    = $start->copy()->subSecond();
        $prevStart  = $prevEnd->copy()->subDays($days - 1)->startOfDay();
        return [$prevStart, $prevEnd];
    }

    protected function timezone(): string
    {
        try {
            if (! app()->bound('current_store')) {
                return config('app.timezone', 'Asia/Karachi');
            }
            return app('current_store')?->timezone ?? config('app.timezone', 'Asia/Karachi');
        } catch (\Throwable) {
            return config('app.timezone', 'Asia/Karachi');
        }
    }

    // ── Branch filter ─────────────────────────────────────────────────────────

    protected function applyBranchFilter($query, array $filters, string $column = 'branch_id')
    {
        $branchId = $filters['branch_id'] ?? null;
        if ($branchId) {
            $query->where($column, $branchId);
        }
        return $query;
    }

    protected function branchId(array $filters): ?int
    {
        return isset($filters['branch_id']) && $filters['branch_id'] ? (int) $filters['branch_id'] : null;
    }

    // ── Query helpers ─────────────────────────────────────────────────────────

    protected function salesBase(Carbon $start, Carbon $end, ?int $branchId = null)
    {
        $q = DB::table('sales')
            ->where('status', 'completed')
            ->where('sale_date', '>=', $start->toDateString())
            ->where('sale_date', '<=', $end->toDateString());

        if ($branchId) $q->where('branch_id', $branchId);

        return $q;
    }

    // ── Formatting ───────────────────────────────────────────────────────────

    protected function formatMoney(float|null $amount): string
    {
        return number_format((float) ($amount ?? 0), 2);
    }

    protected function pct(float $a, float $b): float
    {
        if ($b == 0) return 0;
        return round(($a - $b) / abs($b) * 100, 1);
    }

    protected function margin(float $revenue, float $cost): float
    {
        if ($revenue == 0) return 0;
        return round(($revenue - $cost) / $revenue * 100, 1);
    }

    // ── Caching ───────────────────────────────────────────────────────────────

    /**
     * Cache a report result for 5 minutes.
     * Key includes tenant ID + slug + filters hash.
     */
    protected function remember(string $slug, array $filters, \Closure $callback): ReportResult
    {
        $tenantId = app()->bound('current_store') ? (app('current_store')?->id ?? 'global') : 'global';
        $key      = "report:{$tenantId}:{$slug}:" . md5(json_encode($filters));

        try {
            // Use the database or array driver to avoid tagged-cache requirement
            // when running inside stancl tenancy context (file driver doesn't support tags)
            $store = Cache::driver('array');

            $cached = $store->get($key);
            if ($cached instanceof ReportResult) {
                return $cached;
            }

            $result = $callback();
            $store->put($key, $result, now()->addMinutes(5));
            return $result;
        } catch (\Throwable) {
            // Cache unavailable — run without caching
            return $callback();
        }
    }

    // ── Meta helper ───────────────────────────────────────────────────────────

    protected function buildMeta(array $filters, Carbon $start, Carbon $end, int $rowCount = 0): array
    {
        return [
            'filters_used'  => $filters,
            'date_from'     => $start->toDateString(),
            'date_to'       => $end->toDateString(),
            'generated_at'  => now()->toIso8601String(),
            'row_count'     => $rowCount,
            'timezone'      => $this->timezone(),
        ];
    }

    // ── Summary card helper ───────────────────────────────────────────────────

    protected function card(string $label, float|int|string $raw, ?string $format = 'money', ?float $trend = null): array
    {
        $value = match ($format) {
            'money'  => $this->formatMoney((float) $raw),
            'int'    => number_format((int) $raw),
            'pct'    => number_format((float) $raw, 1) . '%',
            default  => (string) $raw,
        };

        return ['label' => $label, 'value' => $value, 'raw' => $raw, 'trend' => $trend, 'format' => $format];
    }
}
