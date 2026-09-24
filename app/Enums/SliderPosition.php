<?php

namespace App\Enums;

/**
 * Where a slider row renders on the home page.
 *
 * HERO is the big carousel that has always been there. It is the default, so
 * every row written before this column existed keeps rendering exactly where
 * it used to - nothing on a live site moves when the migration runs.
 *
 * GRID and WIDE are the banner block underneath it: GRID is the row of small
 * tiles, WIDE is the full-width strip below them.
 */
interface SliderPosition
{
    const HERO = 5;
    const GRID = 10;
    const WIDE = 15;
}
