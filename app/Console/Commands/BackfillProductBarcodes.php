<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Models\Product;
use App\Support\SeoSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Give every product a valid, scannable EAN-13 so labels can be printed.
 *
 * WHY A RESERVED PREFIX: a GTIN is a globally unique number issued by GS1.
 * Generating one at random in the normal ranges lands on a barcode that already
 * belongs to another company's product — it would scan as the wrong item at a
 * till, and Google Merchant Center (which validates GTINs) would disapprove the
 * listing or suspend the account.
 *
 * GS1 reserves prefixes 02 and 20-29 for "restricted circulation" — numbers a
 * shop assigns for its own internal use. Codes generated here use prefix 20, so
 * they are real, check-digit-valid, scannable EAN-13 that can never collide with
 * an issued GTIN.
 *
 * SeoSchema::gtinFor() deliberately refuses to publish anything in that range as
 * a gtin13, so these stay internal — exactly as intended.
 */
class BackfillProductBarcodes extends Command
{
    protected $signature = 'barcodes:backfill
                            {--dry-run : Print what would change and write nothing}';

    protected $description = 'Assign a valid internal EAN-13 to products whose SKU is not a real barcode';

    /** GS1 restricted-circulation prefix. See the class docblock. */
    private const INTERNAL_PREFIX = '20';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $products = Product::query()
            ->where('status', Status::ACTIVE)
            ->orderBy('id')
            ->get(['id', 'name', 'sku']);

        if ($products->isEmpty()) {
            $this->warn('No active products found.');

            return self::SUCCESS;
        }

        // Every SKU in the table, not just the active ones — a generated code
        // must not collide with an inactive product's barcode either.
        $taken = DB::table('products')->pluck('sku')
            ->map(fn ($sku) => preg_replace('/\D/', '', (string) $sku))
            ->filter()
            ->flip();

        $rows = [];

        foreach ($products as $product) {
            if (SeoSchema::isValidGtin($product->sku)) {
                continue;
            }

            $code = $this->generateUnique($taken);
            $taken[$code] = true;

            $rows[] = [
                'id'      => $product->id,
                'name'    => $product->name,
                'old_sku' => (string) $product->sku,
                'new_sku' => $code,
            ];
        }

        $valid = $products->count() - count($rows);
        $this->info("Active products      : {$products->count()}");
        $this->info("Already valid GTIN   : {$valid}");
        $this->info('To be assigned       : ' . count($rows));

        if (empty($rows)) {
            $this->info('Nothing to do — every active product already has a valid barcode.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Product', 'Old SKU', 'New EAN-13'],
            array_map(fn ($r) => [
                $r['id'],
                mb_strimwidth($r['name'], 0, 45, '…'),
                mb_strimwidth($r['old_sku'], 0, 24, '…'),
                $r['new_sku'],
            ], array_slice($rows, 0, 20))
        );

        if (count($rows) > 20) {
            $this->line('  … and ' . (count($rows) - 20) . ' more.');
        }

        if ($dryRun) {
            $this->warn('Dry run — nothing was written. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        // Backup BEFORE writing. This command overwrites existing SKUs, which
        // POS lookups and printed labels depend on, so the old values must
        // survive somewhere recoverable.
        $backup = 'sku-backfill-' . now()->format('Ymd-His') . '.csv';
        $csv = "id,name,old_sku,new_sku\n";

        foreach ($rows as $r) {
            $csv .= sprintf(
                "%d,\"%s\",\"%s\",%s\n",
                $r['id'],
                str_replace('"', '""', $r['name']),
                str_replace('"', '""', $r['old_sku']),
                $r['new_sku']
            );
        }

        Storage::disk('local')->put($backup, $csv);
        $this->info("Backup written: storage/app/{$backup}");

        DB::transaction(function () use ($rows) {
            foreach ($rows as $r) {
                DB::table('products')->where('id', $r['id'])->update(['sku' => $r['new_sku']]);
            }
        });

        $this->info('Updated ' . count($rows) . ' products.');

        return self::SUCCESS;
    }

    /**
     * A prefix-20 EAN-13 that is not already in use.
     *
     * @param  \Illuminate\Support\Collection<string,mixed>  $taken
     */
    private function generateUnique($taken): string
    {
        // 10 random digits after the 2-digit prefix, then the check digit.
        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $body = self::INTERNAL_PREFIX;

            for ($i = 0; $i < 10; $i++) {
                $body .= random_int(0, 9);
            }

            $code = $body . SeoSchema::gtinCheckDigit($body);

            if (!isset($taken[$code])) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not generate a unique EAN-13 after 1000 attempts.');
    }
}
