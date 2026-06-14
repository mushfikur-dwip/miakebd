<?php

namespace App\Exports;

use App\Libraries\AppLibrary;
use App\Services\StoreSalesReportService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StoreSalesReportExport implements FromCollection, WithHeadings
{
    public StoreSalesReportService $storeSalesReportService;
    public mixed $request;

    public function __construct(StoreSalesReportService $storeSalesReportService, mixed $request)
    {
        $this->storeSalesReportService = $storeSalesReportService;
        $this->request                 = $request;
    }

    public function collection(): \Illuminate\Support\Collection
    {
        $rows = [];

        foreach ($this->storeSalesReportService->list($this->request) as $order) {
            $rows[] = [
                $order?->outlet?->name,
                $order->order_serial_no,
                AppLibrary::datetime($order->order_datetime),
                $order?->user?->name,
                AppLibrary::flatAmountFormat($order->total),
                AppLibrary::flatAmountFormat($order->discount),
                trans("posPaymentMethod." . $order->pos_payment_method),
                trans('orderStatus.' . $order->status),
            ];
        }

        return collect($rows);
    }

    public function headings(): array
    {
        return [
            trans('all.label.branch'),
            trans('all.label.order_serial_no'),
            trans('all.label.date'),
            trans('all.label.customer'),
            trans('all.label.total'),
            trans('all.label.discount'),
            trans('all.label.payment_type'),
            trans('all.label.status'),
        ];
    }
}
