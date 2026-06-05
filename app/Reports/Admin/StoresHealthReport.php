<?php

namespace App\Reports\Admin;

use App\DTOs\ReportResult;
use Illuminate\Support\Facades\DB;

class StoresHealthReport extends BaseAdminReport
{
    public function getName(): string { return 'Stores Health'; }
    public function getDescription(): string { return 'Per-store health: revenue, activity, plan, MRR contribution.'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'status','type'=>'select','label'=>'Store Status','default'=>'active',
             'options'=>[['value'=>'','label'=>'All'],['value'=>'active','label'=>'Active'],['value'=>'suspended','label'=>'Suspended'],['value'=>'expired','label'=>'Expired']]],
            ['key'=>'plan_id','type'=>'select','label'=>'Plan','options'=>[]],
        ];
    }

    public function getDefaultFilters(): array { return ['status'=>'active','plan_id'=>null]; }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('admin-stores-health', $filters, function () use ($filters) {
            $status = $filters['status'] ?? null;
            $planId = $filters['plan_id'] ?? null;

            $rows = $this->centralDb()->table('stores as s')
                ->leftJoin('store_aggregates as sa', 'sa.store_id', '=', 's.id')
                ->leftJoin('subscriptions as sub', function ($j) {
                    $j->on('sub.store_id', '=', 's.id')->where('sub.status', 'active');
                })
                ->leftJoin('plans as p', 'p.id', '=', 'sub.plan_id')
                ->leftJoin('users as owner', function ($j) {
                    $j->on('owner.store_id', '=', 's.id');
                    // would ideally pick store-owner role, but joining on role is complex
                })
                ->when($status, fn($q) => $q->where('s.status', $status))
                ->when($planId, fn($q) => $q->where('p.id', $planId))
                ->groupBy('s.id','s.name','s.email','s.status','s.created_at','p.name','p.price','sa.today_revenue','sa.month_revenue','sa.total_revenue','sa.last_synced_at','sub.ends_at','sa.meta')
                ->select(
                    's.id',
                    's.name as store_name',
                    's.email as store_email',
                    's.status',
                    's.created_at',
                    DB::raw("COALESCE(p.name,'No Plan') as plan_name"),
                    DB::raw("COALESCE(p.price,0) as mrr"),
                    'sa.today_revenue',
                    'sa.month_revenue',
                    'sa.total_revenue',
                    'sa.last_synced_at',
                    'sub.ends_at as subscription_ends',
                    'sa.meta',
                )
                ->orderByDesc('sa.month_revenue')
                ->get()
                ->map(function ($r) {
                    $meta = is_string($r->meta) ? json_decode($r->meta, true) : (array)($r->meta ?? []);
                    return [
                        'store_id'          => $r->id,
                        'store_name'        => $r->store_name,
                        'store_email'       => $r->store_email ?? '—',
                        'status'            => $r->status,
                        'plan_name'         => $r->plan_name,
                        'mrr'               => round((float)$r->mrr, 2),
                        'today_revenue'     => round((float)$r->today_revenue, 2),
                        'month_revenue'     => round((float)$r->month_revenue, 2),
                        'total_revenue'     => round((float)$r->total_revenue, 2),
                        'total_products'    => (int)($meta['total_products'] ?? 0),
                        'total_customers'   => (int)($meta['total_customers'] ?? 0),
                        'subscription_ends' => $r->subscription_ends ?? '—',
                        'last_synced'       => $r->last_synced_at ? date('d M H:i', strtotime($r->last_synced_at)) : 'Never',
                        'created_at'        => date('d M Y', strtotime($r->created_at)),
                    ];
                });

            $totalMRR  = round($rows->sum('mrr'), 2);
            $totalStores = $rows->count();

            return new ReportResult(
                summary: [
                    $this->card('Stores', $totalStores, 'int'),
                    $this->card('Total MRR', $totalMRR, 'money'),
                    $this->card('Avg MRR/Store', $totalStores > 0 ? round($totalMRR / $totalStores, 2) : 0, 'money'),
                    $this->card('Total Platform Revenue', round($rows->sum('total_revenue'), 2), 'money'),
                ],
                rows: $rows,
                columns: [
                    ['key'=>'store_name',      'label'=>'Store',          'type'=>'string'],
                    ['key'=>'plan_name',        'label'=>'Plan',           'type'=>'string'],
                    ['key'=>'status',           'label'=>'Status',         'type'=>'string'],
                    ['key'=>'mrr',              'label'=>'MRR',            'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'month_revenue',    'label'=>'Month Revenue',  'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'total_products',   'label'=>'Products',       'type'=>'int',  'align'=>'right'],
                    ['key'=>'total_customers',  'label'=>'Customers',      'type'=>'int',  'align'=>'right'],
                    ['key'=>'subscription_ends','label'=>'Sub Ends',       'type'=>'string'],
                    ['key'=>'last_synced',      'label'=>'Last Synced',    'type'=>'string'],
                ],
                totals: ['mrr'=>$totalMRR,'month_revenue'=>round($rows->sum('month_revenue'),2)],
                meta: $this->buildMeta($filters, now(), now(), $rows->count()),
            );
        });
    }
}
