<?php

namespace App\Services\Reports;

use App\Contracts\ReportInterface;
use App\Reports\SimpleTestReport;
// Sales reports (Step 2)
use App\Reports\Sales\SalesSummaryReport;
use App\Reports\Sales\SalesByDayReport;
use App\Reports\Sales\SalesByHourReport;
use App\Reports\Sales\SalesByProductReport;
use App\Reports\Sales\SalesByCategoryReport;
use App\Reports\Sales\SalesByBrandReport;
use App\Reports\Sales\SalesByCashierReport;
use App\Reports\Sales\SalesByPaymentMethodReport;
use App\Reports\Sales\SalesByCustomerReport;
use App\Reports\Sales\DiscountReport;
use App\Reports\Sales\ReturnsReport;
// Inventory reports (Step 3)
use App\Reports\Inventory\StockOnHandReport;
use App\Reports\Inventory\LowStockReport;
use App\Reports\Inventory\OutOfStockReport;
use App\Reports\Inventory\StockMovementReport;
use App\Reports\Inventory\StockAgingReport;
use App\Reports\Inventory\StockValuationReport;
use App\Reports\Inventory\PurchaseReport;
use App\Reports\Inventory\SupplierPerformanceReport;
use App\Reports\Inventory\StockTransferReport;
// Financial reports (Step 4)
use App\Reports\Financial\ProfitLossReport;
use App\Reports\Financial\CashFlowReport;
use App\Reports\Financial\DailySummaryReport;
use App\Reports\Financial\ExpensesReport;
use App\Reports\Financial\PaymentReconciliationReport;
// Customer reports (Step 5)
use App\Reports\Customer\CustomerActivityReport;
use App\Reports\Customer\NewCustomersReport;
use App\Reports\Customer\InactiveCustomersReport;
use App\Reports\Customer\CustomerLifetimeValueReport;
use App\Reports\Customer\LoyaltyOverviewReport;
use App\Reports\Customer\CreditAgingReport;
use App\Reports\Customer\CustomerGroupPerformanceReport;
// Tax reports (Step 6)
use App\Reports\Tax\TaxCollectedReport;
use App\Reports\Tax\TaxByRateReport;
use App\Reports\Tax\ProductTaxBreakdownReport;
use App\Reports\Tax\TaxExemptSalesReport;
// Admin reports (Step 9)
use App\Reports\Admin\PlatformRevenueReport;
use App\Reports\Admin\StoresHealthReport;
use App\Reports\Admin\StoresAtRiskReport;
use App\Reports\Admin\ChurnReport;
use App\Reports\Admin\PlanMigrationReport;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Registry and resolver for all available reports.
 * Add new report classes here after creating them.
 */
class ReportManager
{
    /**
     * Map of slug => fully-qualified report class name.
     * Populated progressively as reports are implemented.
     */
    private array $registry = [
        // ── Test (Step 1) ─────────────────────────────────────────────────────
        'test-report' => SimpleTestReport::class,

        // ── Sales (Step 2) ───────────────────────────────────────────────────
        'sales-summary'        => SalesSummaryReport::class,
        'sales-by-day'         => SalesByDayReport::class,
        'sales-by-hour'        => SalesByHourReport::class,
        'sales-by-product'     => SalesByProductReport::class,
        'sales-by-category'    => SalesByCategoryReport::class,
        'sales-by-brand'       => SalesByBrandReport::class,
        'sales-by-cashier'     => SalesByCashierReport::class,
        'sales-by-payment'     => SalesByPaymentMethodReport::class,
        'sales-by-customer'    => SalesByCustomerReport::class,
        'discount-analysis'    => DiscountReport::class,
        'returns-analysis'     => ReturnsReport::class,

        // ── Inventory (Step 3) ───────────────────────────────────────────────
        'stock-on-hand'         => StockOnHandReport::class,
        'low-stock'             => LowStockReport::class,
        'out-of-stock'          => OutOfStockReport::class,
        'stock-movement'        => StockMovementReport::class,
        'stock-aging'           => StockAgingReport::class,
        'stock-valuation'       => StockValuationReport::class,
        'purchase-analysis'     => PurchaseReport::class,
        'supplier-performance'  => SupplierPerformanceReport::class,
        'stock-transfers'       => StockTransferReport::class,

        // ── Financial (Step 4) ───────────────────────────────────────────────
        'profit-loss'                => ProfitLossReport::class,
        'cash-flow'                  => CashFlowReport::class,
        'daily-summary'              => DailySummaryReport::class,
        'expenses-analysis'          => ExpensesReport::class,
        'payment-reconciliation'     => PaymentReconciliationReport::class,

        // ── Customer (Step 5) ────────────────────────────────────────────────
        'customer-activity'    => CustomerActivityReport::class,
        'new-customers'        => NewCustomersReport::class,
        'inactive-customers'   => InactiveCustomersReport::class,
        'customer-ltv'         => CustomerLifetimeValueReport::class,
        'loyalty-overview'     => LoyaltyOverviewReport::class,
        'credit-aging'         => CreditAgingReport::class,
        'group-performance'    => CustomerGroupPerformanceReport::class,

        // ── Tax (Step 6) ─────────────────────────────────────────────────────
        'tax-collected'           => TaxCollectedReport::class,
        'tax-by-rate'             => TaxByRateReport::class,
        'product-tax-breakdown'   => ProductTaxBreakdownReport::class,
        'tax-exempt-sales'        => TaxExemptSalesReport::class,

        // ── Admin (Step 9) ───────────────────────────────────────────────────
        'admin-platform-revenue' => PlatformRevenueReport::class,
        'admin-stores-health'    => StoresHealthReport::class,
        'admin-stores-at-risk'   => StoresAtRiskReport::class,
        'admin-churn'            => ChurnReport::class,
        'admin-plan-migrations'  => PlanMigrationReport::class,
    ];

    // ── Public API ──────────────────────────────────────────────────────────

    public function get(string $slug): ReportInterface
    {
        if (! isset($this->registry[$slug])) {
            throw new InvalidArgumentException("Report '{$slug}' not found.");
        }

        return app($this->registry[$slug]);
    }

    public function list(?string $category = null): Collection
    {
        return collect($this->registry)
            ->map(function (string $class, string $slug) {
                /** @var ReportInterface $report */
                $report = app($class);
                return [
                    'slug'                => $slug,
                    'name'                => $report->getName(),
                    'category'            => $report->getCategory(),
                    'description'         => method_exists($report, 'getDescription') ? $report->getDescription() : '',
                    'required_module'     => method_exists($report, 'getRequiredModule') ? $report->getRequiredModule() : null,
                    'required_permission' => method_exists($report, 'getRequiredPermission') ? $report->getRequiredPermission() : null,
                ];
            })
            ->when($category, fn ($c) => $c->filter(fn ($r) => $r['category'] === $category))
            ->values();
    }

    public function listGrouped(): array
    {
        return $this->list()
            ->groupBy('category')
            ->map(fn ($items, $cat) => ['category' => $cat, 'reports' => $items->values()])
            ->values()
            ->all();
    }

    public function register(string $slug, string $class): void
    {
        $this->registry[$slug] = $class;
    }

    public function registerMany(array $reports): void
    {
        foreach ($reports as $slug => $class) {
            $this->register($slug, $class);
        }
    }

    public function has(string $slug): bool
    {
        return isset($this->registry[$slug]);
    }
}
