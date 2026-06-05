<?php

namespace App\Reports\Admin;

use App\DTOs\ReportResult;
use Illuminate\Support\Facades\DB;

class ChurnReport extends BaseAdminReport
{
    public function getName(): string { return 'Churn Report'; }
    public function getDescription(): string { return 'Cancelled and expired subscriptions — MRR lost this period.'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range','type'=>'date_range','label'=>'Date Range','default'=>'this_month','required'=>true],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('admin-churn', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);

            $churned = $this->centralDb()->table('subscriptions as sub')
                ->join('stores as s', 's.id', '=', 'sub.store_id')
                ->leftJoin('plans as p', 'p.id', '=', 'sub.plan_id')
                ->whereIn('sub.status', ['cancelled', 'expired'])
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('sub.cancelled_at', [$start, $end])
                      ->orWhereBetween('sub.ends_at', [$start, $end]);
                })
                ->select(
                    's.name as store_name',
                    's.email as store_email',
                    DB::raw("COALESCE(p.name,'—') as plan_name"),
                    DB::raw("COALESCE(p.price,0) as mrr_lost"),
                    'sub.status',
                    'sub.cancelled_at',
                    'sub.ends_at',
                    'sub.payment_gateway',
                )
                ->orderByDesc('sub.cancelled_at')
                ->get()
                ->map(fn($r) => [
                    'store_name'       => $r->store_name,
                    'store_email'      => $r->store_email ?? '—',
                    'plan_name'        => $r->plan_name,
                    'mrr_lost'         => round((float) $r->mrr_lost, 2),
                    'status'           => ucfirst($r->status),
                    'cancelled_at'     => $r->cancelled_at ? date('d M Y', strtotime($r->cancelled_at)) : '—',
                    'payment_gateway'  => $r->payment_gateway ?? '—',
                ]);

            $totalMrrLost  = round($churned->sum('mrr_lost'), 2);
            $cancelledCount = $churned->count();

            // Active MRR for churn rate calculation
            $activeMRR = (float) $this->centralDb()->table('subscriptions as sub')
                ->join('plans as p', 'p.id', '=', 'sub.plan_id')
                ->where('sub.status', 'active')
                ->sum('p.price');

            $churnRate = $activeMRR > 0
                ? round($totalMrrLost / ($activeMRR + $totalMrrLost) * 100, 1)
                : 0;

            return new ReportResult(
                summary: [
                    $this->card('Churned Stores',  $cancelledCount, 'int'),
                    $this->card('MRR Lost',         $totalMrrLost, 'money'),
                    $this->card('MRR Churn Rate',   $churnRate, 'pct'),
                    $this->card('Active MRR',        round($activeMRR, 2), 'money'),
                ],
                rows: $churned,
                columns: [
                    ['key'=>'store_name',      'label'=>'Store',          'type'=>'string'],
                    ['key'=>'plan_name',        'label'=>'Plan',           'type'=>'string'],
                    ['key'=>'mrr_lost',         'label'=>'MRR Lost',       'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'status',           'label'=>'Status',         'type'=>'string'],
                    ['key'=>'cancelled_at',     'label'=>'Cancelled/Expired','type'=>'string'],
                    ['key'=>'payment_gateway',  'label'=>'Gateway',        'type'=>'string'],
                ],
                totals: ['mrr_lost' => $totalMrrLost],
                meta: $this->buildMeta($filters, $start, $end, $churned->count()),
            );
        });
    }
}
