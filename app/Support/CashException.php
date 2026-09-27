<?php

namespace App\Support;

use Exception;

/**
 * A refusal from the cash ledger that the page should show as-is: wrong PIN
 * (422), PIN locked (429), not enough balance (422) and the like. Carries its
 * own HTTP status so the controller does not have to guess.
 */
class CashException extends Exception
{
    public function __construct(string $message, private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
