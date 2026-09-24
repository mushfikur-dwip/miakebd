<?php

namespace App\Enums;

interface SmsRecipientStatus
{
    const PENDING = 5;
    const SENT    = 10;
    const FAILED  = 15;
}
