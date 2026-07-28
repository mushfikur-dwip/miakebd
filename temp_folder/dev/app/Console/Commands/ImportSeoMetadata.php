<?php
/**
 * suglow.com — Import SEO metadata from Suglow_SEO_3_Boxes.xlsx
 *
 * UPLOAD TO:  dev/app/Console/Commands/ImportSeoMetadata.php
 *
 * INSTALL FIRST (BUG 5 FIX — correct package name):
 *   composer require phpoffice/phpspreadsheet
 *
 * UPLOAD the spreadsheet to:  dev/storage/app/Suglow_SEO_3_Boxes.xlsx
 *
 * RUN A SAFE TEST FIRST:
 *   php artisan seo:import --dry-run
 *
 * THEN FOR REAL:
 *   php artisan seo:import
 *
 * FIXES IN THIS VERSION
 *  BUG 4: removed the LIKE '%%' fallback that could overwrite a random product.
 *  BUG 6: selects the worksheet by NAME instead of getActiveSheet().
 *  Added --dry-run, DB transaction, and length-safe truncation.
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportSeoMetadata extends Command
{
    protected $signature = 'seo:import
        {--file=Suglow_SEO_3_Boxes.xlsx : Filename inside storage/app, or an absolute path}
        {--sheet=Copy Paste to Website : Worksheet name to read}
        {--dry-run : Report what would change without writing anything}';

    protected $description = 'Import SEO title/description/keywords from the Excel file into products';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // ---- locate the file -------------------------------------------------
        $file = $this->option('file');
        $path = is_file($file) ? $file : storage_path('app/' . $file);

        if (! is_file($path)) {
            $this->error("Spreadsheet not found.");
            $this->line("  Looked for: {$path}");
            $this->line("  Upload it to dev/storage/app/ and try again.");
            return 1;
        }

        // ---- sanity: does the products table have the columns? ---------------
        if (! Schema::hasTable('products')) {
            $this->error('No products table found. Run: php artisan migrate');
            return 1;
        }
        foreach (['meta_title', 'meta_description', 'meta_keywords'] as $col) {
            if (! Schema::hasColumn('products', $col)) {
                $this->error("products table is missing column: {$col}");
                $this->line('Run the migration first:  php artisan migrate');
                return 1;
            }
        }

        // ---- read the workbook (BUG 6 FIX: pick sheet by name) ---------------
        $this->info("Reading {$path} ...");
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);

        $wanted = $this->option('sheet');
        $sheet  = $book->getSheetByName($wanted) ?? $book->getSheet(0);
        $this->line('  Worksheet: ' . $sheet->getTitle());

        $rows = $sheet->toArray(null, true, true, false);
        array_shift($rows);   // drop header row

        // ---- how long can meta_title be? -------------------------------------
        $titleMax = 65;

        $updated    = 0;
        $skipped    = 0;
        $unmatched  = [];

        if (! $dry) {
            DB::beginTransaction();
        }

        try {
            foreach ($rows as $row) {
                $name        = trim((string) ($row[0] ?? ''));
                $title       = trim((string) ($row[2] ?? ''));
                $description = trim((string) ($row[3] ?? ''));
                $keywords    = trim((string) ($row[4] ?? ''));

                if ($name === '' || $title === '') {
                    $skipped++;
                    continue;
                }

                $product = $this->findProduct($name);

                if (! $product) {
                    $unmatched[] = $name;
                    continue;
                }

                $payload = [
                    'meta_title'       => Str::limit($title, $titleMax, ''),
                    'meta_description' => $description,
                    'meta_keywords'    => $keywords,
                ];

                if ($dry) {
                    $this->line("  WOULD UPDATE #{$product->id}  {$product->name}");
                } else {
                    $product->forceFill($payload)->save();
                }

                $updated++;
            }

            if (! $dry) {
                DB::commit();
            }
        } catch (\Throwable $e) {
            if (! $dry) {
                DB::rollBack();
            }
            $this->error('Import failed, nothing was written: ' . $e->getMessage());
            return 1;
        }

        // ---- report ----------------------------------------------------------
        $this->newLine();
        $this->info($dry ? 'DRY RUN — nothing was written.' : 'Import complete.');
        $this->line("  Matched & updated : {$updated}");
        $this->line("  Blank rows skipped: {$skipped}");
        $this->line('  Not matched       : ' . count($unmatched));

        if ($unmatched) {
            $log = storage_path('logs/seo-import-unmatched.txt');
            file_put_contents($log, implode("\n", $unmatched));
            $this->newLine();
            $this->warn('These product names had no match in the database:');
            foreach (array_slice($unmatched, 0, 15) as $u) {
                $this->line("  - {$u}");
            }
            if (count($unmatched) > 15) {
                $this->line('  ... full list: ' . $log);
            }
            $this->newLine();
            $this->line('Usually this means the spreadsheet name differs slightly from the DB name.');
            $this->line('Fix the name in the spreadsheet, or add the slug, then re-run.');
        }

        return 0;
    }

    /**
     * BUG 4 FIX — exact matching only. No wildcard guessing, ever.
     */
    private function findProduct(string $name)
    {
        $model = \App\Models\Product::class;

        // 1. exact name
        $p = $model::where('name', $name)->first();
        if ($p) return $p;

        // 2. exact slug
        $p = $model::where('slug', Str::slug($name))->first();
        if ($p) return $p;

        // 3. case/space-insensitive exact comparison
        $needle = $this->normalise($name);
        return $model::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$needle])
            ->first();
    }

    private function normalise(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return preg_replace('/\s+/', ' ', $s);
    }
}
