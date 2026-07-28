<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductSeo;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ImportSeoMetadata extends Command
{
    protected $signature = 'seo:import
        {--file= : Spreadsheet path (absolute or relative to storage/app)}
        {--sheet= : Worksheet name; defaults to the first sheet}
        {--dry-run : Validate and report without writing}';

    protected $description = 'Import product SEO data into the existing product_seos table';

    public function handle(): int
    {
        $path = $this->resolvePath((string) $this->option('file'));

        if (! $path) {
            $this->error('Spreadsheet not found. Pass --file=<path>.');

            return self::FAILURE;
        }

        try {
            $sheets = Excel::toArray(null, $path);
        } catch (Throwable $exception) {
            $this->error('Unable to read spreadsheet: '.$exception->getMessage());

            return self::FAILURE;
        }

        $rows = $this->selectSheet($sheets, $path, $this->option('sheet'));

        if ($rows === null || count($rows) < 2) {
            $this->error('The selected worksheet is missing or has no data rows.');

            return self::FAILURE;
        }

        $headers = collect(array_shift($rows))
            ->map(fn ($header) => $this->normalizeHeader((string) $header))
            ->all();

        $required = [
            'title' => $this->findHeader($headers, ['meta_title', 'seo_title', 'title', '1_title']),
            'description' => $this->findHeader($headers, ['meta_description', 'seo_description', 'description', '2_description']),
            'keywords' => $this->findHeader($headers, ['meta_keywords', 'meta_keyword', 'keywords', '3_meta_keywords']),
        ];
        $skuColumn = $this->findHeader($headers, ['sku', 'product_sku']);
        $nameColumn = $this->findHeader($headers, ['product_name', 'name', 'product']);

        if (in_array(null, $required, true) || ($skuColumn === null && $nameColumn === null)) {
            $this->error('Required columns: title, description, keywords, and either SKU or product name.');
            $this->line('Detected headers: '.implode(', ', array_filter($headers)));

            return self::FAILURE;
        }

        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'unmatched' => 0, 'ambiguous' => 0, 'invalid' => 0];
        $seenProducts = [];
        $pending = [];
        $products = Product::query()->select(['id', 'sku', 'name'])->get();
        $productsBySku = $products
            ->filter(fn (Product $product) => trim((string) $product->sku) !== '')
            ->groupBy(fn (Product $product) => trim((string) $product->sku));
        $productsByName = $products
            ->filter(fn (Product $product) => trim((string) $product->name) !== '')
            ->groupBy(fn (Product $product) => Str::lower(trim((string) $product->name)));
        $existingSeoByProduct = ProductSeo::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->get()
            ->groupBy('product_id');

        foreach ($rows as $offset => $values) {
            $rowNumber = $offset + 2;
            $row = $this->combineRow($headers, $values);
            $title = trim((string) ($row[$required['title']] ?? ''));
            $description = trim((string) ($row[$required['description']] ?? ''));
            $keywords = $this->keywords($row[$required['keywords']] ?? null);

            if ($title === '' && $description === '' && $keywords === []) {
                continue;
            }

            $matches = $this->matchProducts(
                $skuColumn ? trim((string) ($row[$skuColumn] ?? '')) : '',
                $nameColumn ? trim((string) ($row[$nameColumn] ?? '')) : '',
                $productsBySku,
                $productsByName
            );

            if ($matches->isEmpty()) {
                $stats['unmatched']++;
                $this->warn("Row {$rowNumber}: product not matched.");

                continue;
            }

            if ($matches->count() !== 1) {
                $stats['ambiguous']++;
                $this->warn("Row {$rowNumber}: matched {$matches->count()} products; skipped.");

                continue;
            }

            $product = $matches->first();
            if (isset($seenProducts[$product->id])) {
                $stats['ambiguous']++;
                $this->warn("Row {$rowNumber}: duplicate spreadsheet match for product #{$product->id}; skipped.");

                continue;
            }
            $seenProducts[$product->id] = true;

            if ($title === '' || $description === '') {
                $stats['invalid']++;
                $this->warn("Row {$rowNumber}: title or description is blank; skipped.");

                continue;
            }

            $attributes = [
                'title' => Str::limit($title, 255, ''),
                'description' => $description,
                'meta_keyword' => json_encode($keywords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            $existingRecords = $existingSeoByProduct->get($product->id, collect());

            if ($existingRecords->count() > 1) {
                $stats['ambiguous']++;
                $this->warn("Row {$rowNumber}: product #{$product->id} has duplicate SEO records; skipped.");
                continue;
            }

            $existing = $existingRecords->first();

            if ($existing && $existing->only(array_keys($attributes)) === $attributes) {
                $stats['unchanged']++;

                continue;
            }

            $stats[$existing ? 'updated' : 'created']++;
            $pending[] = [$product->id, $attributes];
        }

        if (! $this->option('dry-run')) {
            DB::transaction(function () use ($pending): void {
                foreach ($pending as [$productId, $attributes]) {
                    ProductSeo::updateOrCreate(['product_id' => $productId], $attributes);
                }
            });
        }

        $this->table(['Result', 'Count'], collect($stats)->map(fn ($count, $key) => [$key, $count])->values()->all());
        $this->info($this->option('dry-run') ? 'Dry run complete; no data was written.' : 'SEO import complete.');

        return ($stats['ambiguous'] + $stats['invalid']) > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolvePath(string $file): ?string
    {
        if ($file === '') {
            return null;
        }

        foreach ([$file, storage_path('app/'.$file)] as $candidate) {
            if (is_file($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        return null;
    }

    private function selectSheet(array $sheets, string $path, mixed $sheet): ?array
    {
        if (! $sheet) {
            return $sheets[0] ?? null;
        }

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $names = $reader->listWorksheetNames($path);
        $index = array_search((string) $sheet, $names, true);

        return $index === false ? null : ($sheets[$index] ?? null);
    }

    private function normalizeHeader(string $header): string
    {
        return Str::of($header)->trim()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
    }

    private function findHeader(array $headers, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $headers, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function combineRow(array $headers, array $values): array
    {
        $values = array_pad($values, count($headers), null);

        return array_combine($headers, array_slice($values, 0, count($headers))) ?: [];
    }

    private function matchProducts(
        string $sku,
        string $name,
        Collection $productsBySku,
        Collection $productsByName
    ): Collection {
        if ($sku !== '') {
            return collect($productsBySku->get($sku, []));
        }

        if ($name === '') {
            return collect();
        }

        return collect($productsByName->get(Str::lower(trim($name)), []));
    }

    private function keywords(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_unique(array_filter(array_map('trim', $value))));
        }

        $decoded = json_decode((string) $value, true);
        $items = is_array($decoded) ? $decoded : preg_split('/[,;\n|]+/', (string) $value);

        return array_values(array_unique(array_filter(array_map(fn ($item) => trim((string) $item), $items ?: []))));
    }
}
