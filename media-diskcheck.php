<?php
// Run this ON THE SERVER. Reports which conversion files are actually on disk
// versus what generated_conversions claims, per conversion.

$stat = ['original' => ['ok' => 0, 'missing' => 0]];
foreach (['thumb', 'cover', 'preview'] as $c) {
    $stat[$c] = ['ok' => 0, 'missing' => 0, 'not_flagged' => 0];
}

$missingPreviewProducts = [];

foreach (Spatie\MediaLibrary\MediaCollections\Models\Media::where('collection_name', 'product')->cursor() as $m) {
    is_file($m->getPath()) ? $stat['original']['ok']++ : $stat['original']['missing']++;

    foreach (['thumb', 'cover', 'preview'] as $c) {
        if (!$m->hasGeneratedConversion($c)) {
            $stat[$c]['not_flagged']++;
            continue;
        }
        if (is_file($m->getPath($c))) {
            $stat[$c]['ok']++;
        } else {
            $stat[$c]['missing']++;
            if ($c === 'preview' && count($missingPreviewProducts) < 10) {
                $missingPreviewProducts[] = $m->id;
            }
        }
    }
}

foreach ($stat as $name => $s) {
    echo str_pad($name, 10) . ' on disk: ' . str_pad($s['ok'], 5)
        . ' MISSING: ' . str_pad($s['missing'], 5)
        . (isset($s['not_flagged']) ? ' never generated: ' . $s['not_flagged'] : '')
        . PHP_EOL;
}

if ($missingPreviewProducts) {
    echo PHP_EOL . 'sample media ids with a flagged-but-absent preview: '
        . implode(',', $missingPreviewProducts) . PHP_EOL;
}
