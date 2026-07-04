<?php

namespace App\Reports\Customer;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

class InactiveCustomersReport extends BaseReport
{
    public function getName(): string { return 'Inactive Customers'; }
    public function getCategory(): string { return 'customer'; }
    public function getDescription(): string { return 'Customers who haven\'t purchased recently — re-engagement targets.'; }
    public function getRequiredModule(): ?string { return 'customer-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'days_inactive','type'=>'number','label'=>'Days Inactive','default'=>60],
            ['key'=>'group_id',     'type'=>'select','label'=>'Customer Group','options'=>[]],
            ['key'=>'branch_id',    'type'=>'branch_select','label'=>'Branch'],
        ];
    }

    public function getDefaultFilters(): array
    {
        return ['days_inactive'=>60,'group_id'=>null,'branch_id'=>null];
    }

    public function run(array $filters): ReportResult
    {
        $filters     = array_merge($this->getDefaultFilters(), $filters);
        $daysInactive = max(1, (int) ($filters['days_inactive'] ?? 60));
        $groupId      = $filters['group_id'] ?? null;
        $cutoff       = now()->subDays($daysInactive)->toDateString();

        return $this->remember('inactive-customers', $filters, function () use ($filters, $daysInactive, $groupId, $cutoff) {
            $rows = DB::table('customers as c')
                ->leftJoin('customer_groups as cg', 'cg.id', '=', 'c.customer_group_id')
                ->whereNull('c.deleted_at')
                ->where('c.is_active', true)
                ->where('c.total_purchases_count', '>', 0)  // must have bought at least once
                ->where(function ($q) use ($cutoff) {
                    $q->where('c.last_purchase_at', '<', $cutoff)
                      ->orWhereNull('c.last_purchase_at');
                })
                ->when($groupId, fn($q) => $q->where('c.customer_group_id', $groupId))
                ->selectRaw("
                    c.id, c.name, c.code, c.phone,
                    COALESCE(cg.name,'No Group') as group_name,
                    c.last_purchase_at,
                    c.lifetime_value,
                    c.total_purchases_count,
                    c.loyalty_points_balance
                ")
                ->orderByDesc('c.lifetime_value')
                ->get()
                ->map(function ($r) use ($cutoff) {
                    $lastPurchase   = $r->last_purchase_at;
                    $daysSinceLastPurchase = $lastPurchase ? now()->diffInDays($lastPurchase) : null;
                    return [
                        'customer_name'       => $r->name,
                        'code'                => $r->code,
                        'phone'               => $r->phone ?? '—',
                        'group_name'          => $r->group_name,
                        'last_purchase'       => $lastPurchase ?? '—',
                        'days_inactive'       => $daysSinceLastPurchase,
                        'lifetime_value'      => round((float) $r->lifetime_value, 2),
                        'total_purchases'     => (int) $r->total_purchases_count,
                        'loyalty_points'      => round((float) $r->loyalty_points_balance, 0),
                    ];
                });

            return new ReportResult(
                summary: [
                    $this->card('Inactive Customers',      $rows->count(), 'int'),
                    $this->card('Inactivity Threshold',    "{$daysInactive} days", 'string'),
                    $this->card('At-risk LTV',             $rows->sum('lifetime_value'), 'money'),
                    $this->card('Avg Days Inactive',       $rows->filter(fn($r) => $r['days_inactive'])->avg('days_inactive') ? round($rows->filter(fn($r) => $r['days_inactive'])->avg('days_inactive')) : 0, 'int'),
                ],
                rows: $rows,
                columns: [
                    ['key'=>'customer_name', 'label'=>'Customer',       'type'=>'string'],
                    ['key'=>'group_name',    'label'=>'Group',          'type'=>'string'],
                    ['key'=>'phone',         'label'=>'Phone',          'type'=>'string'],
                    ['key'=>'last_purchase', 'label'=>'Last Purchase',  'type'=>'string'],
                    ['key'=>'days_inactive', 'label'=>'Days Inactive',  'type'=>'int',  'align'=>'right'],
                    ['key'=>'total_purchases','label'=>'Total Purchases','type'=>'int', 'align'=>'right'],
                    ['key'=>'lifetime_value','label'=>'LTV',            'type'=>'money','align'=>'right'],
                    ['key'=>'loyalty_points','label'=>'Points',         'type'=>'int',  'align'=>'right'],
                ],
                meta: $this->buildMeta($filters, now()->subDays($daysInactive), now(), $rows->count()),
            );
        });
    }
}
