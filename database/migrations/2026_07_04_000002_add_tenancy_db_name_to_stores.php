<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTenancyDbNameToStores extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('stores')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'tenancy_db_name')) {
                $table->string('tenancy_db_name')->nullable()->after('slug');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('stores')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'tenancy_db_name')) {
                $table->dropColumn('tenancy_db_name');
            }
        });
    }
}
