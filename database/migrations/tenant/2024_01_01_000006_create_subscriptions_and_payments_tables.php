<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Billing tables have been moved to central database migrations.
        // Tenant DBs should keep POS data only, so this migration is intentionally a no-op.
    }

    public function down(): void
    {
        // No-op: billing tables are managed centrally.
    }
};
