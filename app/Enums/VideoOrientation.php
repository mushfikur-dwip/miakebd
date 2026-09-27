<?php

namespace App\Enums;

/**
 * The shape of a product video's frame. Not stored (null) means "work it out
 * from the link" - see App\Support\VideoEmbed::isPortrait().
 */
interface VideoOrientation
{
    const LANDSCAPE = 5;
    const PORTRAIT  = 10;
}
