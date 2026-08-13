<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Branch-wise stock. Every stock movement (purchase in, POS sale out,
     * adjustment, transfer) now records which outlet it happened at.
     *
     * NULL means "unassigned" - that is every row written before this
     * migration, plus website delivery orders, which are not tied to a
     * branch. The online storefront keeps summing every row regardless of
     * outlet, so the total it shows is unchanged by this migration.
     *
     * Written to survive a half-applied run. MySQL does not roll DDL back, so
     * if the column was added and the index then failed, the migration row was
     * never recorded and the next attempt would die on "duplicate column"
     * instead of finishing the job. Each step checks for itself.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('stocks', 'outlet_id')) {
            Schema::table('stocks', function (Blueprint $table) {
                $table->foreignId('outlet_id')->nullable()->after('product_id')->constrained('outlets')->nullOnDelete();
            });
        }

        // Covers the per-branch sum the POS runs for every product tile.
        //
        // item_type is deliberately NOT in this index. It is a VARCHAR(255),
        // which on utf8mb4 is 1020 bytes on its own - past the 767-byte index
        // limit of older MySQL and of any table still on COMPACT row format.
        // Leading with it is what made this migration fail with "Specified key
        // was too long" on exactly the servers least able to recover from a
        // half-applied schema change. outlet_id + item_id already narrow the
        // scan to a couple of rows; item_type only separates a product from a
        // variation that happens to share an id, and the query filters on it
        // anyway.
        if (!$this->hasIndex('stocks', 'stocks_outlet_item_index')) {
            Schema::table('stocks', function (Blueprint $table) {
                $table->index(['outlet_id', 'item_id', 'status'], 'stocks_outlet_item_index');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('stocks', 'stocks_outlet_item_index')) {
            Schema::table('stocks', function (Blueprint $table) {
                $table->dropIndex('stocks_outlet_item_index');
            });
        }

        if (Schema::hasColumn('stocks', 'outlet_id')) {
            Schema::table('stocks', function (Blueprint $table) {
                $table->dropConstrainedForeignId('outlet_id');
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        // Schema::getIndexes(), not SHOW INDEX: the raw statement is MySQL-only
        // and breaks the test suite, which runs on SQLite.
        return collect(Schema::getIndexes($table))->contains(fn ($idx) => $idx['name'] === $index);
    }
};
