<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SendPosOrderTelegram
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $info;

    public function __construct(array $info)
    {
        $this->info = $info;
    }
}
