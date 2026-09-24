<?php

namespace Tests\Feature;

use App\Enums\SliderPosition;
use App\Enums\Status;
use App\Http\Requests\PaginateRequest;
use App\Models\Slider;
use App\Services\SliderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The home page asks for one slider position at a time: the hero carousel wants
 * HERO, the banner block wants GRID and WIDE. Getting that filter wrong is not
 * a subtle bug - it puts a wide promo strip inside the hero carousel, or empties
 * the carousel on the live front page.
 */
class SliderPositionTest extends TestCase
{
    use RefreshDatabase;

    private function slider(string $title, int $position): Slider
    {
        return Slider::create([
            'title'    => $title,
            'link'     => '/brands/cerave',
            'position' => $position,
            'status'   => Status::ACTIVE,
        ]);
    }

    private function listFor(array $query)
    {
        $request = PaginateRequest::create('/', 'GET', $query);

        return app(SliderService::class)->list($request);
    }

    public function test_hero_filter_does_not_leak_the_wide_position(): void
    {
        $this->slider('Hero slide', SliderPosition::HERO);
        $this->slider('Wide strip', SliderPosition::WIDE);

        $titles = $this->listFor(['position' => SliderPosition::HERO])->pluck('title');

        // HERO is 5 and WIDE is 15. A LIKE '%5%' filter matches both, which is
        // exactly how a wide banner ends up rendered inside the carousel.
        $this->assertTrue($titles->contains('Hero slide'));
        $this->assertFalse($titles->contains('Wide strip'));
    }

    public function test_each_position_returns_only_its_own_rows(): void
    {
        $this->slider('Hero slide', SliderPosition::HERO);
        $this->slider('Grid tile', SliderPosition::GRID);
        $this->slider('Wide strip', SliderPosition::WIDE);

        foreach ([
            SliderPosition::HERO => 'Hero slide',
            SliderPosition::GRID => 'Grid tile',
            SliderPosition::WIDE => 'Wide strip',
        ] as $position => $expected) {
            $rows = $this->listFor(['position' => $position]);

            $this->assertCount(1, $rows, "position {$position} returned the wrong number of rows");
            $this->assertSame($expected, $rows->first()->title);
        }
    }

    public function test_rows_written_without_a_position_stay_in_the_hero_carousel(): void
    {
        // What every row on the live site looks like the moment the migration
        // runs: the column default, never set by hand.
        Slider::create([
            'title'  => 'Existing slide',
            'status' => Status::ACTIVE,
        ]);

        $titles = $this->listFor(['position' => SliderPosition::HERO])->pluck('title');

        $this->assertTrue($titles->contains('Existing slide'));
    }

    public function test_omitting_the_filter_still_returns_everything(): void
    {
        $this->slider('Hero slide', SliderPosition::HERO);
        $this->slider('Grid tile', SliderPosition::GRID);

        $this->assertCount(2, $this->listFor([]));
    }
}
