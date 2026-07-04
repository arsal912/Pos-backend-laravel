<?php

namespace App\Reports\Customer;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use App\Models\LoyaltySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LoyaltyOverviewReport extends BaseReport
{
    public function getName(): string { return 'Loyalty Overview'; }
    public function getCategory(): string { return 'customer'; }
    public function getDescription(): string { return 'Points earned vs redeemed, top earners, expiring points.'; }
    public function getRequiredModule(): ?string { return 'loyalty'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range','type'=>'date_range','label'=>'Date Range','default'=>'this_month','required'=>true],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('loyalty-overview', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);

            if (! Schema::hasTable('loyalty_transactions')) {
                return new ReportResult(
                    summary: [$this->card('Note', 'Loyalty not set up yet', 'string')],
                    meta: $this->buildMeta($filters, $start, $end, 0),
                );
            }

            $settings = LoyaltySettings::current();

            // Points earned in period
            $earned = (float) DB::table('loyalty_transactions')
                ->whereIn('type', ['earn','welcome_bonus','birthday_bonus','referral_bonus','adjust_add'])
                ->where('points', '>', 0)
                ->whereBetween('created_at', [$start, $end])
                ->sum('points');

            // Points redeemed
            $redeemed = (float) abs(DB::table('loyalty_transactions')
                ->whereIn('type', ['redeem','adjust_deduct'])
                ->where('points', '<', 0)
                ->whereBetween('created_at', [$start, $end])
                ->sum('points'));

            // Total outstanding
            $outstanding = (float) DB::table('customers')->whereNull('deleted_at')->sum('loyalty_points_balance');

            // Expiring in next 30 days
            $expiring = (float) DB::table('loyalty_transactions')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now()->addDays(30))
                ->where('expires_at', '>=', now())
                ->where('points', '>', 0)
                ->sum('points');

            // Top earners
            $topEarners = DB::table('customers as c')
                ->whereNull('c.deleted_at')
                ->where('c.loyalty_points_balance', '>', 0)
                ->select('c.name', 'c.code', 'c.loyalty_points_balance')
                ->orderByDesc('c.loyalty_points_balance')
                ->limit(10)
                ->get()
                ->map(fn($r) => [
                    'customer_name'   => $r->name,
                    'code'            => $r->code,
                    'points_balance'  => round((float) $r->loyalty_points_balance, 0),
                    'cash_value'      => round((float) $r->loyalty_points_balance * (float) $settings->redemption_value, 2),
                ]);

            // Monthly earned vs redeemed chart
            $monthly = DB::table('loyalty_transactions')
                ->whereBetween('created_at', [$start, $end])
                ->groupByRaw("DATE_FORMAT(created_at,'%Y-%m')")
                ->selectRaw("
                    DATE_FORMAT(created_at,'%Y-%m') as month,
                    SUM(CASE WHEN points > 0 THEN points ELSE 0 END) as earned,
                    SUM(CASE WHEN points < 0 THEN ABS(points) ELSE 0 END) as redeemed
                ")
                ->orderByRaw("DATE_FORMAT(created_at,'%Y-%m')")
                ->get();

            $chartData = [
                'type'   => 'bar',
                'labels' => $monthly->pluck('month')->all(),
                'series' => [
                    ['name'=>'Earned',   'data'=>$monthly->pluck('earned')->map(fn($v) => round((float)$v,2))->all()],
                    ['name'=>'Redeemed', 'data'=>$monthly->pluck('redeemed')->map(fn($v) => round((float)$v,2))->all()],
                ],
            ];

            return new ReportResult(
                summary: [
                    $this->card('Points Earned',    $earned, 'int'),
                    $this->card('Points Redeemed',  $redeemed, 'int'),
                    $this->card('Outstanding (All Customers)', $outstanding, 'int'),
                    $this->card('Rs Value Outstanding', round($outstanding * (float)$settings->redemption_value, 2), 'money'),
                    $this->card('Expiring (30 days)', $expiring, 'int'),
                    $this->card('Earn Rate', '1 pt / ' . $settings->points_per_currency_unit . ' Rs', 'string'),
                ],
                rows: $topEarners,
                columns: [
                    ['key'=>'customer_name', 'label'=>'Customer',     'type'=>'string'],
                    ['key'=>'code',          'label'=>'Code',         'type'=>'string'],
                    ['key'=>'points_balance','label'=>'Points',       'type'=>'int',  'align'=>'right'],
                    ['key'=>'cash_value',    'label'=>'Rs Value',     'type'=>'money','align'=>'right'],
                ],
                chart_data: $chartData,
                meta: $this->buildMeta($filters, $start, $end, $topEarners->count()),
            );
        });
    }
}
