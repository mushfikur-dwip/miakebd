<!DOCTYPE html>
<html lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Store Sales Report</title>
    <style>
        body { font-family: "Urbanist", sans-serif !important; color: #1F1F39; }
        .report { width: 100%; text-align: center; }
        p { margin: 0 0 12px 0; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        th, td { border: 1px solid #EFF0F6; padding: 9px 8px; text-align: left; font-size: 11px; font-weight: 400; }
        th { background-color: #F8FBFB; }
        tbody { color: #6E7191; }
        .title { color: #F23E14; font-size: 16px; font-weight: bold; }
        .section-title { text-align: left; font-size: 13px; font-weight: 700; margin: 14px 0 8px; }
        .total { color: #1F1F39; font-weight: 700; }
        .footer { position: fixed; width: 100%; text-align: center; font-size: 11px; bottom: 10px; }
    </style>
</head>

<body>
    @php
        $total = 0;
        $discount = 0;
    @endphp
    <div class="report">
        <img src="{{ $themeLogo }}" width="86" height="30" alt="logo">
        <p style="font-size: 16px;font-weight: bold">{{ App\Libraries\AppLibrary::textShortener($company['company_name'], 60) }}</p>
        <p>{{ App\Libraries\AppLibrary::textShortener($company['company_address'], 50) }}</p>
        <p class="title">{{ trans('all.label.store_sales_report', [], 'en') }}</p>

        <p class="section-title">{{ trans('all.label.branch_summary', [], 'en') }}</p>
        <table>
            <thead>
                <tr>
                    <th>{{ trans('all.label.branch', [], 'en') }}</th>
                    <th>{{ trans('all.label.total_orders', [], 'en') }}</th>
                    <th>{{ trans('all.label.total_sales', [], 'en') }}</th>
                    <th>{{ trans('all.label.total_discounts', [], 'en') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($branchSummary as $summary)
                    <tr>
                        <td>{{ $summary?->outlet?->name }}</td>
                        <td>{{ $summary->total_orders }}</td>
                        <td>{{ App\Libraries\AppLibrary::flatAmountFormat($summary->total_sales) }}</td>
                        <td>{{ App\Libraries\AppLibrary::flatAmountFormat($summary->total_discounts) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p class="section-title">{{ trans('all.label.order_details', [], 'en') }}</p>
        <table>
            <thead>
                <tr>
                    <th>{{ trans('all.label.branch', [], 'en') }}</th>
                    <th>{{ trans('all.label.order_id', [], 'en') }}</th>
                    <th>{{ trans('all.label.date', [], 'en') }}</th>
                    <th>{{ trans('all.label.customer', [], 'en') }}</th>
                    <th>{{ trans('all.label.payment_type', [], 'en') }}</th>
                    <th>{{ trans('all.label.status', [], 'en') }}</th>
                    <th>{{ trans('all.label.discount', [], 'en') }}</th>
                    <th>{{ trans('all.label.total', [], 'en') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($orders as $order)
                    @php
                        $total += $order->total;
                        $discount += $order->discount;
                    @endphp
                    <tr>
                        <td>{{ $order?->outlet?->name }}</td>
                        <td>{{ $order->order_serial_no }}</td>
                        <td>{{ App\Libraries\AppLibrary::datetime($order->order_datetime) }}</td>
                        <td>{{ $order?->user?->name }}</td>
                        <td>{{ trans('posPaymentMethod.' . $order->pos_payment_method, [], 'en') }}</td>
                        <td>{{ trans('orderStatus.' . $order->status, [], 'en') }}</td>
                        <td>{{ App\Libraries\AppLibrary::flatAmountFormat($order->discount) }}</td>
                        <td>{{ App\Libraries\AppLibrary::flatAmountFormat($order->total) }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td colspan="6">{{ trans('all.label.total', [], 'en') }}</td>
                    <td>{{ App\Libraries\AppLibrary::reportCurrencyAmountFormat($discount) }}</td>
                    <td>{{ App\Libraries\AppLibrary::reportCurrencyAmountFormat($total) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="footer">{{ $copyright }}</div>
</body>

</html>
