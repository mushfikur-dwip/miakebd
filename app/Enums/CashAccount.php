<?php

namespace App\Enums;

/**
 * The money pots a branch keeps. The drawer is always there; the MFS pots
 * exist only while the branch has agent service switched on. A SIM pot is
 * e-money on the agent number (for Recharge, the recharge balance), a cash pot
 * is the notes that business brought in - kept apart from the sales drawer.
 */
interface CashAccount
{
    const DRAWER        = 1;
    const BKASH_SIM     = 2;
    const BKASH_CASH    = 3;
    const NAGAD_SIM     = 4;
    const NAGAD_CASH    = 5;
    const RECHARGE_SIM  = 6;
    const RECHARGE_CASH = 7;
    // E-money from till sales: paid by card, or by bKash/Nagad at the counter.
    // Never in the drawer; always there, whatever the agent-service switch.
    const POS_CARD      = 8;
    const POS_MFS       = 9;
}
