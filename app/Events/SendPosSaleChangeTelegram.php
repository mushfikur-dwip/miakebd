<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A till sale was reversed or its payment method changed: tell the shop chat. */
class SendPosSaleChangeTelegram
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $info;

    public function __construct(array $info)
    {
        $this->info = $info;
    }
}
