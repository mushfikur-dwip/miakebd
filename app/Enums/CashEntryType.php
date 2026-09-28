<?php

namespace App\Enums;

interface CashEntryType
{
    const POS_SALE          = 1;
    const POS_SALE_REVERSAL = 2;
    const ADD               = 3;
    const WITHDRAW          = 4;
    const TRANSFER_OUT      = 5;
    const TRANSFER_IN       = 6;
    const MFS_CASH_IN       = 7;
    const MFS_CASH_OUT      = 8;
    const MFS_RECHARGE      = 9;
    const COUNT_VARIANCE    = 10;
    const REVERSAL          = 11;
    // Settings -> Reset: takes an account to zero, as a recorded entry.
    const RESET             = 12;
}
