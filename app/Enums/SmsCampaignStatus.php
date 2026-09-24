<?php

namespace App\Enums;

interface SmsCampaignStatus
{
    /** Recipients are built, nothing sent yet. */
    const DRAFT = 5;

    /** A batch run is in progress. */
    const SENDING = 10;

    /** Stopped by hand. Remaining recipients stay PENDING and can resume. */
    const PAUSED = 15;

    /** No PENDING recipients left. */
    const COMPLETED = 20;
}
