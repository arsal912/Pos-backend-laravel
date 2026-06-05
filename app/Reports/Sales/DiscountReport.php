<?php

namespace App\Reports\Sales;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

class DiscountReport extends BaseReport
{
    public function getName(): string { return 'Discount Analysis'; }
    public function getCategory(): string { return 'sales'; }
    public function getDescription(): string { return 'Discount patterns — by cashier, product, and type.'; }
    public function getRequiredModule(): ?string { return 'sales-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range','type'=>'date_range','label'=>'Date Range','default'=>'this_month','required'=>true],
            ['key'=>'branch_id', 'type'=>'branch_select','label'=>'Branch'],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('discount-analysis', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId = $this->branchId($filters);

            // Sale-level discounts by cashier
            $byCashier = $this->salesBase($start, $end, $branchId)
                ->where('discount_amount', '>', 0)
                ->groupBy('cashier_id')
                ->selectRaw("cashier_id, COUNT(*) as sales_with_discount, SUM(discount_amount) as total_discount, SUM(total) as revenue")
                ->orderByDesc('total_discount')
                ->get();

            $cashierIds = $byCashier->pluck('cashier_id')->filter()->all();
            $userNames  = DB::connection('mysql')->table('users')->whereIn('id', $cashierIds)->pluck('name', 'id');

            $cashierRows = $byCashier->map(fn($r) => [
                'cashier_name'        => $userNames[$r->cashier_id] ?? "Cashier #{$r->cashier_id}",
                'sales_with_discount' => (int) $r->sales_with_discount,
                'total_discount'      => round((float) $r->total_discount, 2),
                'discount_pct'        => (float) $r->revenue > 0 ? round((float) $r->total_discount / (float) $r->revenue * 100, 1) : 0,
            ]);

            // Overall stats
            $totalSaleLevel = (float) $this->salesBase($start, $end, $branchId)->sum('discount_amount');
            $totalItemLevel = (float) DB::table('sale_items as si')
                ->join('sales as s', 's.id', '=', 'si.sale_id')
                ->where('s.status', 'completed')
                ->whereBetween('s.sale_date', [$start->toDateString(), $end->toDateString()])
                ->when($branchId, fn($q) => $q->where('s.branch_id', $branchId))
                ->sum('si.discount_amount');

            $totalRevenue = (float) $this->salesBase($start, $end, $branchId)->sum('total');

            return new ReportResult(
                summary: [
                    $this->card('Total Discounts Given', $totalSaleLevel + $totalItemLevel, 'money'),
                    $this->card('Sale-Level Discounts',  $totalSaleLevel, 'money'),
                    $this->card('Item-Level Discounts',  $totalItemLevel, 'money'),
                    $this->card('Discount Rate', $totalRevenue > 0 ? round(($totalSaleLevel + $totalItemLevel) / ($totalRevenue + $totalSaleLevel + $totalItemLevel) * 100, 1) : 0, 'pct'),
                ],
                rows: $cashierRows,
                columns: [
                    ['key'=>'cashier_name',        'label'=>'Cashier',        'type'=>'string'],
                    ['key'=>'sales_with_discount',  'label'=>'Sales Discounted','type'=>'int',  'align'=>'right','total'=>true],
                    ['key'=>'total_discount',       'label'=>'Total Discount', 'type'=>'money', 'align'=>'right','total'=>true],
                    ['key'=>'discount_pct',         'label'=>'Discount %',     'type'=>'percent','align'=>'right'],
                ],
                totals: ['sales_with_discount'=>$cashierRows->sum('sales_with_discount'),'total_discount'=>round($cashierRows->sum('total_discount'),2)],
                meta: $this->buildMeta($filters, $start, $end, $cashierRows->count()),
            );
        });
    }
}
