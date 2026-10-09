<?php

namespace Tests\Feature;

use App\Enums\Role as EnumRole;
use App\Enums\SliderPosition;
use App\Enums\Status;
use App\Http\Middleware\CachePublicResponse;
use App\Models\Slider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Hero banners are wide desktop designs (1689x600). On a phone the whole banner
 * fits only at ~130px tall, so each hero slide can carry a separate phone
 * image (2:1, 1200x600) that the storefront shows on small screens.
 */
class SliderMobileImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        foreach (['Admin', 'Customer', 'Manager', 'POS Operator', 'Stuff'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'sanctum']);
        }
        Permission::findOrCreate('settings', 'sanctum');
        Role::findById(EnumRole::ADMIN, 'sanctum')->givePermissionTo('settings');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = User::create([
            'name'         => 'Owner',
            'username'     => 'owner',
            'email'        => 'owner@example.com',
            'password'     => bcrypt('secret123'),
            'phone'        => '01700000001',
            'country_code' => '+880',
            'status'       => Status::ACTIVE,
        ]);
        $admin->assignRole(EnumRole::ADMIN);
        Sanctum::actingAs($admin);
    }

    private function hero(array $extra = []): array
    {
        return $extra + [
            'status'   => Status::ACTIVE,
            'position' => SliderPosition::HERO,
            'image'    => UploadedFile::fake()->image('banner.jpg', 1689, 600),
        ];
    }

    private function storefrontSlide(int $id): array
    {
        CachePublicResponse::flush();
        $rows = $this->getJson('/api/frontend/slider?paginate=0&position=' . SliderPosition::HERO)->assertOk()->json('data');

        return collect($rows)->firstWhere('id', $id);
    }

    public function test_a_hero_slide_can_carry_a_phone_image(): void
    {
        $id = $this->post('/api/admin/setting/slider', $this->hero([
            'mobile_image' => UploadedFile::fake()->image('phone.jpg', 1200, 600),
        ]))->assertSuccessful()->json('data.id');

        $slide = $this->storefrontSlide($id);

        $this->assertStringContainsString('phone-mobile.jpg', (string) $slide['mobile_image']);
        $this->assertStringContainsString('banner-cover.jpg', $slide['image']);
    }

    public function test_a_slide_without_a_phone_image_says_so(): void
    {
        $id = $this->post('/api/admin/setting/slider', $this->hero())->assertSuccessful()->json('data.id');

        $this->assertNull($this->storefrontSlide($id)['mobile_image']);
    }

    /** The phone upload gets its own 1200x600 copy, not the desktop sizes. */
    public function test_the_phone_image_is_resized_for_phones_only(): void
    {
        $id = $this->post('/api/admin/setting/slider', $this->hero([
            'mobile_image' => UploadedFile::fake()->image('phone.jpg', 1080, 540),
        ]))->assertSuccessful()->json('data.id');

        $media = Slider::find($id)->getFirstMedia('slider-mobile');

        $this->assertTrue($media->hasGeneratedConversion('mobile'));
        $this->assertFalse($media->hasGeneratedConversion('cover'));
        $this->assertFalse($media->hasGeneratedConversion('tile'));
        $this->assertSame([1200, 600], array_slice(getimagesize($media->getPath('mobile')), 0, 2));
    }

    public function test_a_new_phone_image_replaces_the_old_one(): void
    {
        $id = $this->post('/api/admin/setting/slider', $this->hero([
            'mobile_image' => UploadedFile::fake()->image('first.jpg', 1200, 600),
        ]))->assertSuccessful()->json('data.id');

        $this->post("/api/admin/setting/slider/{$id}", [
            'status'       => Status::ACTIVE,
            'position'     => SliderPosition::HERO,
            'mobile_image' => UploadedFile::fake()->image('second.jpg', 1200, 600),
        ])->assertSuccessful();

        $this->assertCount(1, Slider::find($id)->getMedia('slider-mobile'));
        $this->assertStringContainsString('second-mobile.jpg', $this->storefrontSlide($id)['mobile_image']);
    }

    public function test_editing_without_new_files_keeps_both_images(): void
    {
        $id = $this->post('/api/admin/setting/slider', $this->hero([
            'mobile_image' => UploadedFile::fake()->image('phone.jpg', 1200, 600),
        ]))->assertSuccessful()->json('data.id');

        $this->post("/api/admin/setting/slider/{$id}", [
            'title'    => 'Eid offer',
            'status'   => Status::ACTIVE,
            'position' => SliderPosition::HERO,
        ])->assertSuccessful();

        $slide = $this->storefrontSlide($id);
        $this->assertStringContainsString('phone-mobile.jpg', $slide['mobile_image']);
        $this->assertStringContainsString('banner-cover.jpg', $slide['image']);
    }

    public function test_the_phone_image_can_be_removed(): void
    {
        $id = $this->post('/api/admin/setting/slider', $this->hero([
            'mobile_image' => UploadedFile::fake()->image('phone.jpg', 1200, 600),
        ]))->assertSuccessful()->json('data.id');

        $this->post("/api/admin/setting/slider/{$id}", [
            'status'              => Status::ACTIVE,
            'position'            => SliderPosition::HERO,
            'remove_mobile_image' => 1,
        ])->assertSuccessful();

        $slide = $this->storefrontSlide($id);
        $this->assertNull($slide['mobile_image']);
        $this->assertStringContainsString('banner-cover.jpg', $slide['image']);
    }

    public function test_the_phone_image_must_be_a_picture(): void
    {
        $this->postJson('/api/admin/setting/slider', $this->hero([
            'mobile_image' => UploadedFile::fake()->create('phone.pdf', 10, 'application/pdf'),
        ]))->assertStatus(422)->assertJsonValidationErrors('mobile_image');
    }
}
