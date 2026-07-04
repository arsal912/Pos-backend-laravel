<?php

namespace App\Reports\Admin;

use App\DTOs\ReportResult;
use Illuminate\Support\Facades\DB;

class StoresAtRiskReport extends BaseAdminReport
{
    public function getName(): string { return 'Stores at Risk'; }
    public function getDescription(): string { return 'Stores showing decline, inactivity, failed payments, or expiring subs.'; }

    public function getFilterSchema(): array { return []; }
    public function getDefaultFilters(): array { return []; }

    public function run(array $filters): ReportResult
    {
        return $this->remember('admin-stores-at-risk', $filters, function () use ($filters) {
            $now = now();

            $activeStores = $this->centralDb()->table('stores as s')
                ->leftJoin('store_aggregates as sa', 'sa.store_id', '=', 's.id')
                ->leftJoin('subscriptions as sub', function ($j) {
                    $j->on('sub.store_id','=','s.id')->where('sub.status','active');
                })
                ->leftJoin('plans as p', 'p.id', '=', 'sub.plan_id')
                ->where('s.status', 'active')
                ->select('s.id','s.name','s.email','s.created_at',DB::raw("COALESCE(p.name,'No Plan') as plan_name"),'sa.today_revenue','sa.month_revenue','sub.ends_at','sa.last_payment_at','sa.meta')
                ->get();

            // Previous month revenue for comparison
            $prevMonthRevenue = $this->centralDb()->table('store_aggregates')
                ->selectRaw('store_id, month_revenue')
                ->pluck('month_revenue', 'store_id');

            $risks = collect();

            foreach ($activeStores as $s) {
                $riskFactors = [];
                $meta = is_string($s->meta) ? json_decode($s->meta, true) : (array)($s->meta ?? []);

                // No sales in 7 days
                $salesCountToday = (int)($meta['sales_today_count'] ?? 0);
                $lastPayment = $s->last_payment_at;
                if ($lastPayment && $now->diffInDays($lastPayment) > 7) {
                    $riskFactors[] = 'No sales in ' . $now->diffInDays($lastPayment) . ' days';
                }

                // Revenue dropped >30%
                $monthRev     = (float)($s->month_revenue ?? 0);
                $prevMonthRev = (float)($prevMonthRevenue[$s->id] ?? 0);
                if ($prevMonthRev > 0 && $monthRev < $prevMonthRev * 0.7) {
                    $drop = round((1 - $monthRev / $prevMonthRev) * 100);
                    $riskFactors[] = "Revenue down {$drop}% vs last sync";
                }

                // Sub expiring in 7 days
                if ($s->ends_at && $now->diffInDays($s->ends_at) <= 7 && strtotime($s->ends_at) > time()) {
                    $riskFactors[] = 'Subscription expiring in ' . $now->diffInDays($s->ends_at) . ' days';
                }

                // Failed payment
                $failedPmt = $this->centralDb()->table('payments')
                    ->where('store_id', $s->id)
                    ->where('status', 'failed')
                    ->where('created_at', '>=', $now->copy()->subDays(7))
                    ->exists();
                if ($failedPmt) $riskFactors[] = 'Failed payment in last 7 days';

                if (! empty($riskFactors)) {
                    $risks->push([
                        'store_name'    => $s->name,
                        'store_email'   => $s->email ?? '—',
                        'plan_name'     => $s->plan_name,
                        'month_revenue' => round($monthRev, 2),
                        'risk_factors'  => implode('; ', $riskFactors),
                        'risk_count'    => count($riskFactors),
                        'sub_ends'      => $s->ends_at ?? '—',
                    ]);
                }
            }

            $sorted = $risks->sortByDesc('risk_count')->values();

            return new ReportResult(
                summary: [
                    $this->card('At-Risk Stores',     $sorted->count(), 'int'),
                    $this->card('No Sales (7d)',       $risks->filter(fn($r) => str_contains($r['risk_factors'], 'No sales'))->count(), 'int'),
                    $this->card('Revenue Declining',   $risks->filter(fn($r) => str_contains($r['risk_factors'], 'down'))->count(), 'int'),
                    $this->card('Failed Payments',     $risks->filter(fn($r) => str_contains($r['risk_factors'], 'Failed'))->count(), 'int'),
                ],
                rows: $sorted,
                columns: [
                    ['key'=>'store_name',   'label'=>'Store',        'type'=>'string'],
                    ['key'=>'plan_name',    'label'=>'Plan',         'type'=>'string'],
                    ['key'=>'month_revenue','label'=>'Month Rev',    'type'=>'money','align'=>'right'],
                    ['key'=>'risk_count',   'label'=>'Risk Factors', 'type'=>'int',  'align'=>'right'],
                    ['key'=>'risk_factors', 'label'=>'Risks',        'type'=>'string'],
                    ['key'=>'sub_ends',     'label'=>'Sub Ends',     'type'=>'string'],
                ],
                meta: $this->buildMeta($filters, now(), now(), $sorted->count()),
            );
        });
    }
}
