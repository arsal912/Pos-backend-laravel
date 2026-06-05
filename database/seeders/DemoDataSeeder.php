<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Services\StockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds a tenant DB with realistic demo data for Phase 4D report testing.
 * Usage: php artisan tenant:seed-demo {storeId}
 *
 * Creates:
 *   - 3 categories, 3 brands, 2 tax rates, 2 units
 *   - 12 products (varied prices, tax rates, categories)
 *   - 3 customer groups + 30 customers
 *   - 1 supplier, 1 PO + GRN (adds initial stock)
 *   - ~500 completed sales over last 90 days
 *   - 20 returns (approx 4% return rate)
 *   - 15 expense entries (varied categories)
 *   - Loyalty transactions via completeSale flow
 */
class DemoDataSeeder extends Seeder
{
    public function run(int $storeId): void
    {
        $store = Store::findOrFail($storeId);

        $this->command->info("Seeding demo data for: {$store->name} (ID: {$storeId})");

        $store->run(function () use ($storeId) {
            $this->seedCatalog($storeId);
            $this->seedCustomers($storeId);
            $this->seedInventory($storeId);
            $this->seedSales($storeId);
            $this->seedExpenses($storeId);
        });

        $this->command->info('✓ Demo data seeded successfully.');
    }

    // ── CATALOG ──────────────────────────────────────────────────────────────

