<?php

namespace App\Services;

use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Libraries\AppLibrary;
use App\Libraries\QueryExceptionLibrary;
use App\Models\Order;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StoreSalesReportService
{
    protected array $filters = [
        'order_serial_no',
        'outlet_id',
        'status',
        'payment_method',
    ];

    /**
     * @throws Exception
     */
    public function list(Request $request)
    {
        try {
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType   = $request->get('order_by') ?? 'desc';

            return $this->query($request)->orderBy($orderColumn, $orderType)->$method($methodValue);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function overview(Request $request): array
    {
        try {
            $orders = $this->query($request)->get();

            return [
                'total_orders'      => $orders->count(),
                'total_earnings'    => AppLibrary::currencyAmountFormat($orders->sum('total')),
                'total_discounts'   => AppLibrary::currencyAmountFormat($orders->sum('discount')),
                'total_branches'    => $orders->pluck('outlet_id')->unique()->count(),
            ];
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function branchSummary(Request $request)
    {
        try {
            return $this->query($request)
                ->selectRaw('outlet_id, COUNT(*) as total_orders, SUM(total) as total_sales, SUM(discount) as total_discounts')
                ->groupBy('outlet_id')
                ->orderByDesc('total_sales')
                ->get();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    private function query(Request $request)
    {
        $requests = $request->all();

        return Order::with('user', 'outlet')
            ->where('order_type', OrderType::POS)
            ->where('source', Source::POS)
            ->where('payment_status', PaymentStatus::PAID)
            ->whereNotNull('outlet_id')
            ->where(function ($query) use ($requests) {
                if (isset($requests['from_date']) && isset($requests['to_date'])) {
                    $firstDate = date('Y-m-d', strtotime($requests['from_date']));
                    $lastDate  = date('Y-m-d', strtotime($requests['to_date']));
                    $query->whereDate('order_datetime', '>=', $firstDate)
                        ->whereDate('order_datetime', '<=', $lastDate);
                }

                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->filters)) {
                        if ($key === 'status' || $key === 'outlet_id') {
                            $query->where($key, (int) $request);
                        } elseif ($key === 'payment_method') {
                            $query->where('pos_payment_method', abs((int) $request));
                        } else {
                            $query->where($key, 'like', '%' . $request . '%');
                        }
                    }
                }
            });
    }
}
