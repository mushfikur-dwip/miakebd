<?php

namespace Tests\Feature;

use App\Enums\SliderPosition;
use App\Enums\Status;
use App\Http\Requests\SliderRequest;
use App\Models\Slider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * A banner's words are usually in the picture itself, so the title is
 * optional: without one the storefront shows the image alone.
 */
class SliderTitleTest extends TestCase
{
    use RefreshDatabase;

    private function titlePasses(?string $title): bool
    {
        return Validator::make(['title' => $title], ['title' => (new SliderRequest())->rules()['title']])->passes();
    }

    public function test_a_slider_can_be_saved_without_a_title(): void
    {
        $this->assertTrue($this->titlePasses(null));

        $slider = Slider::create(['title' => null, 'status' => Status::ACTIVE, 'position' => SliderPosition::HERO]);

        $this->assertNull($slider->fresh()->title);
    }

    public function test_several_sliders_can_go_without_a_title(): void
    {
        Slider::create(['title' => null, 'status' => Status::ACTIVE, 'position' => SliderPosition::HERO]);

        $this->assertTrue($this->titlePasses(null));
    }

    public function test_a_given_title_is_still_unique_and_bounded(): void
    {
        Slider::create(['title' => 'Eid offer', 'status' => Status::ACTIVE, 'position' => SliderPosition::HERO]);

        $this->assertFalse($this->titlePasses('Eid offer'));
        $this->assertFalse($this->titlePasses(str_repeat('a', 191)));
        $this->assertTrue($this->titlePasses('Winter sale'));
    }
}
