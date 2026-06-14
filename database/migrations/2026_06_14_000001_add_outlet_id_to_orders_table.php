<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('outlet_id')->nullable()->after('user_id')->constrained('outlets')->nullOnDelete();
            $table->index(['order_type', 'source', 'payment_status', 'outlet_id'], 'orders_store_sales_report_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_store_sales_report_index');
            $table->dropConstrainedForeignId('outlet_id');
        });
    }
};
