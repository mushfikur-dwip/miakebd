<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('order_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            $table->unsignedBigInteger('admin_id')->nullable()->after('user_id');
            $table->foreign('admin_id')->references('id')->on('users')->onDelete('set null');
            
            $table->text('note')->nullable()->after('type');
            $table->decimal('balance_before', 19, 6)->nullable()->after('note');
            $table->decimal('balance_after', 19, 6)->nullable()->after('balance_before');
            
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['admin_id']);
            $table->dropIndex(['user_id']);
            $table->dropColumn(['user_id', 'admin_id', 'note', 'balance_before', 'balance_after']);
        });
    }
};
