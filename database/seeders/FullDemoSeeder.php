<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Expense;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\DatabaseManager as TenancyDatabaseManager;

/**
 * Full Demo Seeder — creates a complete demo store with realistic data.
 *
 * Usage:
 *   php artisan db:seed --class=FullDemoSeeder
 *
 * Creates:
 *   - 1 demo store with owner account
 *   - 5 categories, 5 brands, 3 units, 2 tax rates
 *   - 15 products with inventory stock
 *   - 3 suppliers with 5 purchase orders
 *   - 2 customer groups + 15 customers
 *   - 20 completed sales
 *   - 10 expenses
 */
class FullDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🚀 Starting Full Demo Seeder...');

        // ── 1. Create demo store ───────────────────────────────────────────────

        $email = 'demo@demostore.com';
        if (User::where('email', $email)->exists()) {
            $this->command->warn("Demo store already exists (user: {$email}). Skipping.");
            return;
        }

        $store = Store::create([
            'name'          => 'Demo Store PK',
            'slug'          => 'demo-store-pk',
            'business_type' => 'retail',
            'email'         => 'contact@demostore.com',
            'phone'         => '+92 300 1234567',
            'address'       => 'Shop 12, Main Market, Gulberg',
            'city'          => 'Lahore',
            'country'       => 'PK',
            'currency'      => 'PKR',
            'timezone'      => 'Asia/Karachi',
            'status'        => 'active',
            'is_active'     => true,
            'trial_ends_at' => now()->addDays(30),
        ]);

        $this->command->info("✓ Store created: {$store->name} (ID: {$store->id})");

        // ── 2. Create owner user ───────────────────────────────────────────────

        $owner = User::create([
            'name'               => 'Demo Owner',
            'email'              => $email,
            'password'           => 'password',
            'phone'              => '+92 300 1234567',
            'store_id'           => $store->id,
            'is_active'          => true,
            'email_verified_at'  => now(),
        ]);
        $owner->assignRole('store-owner');

        // Cashier user
        $cashier = User::create([
            'name'               => 'Demo Cashier',
            'email'              => 'cashier@demostore.com',
            'password'           => 'password',
            'phone'              => '+92 301 9876543',
            'store_id'           => $store->id,
            'is_active'          => true,
            'email_verified_at'  => now(),
        ]);
        $cashier->assignRole('cashier');

        $this->command->info("✓ Users created: {$email} / password");

        // ── 3. Subscription ────────────────────────────────────────────────────

        $plan = Plan::where('is_active', true)->orderBy('price')->first();
        if ($plan) {
            Subscription::create([
                'store_id'      => $store->id,
                'plan_id'       => $plan->id,
                'status'        => 'active',
                'starts_at'     => now(),
                'ends_at'       => now()->addYear(),
                'amount'        => $plan->price,
                'currency'      => 'PKR',
                'billing_cycle' => $plan->billing_cycle ?? 'monthly',
            ]);
        }

        // ── 4. Create tenant database ──────────────────────────────────────────

        $this->command->info('Creating tenant database...');
        $store->database()->makeCredentials();
        app(TenancyDatabaseManager::class)->ensureTenantCanBeCreated($store);
        $store->database()->manager()->createDatabase($store);

        Artisan::call('tenants:migrate', [
            '--tenants' => [$store->getTenantKey()],
            '--force'   => true,
        ]);
        $this->command->info('✓ Tenant database created and migrated');

        // ── 5. Seed tenant data ────────────────────────────────────────────────

        $store->run(function () use ($store, $owner, $cashier) {
            $this->seedTenantData($store, $owner, $cashier);
        });

        $this->command->info('');
        $this->command->info('✅ Demo seeder complete!');
        $this->command->info('');
        $this->command->table(
            ['Field', 'Value'],
            [
                ['Store',          "Demo Store PK (ID: {$store->id})"],
                ['Owner Email',    'demo@demostore.com'],
                ['Owner Password', 'password'],
                ['Cashier Email',  'cashier@demostore.com'],
                ['Cashier Pass',   'password'],
                ['Frontend URL',   'http://localhost:3000/login'],
            ]
        );
    }

    // ── Tenant data ────────────────────────────────────────────────────────────

    private function seedTenantData(Store $store, User $owner, User $cashier): void
    {
        $this->command->info('Seeding tenant data...');

        // ── Branch ────────────────────────────────────────────────────────────
        $branch = Branch::create([
            'store_id' => $store->id,
            'name'     => 'Main Branch',
            'code'     => 'MAIN',
            'is_main'  => true,
            'is_active'=> true,
        ]);

        // Update users with branch — must use central DB connection explicitly
        DB::connection('mysql')->table('users')
            ->whereIn('id', [$owner->id, $cashier->id])
            ->update(['branch_id' => $branch->id]);

        // ── Categories ────────────────────────────────────────────────────────
        $categories = collect([
            ['name' => 'Electronics',    'slug' => 'electronics'],
            ['name' => 'Clothing',       'slug' => 'clothing'],
            ['name' => 'Food & Grocery', 'slug' => 'food-grocery'],
            ['name' => 'Stationery',     'slug' => 'stationery'],
            ['name' => 'Mobile & Accessories', 'slug' => 'mobile-accessories'],
        ])->map(fn ($c) => Category::create(array_merge($c, ['is_active' => true, 'sort_order' => 0])));

        $this->command->info('  ✓ 5 categories');

        // ── Brands ────────────────────────────────────────────────────────────
        $brands = collect([
            ['name' => 'Samsung',    'slug' => 'samsung'],
            ['name' => 'Apple',      'slug' => 'apple'],
            ['name' => 'Dawlance',   'slug' => 'dawlance'],
            ['name' => 'Gul Ahmed',  'slug' => 'gul-ahmed'],
            ['name' => 'Nestle',     'slug' => 'nestle'],
        ])->map(fn ($b) => Brand::create(array_merge($b, ['is_active' => true])));

        $this->command->info('  ✓ 5 brands');

        // ── Units ─────────────────────────────────────────────────────────────
        $unitPcs = Unit::create(['name' => 'Piece',     'short_code' => 'pcs', 'is_decimal' => false]);
        $unitKg  = Unit::create(['name' => 'Kilogram',  'short_code' => 'kg',  'is_decimal' => true]);
        $unitLtr = Unit::create(['name' => 'Litre',     'short_code' => 'ltr', 'is_decimal' => true]);

        $this->command->info('  ✓ 3 units');

        // ── Tax rates ─────────────────────────────────────────────────────────
        $tax0  = TaxRate::create(['name' => 'Zero Rated',     'rate' => 0,  'is_inclusive' => false, 'is_active' => true]);
        $tax17 = TaxRate::create(['name' => 'Standard (17%)', 'rate' => 17, 'is_inclusive' => false, 'is_active' => true]);
        $tax5  = TaxRate::create(['name' => 'Reduced (5%)',   'rate' => 5,  'is_inclusive' => false, 'is_active' => true]);

        $this->command->info('  ✓ 3 tax rates');

        // ── Products ──────────────────────────────────────────────────────────
        $productsData = [
            // Electronics
            ['name' => 'Samsung Galaxy A54',      'sku' => 'MOB-001', 'cat' => 0, 'brand' => 0, 'price' => 89000,  'cost' => 75000,  'tax' => $tax17, 'unit' => $unitPcs, 'stock' => 25, 'threshold' => 5],
            ['name' => 'iPhone 14',               'sku' => 'MOB-002', 'cat' => 0, 'brand' => 1, 'price' => 220000, 'cost' => 185000, 'tax' => $tax17, 'unit' => $unitPcs, 'stock' => 10, 'threshold' => 3],
            ['name' => 'Samsung 55" Smart TV',    'sku' => 'TV-001',  'cat' => 0, 'brand' => 0, 'price' => 145000, 'cost' => 120000, 'tax' => $tax17, 'unit' => $unitPcs, 'stock' => 8,  'threshold' => 2],
            ['name' => 'Dawlance Refrigerator',   'sku' => 'REF-001', 'cat' => 0, 'brand' => 2, 'price' => 95000,  'cost' => 78000,  'tax' => $tax17, 'unit' => $unitPcs, 'stock' => 5,  'threshold' => 2],
            // Clothing
            ['name' => 'Gul Ahmed Lawn Suit',     'sku' => 'CLO-001', 'cat' => 1, 'brand' => 3, 'price' => 3500,   'cost' => 2200,   'tax' => $tax0,  'unit' => $unitPcs, 'stock' => 50, 'threshold' => 10],
            ['name' => 'Men\'s Formal Shirt',     'sku' => 'CLO-002', 'cat' => 1, 'brand' => 3, 'price' => 1800,   'cost' => 1100,   'tax' => $tax0,  'unit' => $unitPcs, 'stock' => 40, 'threshold' => 10],
            ['name' => 'Kids T-Shirt Pack (3)',   'sku' => 'CLO-003', 'cat' => 1, 'brand' => 3, 'price' => 1200,   'cost' => 750,    'tax' => $tax0,  'unit' => $unitPcs, 'stock' => 35, 'threshold' => 10],
            // Food & Grocery
            ['name' => 'Nestle Milkpak 1L',       'sku' => 'FD-001',  'cat' => 2, 'brand' => 4, 'price' => 360,    'cost' => 290,    'tax' => $tax0,  'unit' => $unitLtr, 'stock' => 200,'threshold' => 20],
            ['name' => 'Basmati Rice 5kg',         'sku' => 'FD-002',  'cat' => 2, 'brand' => null, 'price' => 1650,'cost' => 1300,   'tax' => $tax0,  'unit' => $unitKg,  'stock' => 100,'threshold' => 20],
            ['name' => 'Cooking Oil 5L',           'sku' => 'FD-003',  'cat' => 2, 'brand' => null, 'price' => 2800,'cost' => 2300,   'tax' => $tax5,  'unit' => $unitLtr, 'stock' => 60, 'threshold' => 10],
            // Stationery
            ['name' => 'A4 Paper Ream (500 sheets)','sku' => 'ST-001', 'cat' => 3, 'brand' => null, 'price' => 1100,'cost' => 800,    'tax' => $tax17, 'unit' => $unitPcs, 'stock' => 30, 'threshold' => 5],
            ['name' => 'Ball Point Pen Box (50)',   'sku' => 'ST-002', 'cat' => 3, 'brand' => null, 'price' => 550, 'cost' => 380,    'tax' => $tax17, 'unit' => $unitPcs, 'stock' => 25, 'threshold' => 5],
            // Mobile Accessories
            ['name' => 'USB-C Charging Cable',     'sku' => 'ACC-001', 'cat' => 4, 'brand' => null, 'price' => 450, 'cost' => 250,    'tax' => $tax17, 'unit' => $unitPcs, 'stock' => 80, 'threshold' => 15],
            ['name' => 'Screen Protector (Universal)','sku' => 'ACC-002','cat' => 4,'brand' => null, 'price' => 250, 'cost' => 120,    'tax' => $tax17, 'unit' => $unitPcs, 'stock' => 60, 'threshold' => 10],
            ['name' => 'Power Bank 10000mAh',      'sku' => 'ACC-003', 'cat' => 4, 'brand' => 0,    'price' => 3500,'cost' => 2500,   'tax' => $tax17, 'unit' => $unitPcs, 'stock' => 15, 'threshold' => 5],
        ];

        $products = [];
        $stock    = app(StockService::class);

        foreach ($productsData as $pd) {
            $product = Product::create([
                'name'                => $pd['name'],
                'slug'                => Str::slug($pd['name']).'-'.Str::random(4),
                'sku'                 => $pd['sku'],
                'category_id'        => $categories[$pd['cat']]->id,
                'brand_id'           => $pd['brand'] !== null ? $brands[$pd['brand']]->id : null,
                'unit_id'            => $pd['unit']->id,
                'tax_rate_id'        => $pd['tax']->id,
                'selling_price'      => $pd['price'],
                'cost_price'         => $pd['cost'],
                'type'               => 'simple',
                'track_stock'        => true,
                'allow_negative_stock'=> false,
                'low_stock_threshold' => $pd['threshold'],
                'is_active'          => true,
                'created_by'         => $owner->id,
            ]);

            // Add opening stock
            $stock->addStock(
                $product->id, null, $branch->id,
                $pd['stock'], 'initial', null, null,
                $pd['cost'], 'Opening stock — demo seeder'
            );

            $products[] = $product;
        }

        $this->command->info('  ✓ 15 products with stock');

        // ── Suppliers ─────────────────────────────────────────────────────────
        $suppliersData = [
            ['name' => 'TechWorld Distributors',  'email' => 'tech@techworld.pk',    'phone' => '+92 42 1234567', 'city' => 'Lahore'],
            ['name' => 'Fashion Hub Wholesale',   'email' => 'info@fashionhub.pk',   'phone' => '+92 21 9876543', 'city' => 'Karachi'],
            ['name' => 'National Food Suppliers', 'email' => 'orders@nfs.pk',        'phone' => '+92 51 5554321', 'city' => 'Islamabad'],
            ['name' => 'Office Plus Supply',      'email' => 'sales@officeplus.pk',  'phone' => '+92 42 7890123', 'city' => 'Lahore'],
            ['name' => 'Mobile Zone Wholesale',   'email' => 'info@mobilezone.pk',   'phone' => '+92 300 5550000', 'city' => 'Lahore'],
        ];

        array_map(fn ($s) => Supplier::create(array_merge($s, ['is_active' => true])), $suppliersData);

        $this->command->info('  ✓ 5 suppliers');

        // ── Customer Groups ───────────────────────────────────────────────────
        $groupRegular = CustomerGroup::create([
            'name'                    => 'Regular',
            'slug'                    => 'regular',
            'default_discount_percent'=> 0,
            'earns_loyalty_points'    => true,
            'is_default'              => true,
            'is_active'               => true,
            'color'                   => '#6366f1',
        ]);
        $groupVip = CustomerGroup::create([
            'name'                    => 'VIP',
            'slug'                    => 'vip',
            'default_discount_percent'=> 10,
            'earns_loyalty_points'    => true,
            'is_default'              => false,
            'is_active'               => true,
            'color'                   => '#f59e0b',
        ]);
        $groupWholesale = CustomerGroup::create([
            'name'                    => 'Wholesale',
            'slug'                    => 'wholesale',
            'default_discount_percent'=> 15,
            'earns_loyalty_points'    => false,
            'is_default'              => false,
            'is_active'               => true,
            'color'                   => '#10b981',
        ]);

        $this->command->info('  ✓ 3 customer groups');

        // ── Customers ─────────────────────────────────────────────────────────
        $customersData = [
            ['name' => 'Ahmed Hassan',      'phone' => '03001111001', 'email' => 'ahmed@email.com',   'city' => 'Lahore',    'group' => $groupRegular, 'credit' => 5000,  'points' => 150],
            ['name' => 'Sara Malik',        'phone' => '03002222002', 'email' => 'sara@email.com',    'city' => 'Karachi',   'group' => $groupVip,     'credit' => 20000, 'points' => 1200],
            ['name' => 'Muhammad Ali',      'phone' => '03003333003', 'email' => 'mali@email.com',    'city' => 'Islamabad', 'group' => $groupRegular, 'credit' => 3000,  'points' => 75],
            ['name' => 'Fatima Khan',       'phone' => '03004444004', 'email' => 'fatima@email.com',  'city' => 'Lahore',    'group' => $groupVip,     'credit' => 15000, 'points' => 850],
            ['name' => 'Ali Raza',          'phone' => '03005555005', 'email' => 'ali@email.com',     'city' => 'Lahore',    'group' => $groupRegular, 'credit' => 0,     'points' => 0],
            ['name' => 'Ayesha Siddiqui',   'phone' => '03006666006', 'email' => 'ayesha@email.com',  'city' => 'Faisalabad','group' => $groupWholesale,'credit'=> 50000, 'points' => 0],
            ['name' => 'Bilal Ahmed',       'phone' => '03007777007', 'email' => 'bilal@email.com',   'city' => 'Lahore',    'group' => $groupRegular, 'credit' => 2000,  'points' => 200],
            ['name' => 'Zainab Hussain',    'phone' => '03008888008', 'email' => 'zainab@email.com',  'city' => 'Karachi',   'group' => $groupVip,     'credit' => 25000, 'points' => 2000],
            ['name' => 'Omar Farooq',       'phone' => '03009999009', 'email' => 'omar@email.com',    'city' => 'Islamabad', 'group' => $groupRegular, 'credit' => 1000,  'points' => 50],
            ['name' => 'Hira Baig',         'phone' => '03010101010', 'email' => 'hira@email.com',    'city' => 'Lahore',    'group' => $groupRegular, 'credit' => 5000,  'points' => 300],
            ['name' => 'Tariq Mehmood',     'phone' => '03021212121', 'email' => 'tariq@email.com',   'city' => 'Multan',    'group' => $groupWholesale,'credit'=> 75000, 'points' => 0],
            ['name' => 'Nadia Iqbal',       'phone' => '03032323232', 'email' => 'nadia@email.com',   'city' => 'Lahore',    'group' => $groupVip,     'credit' => 10000, 'points' => 600],
            ['name' => 'Kamran Shahid',     'phone' => '03043434343', 'email' => 'kamran@email.com',  'city' => 'Rawalpindi','group' => $groupRegular, 'credit' => 3000,  'points' => 120],
            ['name' => 'Sana Nawaz',        'phone' => '03054545454', 'email' => 'sana@email.com',    'city' => 'Lahore',    'group' => $groupRegular, 'credit' => 0,     'points' => 0],
            ['name' => 'Usman Ghani',       'phone' => '03065656565', 'email' => 'usman@email.com',   'city' => 'Karachi',   'group' => $groupWholesale,'credit'=> 100000,'points' => 0],
        ];

        $customers = [];
        $counter   = 1;
        foreach ($customersData as $cd) {
            $customers[] = Customer::create([
                'code'                    => sprintf('CUS-%06d', $counter++),
                'name'                    => $cd['name'],
                'phone'                   => $cd['phone'],
                'email'                   => $cd['email'],
                'city'                    => $cd['city'],
                'country'                 => 'PK',
                'customer_group_id'       => $cd['group']->id,
                'credit_limit'            => $cd['credit'],
                'loyalty_points_balance'  => $cd['points'],
                'outstanding_balance'     => 0,
                'lifetime_value'          => 0,
                'is_active'               => true,
                'sms_marketing_opted_in'  => true,
                'email_marketing_opted_in'=> true,
                'created_by'              => $owner->id,
            ]);
        }

        $this->command->info('  ✓ 15 customers');

        // ── Sales ─────────────────────────────────────────────────────────────
        $this->seedSales($products, $customers, $cashier, $branch->id);
        $this->command->info('  ✓ 20 sales');

        // ── Recalculate customer stats from actual sales ───────────────────────
        Customer::recalculateAllStats();
        $this->command->info('  ✓ Customer stats recalculated');

        // ── Expenses ──────────────────────────────────────────────────────────
        $expenseData = [
            ['category' => 'Rent',       'description' => 'Shop rent — June 2026',          'amount' => 45000,  'method' => 'bank_transfer'],
            ['category' => 'Utilities',  'description' => 'Electricity bill — May 2026',    'amount' => 8500,   'method' => 'cash'],
            ['category' => 'Salary',     'description' => 'Staff salary — May 2026',        'amount' => 35000,  'method' => 'bank_transfer'],
            ['category' => 'Marketing',  'description' => 'Social media ads — June 2026',   'amount' => 5000,   'method' => 'card'],
            ['category' => 'Supplies',   'description' => 'Packaging materials',            'amount' => 2500,   'method' => 'cash'],
            ['category' => 'Utilities',  'description' => 'Internet + phone — June 2026',   'amount' => 3500,   'method' => 'bank_transfer'],
            ['category' => 'Maintenance','description' => 'AC servicing',                   'amount' => 4000,   'method' => 'cash'],
            ['category' => 'Transport',  'description' => 'Delivery van fuel',              'amount' => 6000,   'method' => 'cash'],
            ['category' => 'Salary',     'description' => 'Part-time staff',                'amount' => 15000,  'method' => 'cash'],
            ['category' => 'Other',      'description' => 'Miscellaneous expenses',         'amount' => 1800,   'method' => 'cash'],
        ];

        foreach ($expenseData as $i => $e) {
            Expense::create([
                'expense_date'  => now()->subDays(rand(1, 30)),
                'category'      => $e['category'],
                'description'   => $e['description'],
                'amount'        => $e['amount'],
                'payment_method'=> $e['method'],
                'branch_id'     => $branch->id,
                'created_by'    => $owner->id,
            ]);
        }

        $this->command->info('  ✓ 10 expenses');
    }

    // ── Sales generation ──────────────────────────────────────────────────────

    private function seedSales(array $products, array $customers, User $cashier, int $branchId): void
    {
        $saleNumber = 1;

        foreach (range(1, 20) as $_) {
            $saleDate  = now()->subDays(rand(0, 60));
            $customer  = rand(0, 3) === 0 ? null : $customers[array_rand($customers)];
            $numItems  = rand(1, 4);
            $items     = [];

            // Pick random products
            $picked = (array) array_rand($products, min($numItems, count($products)));
            foreach ($picked as $idx) {
                $p   = $products[$idx];
                $qty = rand(1, 3);
                $items[] = [
                    'product'  => $p,
                    'qty'      => $qty,
                    'price'    => (float) $p->selling_price,
                ];
            }

            $subtotal = array_sum(array_map(fn ($item) => $item['price'] * $item['qty'], $items));
            $discount = rand(0, 2) === 0 ? round($subtotal * 0.05, 2) : 0; // 5% discount sometimes
            $taxAmt   = array_sum(array_map(fn ($item) => ($item['price'] * $item['qty']) * ((float)$item['product']->taxRate?->rate ?? 0) / 100, $items));
            $total    = round($subtotal - $discount + $taxAmt, 2);
            $paid     = $total;
            $method   = ['cash', 'cash', 'cash', 'card'][rand(0, 3)]; // mostly cash

            $sale = DB::table('sales')->insertGetId([
                'sale_number'    => sprintf('S-%s-%08d', date('Y'), $saleNumber++),
                'branch_id'      => $branchId,
                'customer_id'    => $customer?->id,
                'cashier_id'     => $cashier->id,
                'sale_date'      => $saleDate->toDateString(),
                'subtotal'       => $subtotal,
                'tax_amount'     => $taxAmt,
                'discount_amount'=> $discount,
                'discount_type'  => $discount > 0 ? 'fixed' : null,
                'total'          => $total,
                'paid_amount'    => $paid,
                'change_given'   => 0,
                'balance'        => 0,
                'status'         => 'completed',
                'payment_status' => 'paid',
                'created_at'     => $saleDate,
                'updated_at'     => $saleDate,
            ]);

            // Sale items
            foreach ($items as $item) {
                $lineSubtotal = $item['price'] * $item['qty'];
                $lineTax      = $lineSubtotal * ((float)$item['product']->taxRate?->rate ?? 0) / 100;
                DB::table('sale_items')->insert([
                    'sale_id'         => $sale,
                    'product_id'      => $item['product']->id,
                    'variant_id'      => null,
                    'product_name'    => $item['product']->name,
                    'sku'             => $item['product']->sku,
                    'quantity'        => $item['qty'],
                    'unit_price'      => $item['price'],
                    'cost_at_time'    => $item['product']->cost_price,
                    'tax_rate'        => $item['product']->taxRate?->rate ?? 0,
                    'tax_amount'      => round($lineTax, 2),
                    'discount_amount' => 0,
                    'line_total'      => round($lineSubtotal + $lineTax, 2),
                    'created_at'      => $saleDate,
                    'updated_at'      => $saleDate,
                ]);
            }

            // Payment
            DB::table('sale_payments')->insert([
                'sale_id'    => $sale,
                'method'     => $method,
                'amount'     => $paid,
                'reference'  => null,
                'notes'      => null,
                'created_at' => $saleDate,
                'updated_at' => $saleDate,
            ]);
        }
    }
}
