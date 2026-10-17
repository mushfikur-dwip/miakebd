<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** An item is down to its last one (or none) at a branch: tell the shop chat. */
class SendLowStockTelegram
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $info;

    public function __construct(array $info)
    {
        $this->info = $info;
    }
}
