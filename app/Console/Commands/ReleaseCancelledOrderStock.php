<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\Status;
use App\Libraries\AppLibrary;
use App\Models\Order;
use App\Models\Stock;
use Illuminate\Console\Command;

/**
 * Until cancelling gave stock back, an order cancelled or rejected from the
 * admin kept its units off the shelf for good. This lists every such order
 * still holding stock; --fix gives the units back, as cancelling does now.
 *
 * Run the list first and compare it with the shelves: a branch that already
 * corrected its count by hand (Stock Adjustment) would be counted twice.
 */
class ReleaseCancelledOrderStock extends Command
{
    protected $signature = 'orders:cancelled-stock {--fix : Give the listed orders\' stock back}';

    protected $description = 'List cancelled or rejected orders whose units are still off the shelf, and optionally give them back';

    public function handle(): int
    {
        $lines = Stock::with(['product:id,name', 'outlet:id,name'])
            ->where('model_type', Order::class)
            ->where('status', Status::ACTIVE)
            ->whereIn('model_id', Order::select('id')->whereIn('status', [OrderStatus::CANCELED, OrderStatus::REJECTED]))
            ->orderBy('model_id')
            ->get();

        if ($lines->isEmpty()) {
            $this->info('No cancelled or rejected order holds any stock.');

            return self::SUCCESS;
        }

        $orders = Order::whereIn('id', $lines->pluck('model_id')->unique())->get()->keyBy('id');
        $units  = (int) abs($lines->sum('quantity'));

        $this->table(['order', 'placed', 'status', 'branch', 'product', 'units'], $lines->map(fn(Stock $line) => [
            $orders[$line->model_id]?->order_serial_no,
            AppLibrary::datetime($orders[$line->model_id]?->order_datetime),
            trans('orderStatus.' . $orders[$line->model_id]?->status),
            $line->outlet?->name ?? '-',
            trim($line->product?->name . ' ' . $line->variation_names),
            abs((int) $line->quantity),
        ])->all());

        if (!$this->option('fix')) {
            $this->line($orders->count() . ' cancelled or rejected orders still hold ' . $units . ' units. Run with --fix to give them back.');

            return self::SUCCESS;
        }

        Stock::whereIn('id', $lines->pluck('id'))->update(['status' => Status::INACTIVE]);
        $this->info('Released ' . $units . ' units from ' . $orders->count() . ' orders.');

        return self::SUCCESS;
    }
}