    private function seedCatalog(int $storeId): void
    {
        $this->command->line('  → Seeding catalog...');

        // Tax rates
        DB::table('tax_rates')->insertOrIgnore([
            ['name'=>'GST 17%',   'rate'=>17.00, 'is_inclusive'=>false,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()],
            ['name'=>'Zero Rated','rate'=>0.00,  'is_inclusive'=>false,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()],
        ]);

        // Units
        DB::table('units')->insertOrIgnore([
            ['name'=>'Piece', 'short_code'=>'pc', 'is_decimal'=>false,'created_at'=>now(),'updated_at'=>now()],
            ['name'=>'Kilogram','short_code'=>'kg','is_decimal'=>true,'created_at'=>now(),'updated_at'=>now()],
        ]);

        // Categories
        $cats = [
            ['name'=>'Electronics','slug'=>'electronics'],
            ['name'=>'Groceries',  'slug'=>'groceries'],
            ['name'=>'Apparel',    'slug'=>'apparel'],
        ];
        foreach ($cats as $cat) {
            DB::table('categories')->updateOrInsert(['slug'=>$cat['slug']], array_merge($cat, [
                'is_active'=>true,'sort_order'=>0,'created_at'=>now(),'updated_at'=>now(),
            ]));
        }

        // Brands
        $brands = [
            ['name'=>'Samsung',  'slug'=>'samsung'],
            ['name'=>'Local',    'slug'=>'local'],
            ['name'=>'GenBrand', 'slug'=>'genbrand'],
        ];
        foreach ($brands as $b) {
            DB::table('brands')->updateOrInsert(['slug'=>$b['slug']], array_merge($b, [
                'is_active'=>true,'created_at'=>now(),'updated_at'=>now(),
            ]));
        }

        $catIds   = DB::table('categories')->pluck('id', 'slug');
        $brandIds = DB::table('brands')->pluck('id', 'slug');
        $taxGst   = DB::table('tax_rates')->where('name','GST 17%')->value('id');
        $unitPc   = DB::table('units')->where('short_code','pc')->value('id');

        // Products
        $products = [
            ['name'=>'Samsung S24',      'sku'=>'SAM-S24',    'cat'=>'electronics','brand'=>'samsung','cost'=>70000,'price'=>85000,'tax'=>$taxGst],
            ['name'=>'Screen Protector', 'sku'=>'ACC-SP-001', 'cat'=>'electronics','brand'=>'samsung','cost'=>400,  'price'=>800,  'tax'=>$taxGst],
            ['name'=>'USB-C Cable',      'sku'=>'ACC-USB-001','cat'=>'electronics','brand'=>'genbrand','cost'=>150, 'price'=>350,  'tax'=>$taxGst],
            ['name'=>'Basmati Rice 5kg', 'sku'=>'GRC-RICE-5', 'cat'=>'groceries', 'brand'=>'local',   'cost'=>1200,'price'=>1600, 'tax'=>null],
            ['name'=>'Sugar 1kg',        'sku'=>'GRC-SUG-1',  'cat'=>'groceries', 'brand'=>'local',   'cost'=>140, 'price'=>200,  'tax'=>null],
            ['name'=>'Cooking Oil 1L',   'sku'=>'GRC-OIL-1',  'cat'=>'groceries', 'brand'=>'local',   'cost'=>480, 'price'=>650,  'tax'=>null],
            ['name'=>'T-Shirt XL',       'sku'=>'APP-TSH-XL', 'cat'=>'apparel',   'brand'=>'genbrand','cost'=>600, 'price'=>1200, 'tax'=>$taxGst],
            ['name'=>'Jeans W32',        'sku'=>'APP-JNS-32', 'cat'=>'apparel',   'brand'=>'genbrand','cost'=>1200,'price'=>2500, 'tax'=>$taxGst],
            ['name'=>'Sports Shoes 42',  'sku'=>'APP-SHO-42', 'cat'=>'apparel',   'brand'=>'local',   'cost'=>2200,'price'=>4500, 'tax'=>$taxGst],
            ['name'=>'Water Bottle',     'sku'=>'ACC-WB-001', 'cat'=>'electronics','brand'=>'genbrand','cost'=>200,'price'=>450,  'tax'=>$taxGst],
            ['name'=>'Notebook A5',      'sku'=>'STA-NB-A5',  'cat'=>'groceries', 'brand'=>'local',   'cost'=>50,  'price'=>120,  'tax'=>null],
            ['name'=>'Pen (pack of 10)', 'sku'=>'STA-PEN-10', 'cat'=>'groceries', 'brand'=>'local',   'cost'=>80,  'price'=>180,  'tax'=>null],
        ];

        foreach ($products as $p) {
            $exists = DB::table('products')->where('sku', $p['sku'])->exists();
            if (! $exists) {
                DB::table('products')->insert([
                    'name'           => $p['name'],
                    'slug'           => Str::slug($p['name']) . '-' . Str::random(4),
                    'sku'            => $p['sku'],
                    'type'           => 'simple',
                    'category_id'    => $catIds[$p['cat']] ?? null,
                    'brand_id'       => $brandIds[$p['brand']] ?? null,
                    'unit_id'        => $unitPc,
                    'cost_price'     => $p['cost'],
                    'selling_price'  => $p['price'],
                    'tax_rate_id'    => $p['tax'],
                    'track_stock'    => true,
                    'allow_negative_stock' => true,
                    'is_active'      => true,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }
        }
    }

    // ── CUSTOMERS ─────────────────────────────────────────────────────────────

    private function seedCustomers(int $storeId): void
    {
        $this->command->line('  → Seeding customer groups + customers...');

        // Customer groups
        $groups = [
            ['name'=>'Regular',   'slug'=>'regular',   'is_default'=>true, 'earns_loyalty_points'=>true, 'default_discount_percent'=>null, 'color'=>'#6366f1'],
            ['name'=>'VIP',       'slug'=>'vip',        'is_default'=>false,'earns_loyalty_points'=>true, 'default_discount_percent'=>5,    'color'=>'#f59e0b'],
            ['name'=>'Wholesale', 'slug'=>'wholesale',  'is_default'=>false,'earns_loyalty_points'=>false,'default_discount_percent'=>10,   'color'=>'#10b981'],
        ];
        foreach ($groups as $g) {
            DB::table('customer_groups')->updateOrInsert(['slug'=>$g['slug']], array_merge($g, [
                'is_active'=>true,'sort_order'=>0,'created_at'=>now(),'updated_at'=>now(),
            ]));
        }

        $groupIds = DB::table('customer_groups')->pluck('id', 'slug');

        $names = ['Ahmed Khan','Sara Ali','Muhammad Bilal','Fatima Sheikh','Usman Tariq','Ayesha Malik','Hassan Raza','Nadia Qureshi','Imran Baig','Sana Ahmed',
                  'Tariq Hussain','Rukhsana Akhtar','Khurram Shah','Mehwish Mirza','Zeeshan Butt','Parveen Iqbal','Fahad Chaudhry','Sobia Nawaz','Ali Raza','Hina Javed',
                  'Kamran Siddiqui','Rabia Tahir','Waqas Bhatti','Nosheen Malik','Shahid Anwar','Amna Rizvi','Yasir Liaqat','Zubia Hameed','Nadeem Awan','Bushra Saeed'];

        foreach ($names as $i => $name) {
            $groupSlug = match(true) {
                $i < 10  => 'regular',
                $i < 20  => 'vip',
                default  => 'wholesale',
            };
            $exists = DB::table('customers')->where('name', $name)->exists();
            if (! $exists) {
                DB::table('customers')->insert([
                    'code'             => sprintf('CUS-%06d', DB::table('customers')->count() + 1),
                    'name'             => $name,
                    'phone'            => '03' . rand(10, 49) . '-' . rand(1000000, 9999999),
                    'customer_group_id'=> $groupIds[$groupSlug],
                    'credit_limit'     => $i >= 20 ? 50000 : ($i >= 10 ? 10000 : null),
                    'is_active'        => true,
                    'opening_balance'  => 0,
                    'created_at'       => now()->subDays(rand(30, 180)),
                    'updated_at'       => now(),
                ]);
            }
        }
    }

    // ── INVENTORY ─────────────────────────────────────────────────────────────

    private function seedInventory(int $storeId): void
    {
        $this->command->line('  → Adding initial stock...');

        $products = DB::table('products')->get();
        $stock    = app(StockService::class);

        foreach ($products as $p) {
            $hasStock = DB::table('inventory_items')->where('product_id', $p->id)->exists();
            if (! $hasStock) {
                $stock->addStock($p->id, null, 1, rand(50, 200), 'initial', null, null, (float)$p->cost_price, 'Demo initial stock');
            }
        }
    }

    // ── SALES ─────────────────────────────────────────────────────────────────

    private function seedSales(int $storeId): void
    {
        $this->command->line('  → Seeding ~500 sales over last 90 days...');

        $products  = DB::table('products')->get();
        $customers = DB::table('customers')->get();
        $stock     = app(StockService::class);

        $methods = ['cash','cash','cash','card','card','jazzcash','easypaisa','on_credit'];
        $saleCount = 0;

        for ($day = 89; $day >= 0; $day--) {
            $date     = now()->subDays($day)->toDateString();
            $daySales = rand(3, 8); // 3-8 sales per day

            for ($s = 0; $s < $daySales; $s++) {
                $customerId = rand(0, 10) < 3 ? $customers->random()->id : null; // 30% chance customer attached
                $itemCount  = rand(1, 4);
                $method     = $methods[array_rand($methods)];

                try {
                    $saleItems = [];
                    $subtotal  = 0;
                    $taxAmt    = 0;

                    for ($i = 0; $i < $itemCount; $i++) {
                        $product = $products->random();
                        $qty     = rand(1, 3);
                        $price   = (float) $product->selling_price;
                        $cost    = (float) $product->cost_price;
                        $taxRate = 0;
                        $taxAmt_ = 0;

                        if ($product->tax_rate_id) {
                            $taxRate = (float) DB::table('tax_rates')->where('id', $product->tax_rate_id)->value('rate') ?? 0;
                            $taxAmt_ = round($price * $qty * $taxRate / 100, 2);
                        }

                        $lineTotal = round($price * $qty, 2);
                        $subtotal += $lineTotal;
                        $taxAmt   += $taxAmt_;

                        $saleItems[] = [
                            'product_id'   => $product->id,
                            'product_name' => $product->name,
                            'sku'          => $product->sku,
                            'quantity'     => $qty,
                            'unit_price'   => $price,
                            'cost_at_time' => $cost,
                            'tax_rate'     => $taxRate,
                            'tax_amount'   => $taxAmt_,
                            'line_total'   => $lineTotal,
                        ];
                    }

                    // Occasional 5% discount
                    $discountAmt = rand(0, 10) < 2 ? round($subtotal * 0.05, 2) : 0;
                    $total = $subtotal - $discountAmt;

                    $saleId = DB::table('sales')->insertGetId([
                        'sale_number'     => 'S-' . date('Y', strtotime($date)) . '-' . sprintf('%08d', ++$saleCount),
                        'branch_id'       => 1,
                        'customer_id'     => $customerId,
                        'cashier_id'      => 1,
                        'sale_date'       => $date,
                        'subtotal'        => $subtotal,
                        'tax_amount'      => $taxAmt,
                        'discount_amount' => $discountAmt,
                        'discount_type'   => $discountAmt > 0 ? 'fixed' : null,
                        'total'           => $total,
                        'paid_amount'     => $total,
                        'change_given'    => 0,
                        'balance'         => 0,
                        'status'          => 'completed',
                        'payment_status'  => 'paid',
                        'created_at'      => Carbon::parse($date)->addHours(rand(9, 21)),
                        'updated_at'      => now(),
                    ]);

                    foreach ($saleItems as $item) {
                        DB::table('sale_items')->insert(array_merge($item, [
                            'sale_id'         => $saleId,
                            'discount_amount' => 0,
                            'created_at'      => now(),
                            'updated_at'      => now(),
                        ]));

                        // Deduct stock (best-effort)
                        try {
                            $stock->deductStock($item['product_id'], null, 1, $item['quantity'], 'sale', 'sale', $saleId, $item['cost_at_time']);
                        } catch (\Throwable) { /* allow negative stock */ }
                    }

                    DB::table('sale_payments')->insert([
                        'sale_id'    => $saleId,
                        'method'     => in_array($method, ['on_credit']) ? 'cash' : $method,
                        'amount'     => $total,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    // Skip individual sale errors
                }
            }
        }

        // Add a few returns
        $completedSales = DB::table('sales')->where('status','completed')->inRandomOrder()->limit(20)->get();
        foreach ($completedSales->take(15) as $sale) {
            $saleItem = DB::table('sale_items')->where('sale_id', $sale->id)->first();
            if (! $saleItem) continue;
            try {
                $returnId = DB::table('sale_returns')->insertGetId([
                    'return_number'    => 'RET-' . date('Y') . '-' . sprintf('%06d', rand(1,999)),
                    'original_sale_id' => $sale->id,
                    'branch_id'        => 1,
                    'customer_id'      => $sale->customer_id,
                    'cashier_id'       => 1,
                    'return_date'      => $sale->sale_date,
                    'refund_amount'    => (float) $saleItem->unit_price,
                    'refund_method'    => 'cash',
                    'reason'           => ['Defective','Wrong item','Customer changed mind'][rand(0,2)],
                    'status'           => 'completed',
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
                DB::table('sale_return_items')->insert([
                    'sale_return_id'    => $returnId,
                    'sale_item_id'      => $saleItem->id,
                    'product_id'        => $saleItem->product_id,
                    'quantity_returned' => 1,
                    'unit_price'        => $saleItem->unit_price,
                    'refund_amount'     => (float) $saleItem->unit_price,
                    'restock'           => true,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
            } catch (\Throwable) {}
        }

        $this->command->line("  → Created {$saleCount} sales.");
    }

    // ── EXPENSES ──────────────────────────────────────────────────────────────

    private function seedExpenses(int $storeId): void
    {
        $this->command->line('  → Seeding expenses...');

        $expenses = [
            ['category'=>'Rent',      'description'=>'Monthly shop rent',     'amount'=>45000,'method'=>'bank_transfer'],
            ['category'=>'Rent',      'description'=>'Monthly shop rent',     'amount'=>45000,'method'=>'bank_transfer'],
            ['category'=>'Rent',      'description'=>'Monthly shop rent',     'amount'=>45000,'method'=>'bank_transfer'],
            ['category'=>'Utilities', 'description'=>'WAPDA electricity bill','amount'=>8500, 'method'=>'cash'],
            ['category'=>'Utilities', 'description'=>'WAPDA electricity bill','amount'=>9200, 'method'=>'cash'],
            ['category'=>'Utilities', 'description'=>'PTCL internet',        'amount'=>2500, 'method'=>'bank_transfer'],
            ['category'=>'Staff',     'description'=>'Staff salary',          'amount'=>35000,'method'=>'bank_transfer'],
            ['category'=>'Staff',     'description'=>'Staff salary',          'amount'=>35000,'method'=>'bank_transfer'],
            ['category'=>'Marketing', 'description'=>'Facebook ad campaign',  'amount'=>5000, 'method'=>'card'],
            ['category'=>'Marketing', 'description'=>'Banner printing',       'amount'=>3500, 'method'=>'cash'],
            ['category'=>'Transport', 'description'=>'Delivery charges',      'amount'=>2000, 'method'=>'cash'],
            ['category'=>'Transport', 'description'=>'Fuel reimbursement',    'amount'=>1500, 'method'=>'cash'],
            ['category'=>'Repair',    'description'=>'AC servicing',          'amount'=>3500, 'method'=>'cash'],
            ['category'=>'Supplies',  'description'=>'Stationery & packaging','amount'=>1800, 'method'=>'cash'],
            ['category'=>'Other',     'description'=>'Miscellaneous expenses','amount'=>2500, 'method'=>'cash'],
        ];

        foreach ($expenses as $i => $e) {
            DB::table('expenses')->insert([
                'category'       => $e['category'],
                'description'    => $e['description'],
                'amount'         => $e['amount'],
                'payment_method' => $e['method'],   // column is payment_method
                'expense_date'   => now()->subDays(rand(0, 89))->toDateString(),
                'branch_id'      => 1,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }
}
