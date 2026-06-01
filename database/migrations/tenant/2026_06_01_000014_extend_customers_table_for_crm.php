<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // D1 — CRM columns
            $table->unsignedBigInteger('customer_group_id')->nullable()->after('credit_limit');
            $table->decimal('loyalty_points_balance', 15, 2)->default(0)->after('customer_group_id');
            $table->decimal('lifetime_value', 15, 2)->default(0)->after('loyalty_points_balance');
            $table->decimal('outstanding_balance', 15, 2)->default(0)->after('lifetime_value'); // D3 denorm
            $table->timestamp('last_purchase_at')->nullable()->after('outstanding_balance');
            $table->unsignedInteger('total_purchases_count')->default(0)->after('last_purchase_at');
            $table->boolean('sms_marketing_opted_in')->default(true)->after('total_purchases_count');
            $table->boolean('email_marketing_opted_in')->default(true)->after('sms_marketing_opted_in');
            $table->boolean('whatsapp_marketing_opted_in')->default(true)->after('email_marketing_opted_in');
            $table->string('referral_code')->nullable()->unique()->after('whatsapp_marketing_opted_in');
            $table->unsignedBigInteger('referred_by_customer_id')->nullable()->after('referral_code');
            $table->json('tags')->nullable()->after('referred_by_customer_id');

            $table->foreign('customer_group_id')
                ->references('id')->on('customer_groups')
                ->nullOnDelete();

            $table->index('customer_group_id');
            $table->index('loyalty_points_balance');
            $table->index('outstanding_balance');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['customer_group_id']);
            $table->dropIndex(['customer_group_id']);
            $table->dropIndex(['loyalty_points_balance']);
            $table->dropIndex(['outstanding_balance']);

            $cols = [
                'customer_group_id', 'loyalty_points_balance', 'lifetime_value',
                'outstanding_balance', 'last_purchase_at', 'total_purchases_count',
                'sms_marketing_opted_in', 'email_marketing_opted_in',
                'whatsapp_marketing_opted_in', 'referral_code',
                'referred_by_customer_id', 'tags',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('customers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
