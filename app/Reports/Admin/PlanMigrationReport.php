<?php

namespace App\Reports\Admin;

use App\DTOs\ReportResult;
use Illuminate\Support\Facades\DB;

class PlanMigrationReport extends BaseAdminReport
{
    public function getName(): string { return 'Plan Migrations'; }
    public function getDescription(): string { return 'Stores that changed plans in the period — upgrades and downgrades.'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range','type'=>'date_range','label'=>'Date Range','default'=>'this_month','required'=>true],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('admin-plan-migrations', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);

            // Find stores with multiple subscriptions in the period (plan change indicator)
            $migrations = $this->centralDb()->table('subscriptions as sub')
                ->join('stores as s', 's.id', '=', 'sub.store_id')
                ->join('plans as p', 'p.id', '=', 'sub.plan_id')
                ->whereBetween('sub.created_at', [$start, $end])
                ->whereNotIn('sub.status', ['pending'])
                ->select(
                    's.name as store_name',
                    's.email as store_email',
                    'p.name as plan_name',
                    'p.price as plan_price',
                    'sub.status',
                    'sub.created_at',
                    'sub.payment_gateway',
                )
                ->orderBy('s.id')
                ->orderBy('sub.created_at')
                ->get();

            // Group by store and find plan changes
            $rows = collect();
            $byStore = $migrations->groupBy('store_name');
            foreach ($byStore as $storeName => $storeSubs) {
                if ($storeSubs->count() < 2) {
                    // Only one subscription event — just a new signup or renewal
                    $sub = $storeSubs->first();
                    $rows->push([
                        'store_name'   => $storeName,
                        'change_type'  => 'New Subscription',
                        'from_plan'    => '—',
                        'to_plan'      => $sub->plan_name,
                        'mrr_change'   => round((float)$sub->plan_price, 2),
                        'date'         => date('d M Y', strtotime($sub->created_at)),
                        'gateway'      => $sub->payment_gateway ?? '—',
                    ]);
                } else {
                    // Multiple — detect upgrade/downgrade
                    $sorted = $storeSubs->sortBy('created_at')->values();
                    for ($i = 1; $i < $sorted->count(); $i++) {
                        $prev = $sorted[$i-1];
                        $curr = $sorted[$i];
                        $prevPrice = (float) $prev->plan_price;
                        $currPrice = (float) $curr->plan_price;
                        $changeType = match(true) {
                            $currPrice > $prevPrice => 'Upgrade',
                            $currPrice < $prevPrice => 'Downgrade',
                            default => 'Same Plan',
                        };
                        $rows->push([
                            'store_name'  => $storeName,
                            'change_type' => $changeType,
                            'from_plan'   => $prev->plan_name,
                            'to_plan'     => $curr->plan_name,
                            'mrr_change'  => round($currPrice - $prevPrice, 2),
                            'date'        => date('d M Y', strtotime($curr->created_at)),
                            'gateway'     => $curr->payment_gateway ?? '—',
                        ]);
                    }
                }
            }

            $upgrades   = $rows->filter(fn($r) => $r['change_type'] === 'Upgrade')->count();
            $downgrades = $rows->filter(fn($r) => $r['change_type'] === 'Downgrade')->count();
            $netMRR     = round($rows->sum('mrr_change'), 2);

            return new ReportResult(
                summary: [
                    $this->card('Plan Changes',  $rows->count(), 'int'),
                    $this->card('Upgrades',       $upgrades, 'int'),
                    $this->card('Downgrades',     $downgrades, 'int'),
                    $this->card('Net MRR Change', $netMRR, 'money'),
                ],
                rows: $rows->values(),
                columns: [
                    ['key'=>'store_name',  'label'=>'Store',       'type'=>'string'],
                    ['key'=>'change_type', 'label'=>'Change',      'type'=>'string'],
                    ['key'=>'from_plan',   'label'=>'From',        'type'=>'string'],
                    ['key'=>'to_plan',     'label'=>'To',          'type'=>'string'],
                    ['key'=>'mrr_change',  'label'=>'MRR Δ',      'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'date',        'label'=>'Date',        'type'=>'string'],
                    ['key'=>'gateway',     'label'=>'Gateway',     'type'=>'string'],
                ],
                totals: ['mrr_change' => $netMRR],
                meta: $this->buildMeta($filters, $start, $end, $rows->count()),
            );
        });
    }
}
