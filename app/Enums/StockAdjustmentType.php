<?php

namespace App\Enums;

interface StockAdjustmentType
{
    const TRANSFER = 5;
    const ADD = 10;
    const REMOVE = 15;
    // Sets a branch's count to an absolute number rather than moving goods by
    // a delta. What the operator types becomes the stock, whatever was there.
    const RECALCULATE = 20;
}
