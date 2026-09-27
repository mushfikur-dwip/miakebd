<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Role as EnumRole;
use App\Enums\Status;
use App\Enums\VideoOrientation;
use App\Enums\VideoProvider;
use App\Models\Product;
use App\Models\User;
use App\Support\FacebookVideoLink;
use App\Support\VideoEmbed;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Product videos play in an <iframe>, which only works with a provider's
 * embed-player address. The link an admin copies from Facebook or YouTube is
 * the watch page, which refuses to be framed - so it is turned into the embed
 * address on the way out, and the admin can paste the ordinary link.
 */
class ProductVideoTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    // What Facebook's player page holds; a <video> means it will play.
    private string $playerPage = '<div><video src="x"></video></div>';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Customer', 'Manager', 'POS Operator', 'Stuff'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'sanctum']);
        }
        Permission::findOrCreate('products_show', 'sanctum');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@example.test',
            'password' => bcrypt('secret'), 'status' => Status::ACTIVE,
        ]);
        $admin->assignRole(EnumRole::MANAGER);
        $admin->givePermissionTo('products_show');
        Sanctum::actingAs($admin);

        $this->product = Product::create([
            'name' => 'Serum', 'slug' => 'serum', 'sku' => 'SERUM1', 'status' => Status::ACTIVE,
            'can_purchasable' => Ask::YES, 'buying_price' => 100, 'selling_price' => 500, 'variation_price' => 500,
        ]);

        // Facebook is never reached from a test. By default its player
        // answers as it does for a Public reel.
        Http::preventStrayRequests();
        // The first matching fake wins, so the player is answered from a
        // property each test can change; any other address falls through
        // to the fakes a test adds.
        Http::fake(fn($request) => str_contains($request->url(), 'plugins/video.php') ? Http::response($this->playerPage) : null);
    }

    private function saveFacebook(string $link, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/admin/product/video/' . $this->product->id, $extra + [
            'video_provider' => VideoProvider::FACEBOOK,
            'link'           => $link,
        ]);
    }

    public function test_a_facebook_page_video_link_is_saved_as_its_reel_and_embeds(): void
    {
        $reel = 'https://www.facebook.com/reel/1234567890123456/';

        $this->saveFacebook('https://www.facebook.com/suglowbd/videos/1234567890123456?mibextid=abc')
            ->assertSuccessful()
            ->assertJsonPath('data.provider_name', 'Facebook')
            ->assertJsonPath('data.link', $reel)
            ->assertJsonPath('data.embed_url', 'https://www.facebook.com/plugins/video.php?href=' . rawurlencode($reel) . '&show_text=false&t=0')
            ->assertJsonMissingPath('warning');
    }

    // The link Share -> Copy link gives. The player cannot follow it and
    // shows "Video unavailable", so it is followed here to the reel.
    public function test_a_share_link_is_followed_to_its_reel(): void
    {
        Http::fake([
            'www.facebook.com/share/r/*' => Http::response('', 302, ['Location' => 'https://www.facebook.com/reel/987654321012345/?mibextid=xyz']),
        ]);

        $this->saveFacebook('https://www.facebook.com/share/r/1AbCdEfGhI/')
            ->assertSuccessful()
            ->assertJsonPath('data.link', 'https://www.facebook.com/reel/987654321012345/');
    }

    public function test_a_share_link_whose_page_names_the_reel_is_saved_as_the_reel(): void
    {
        Http::fake([
            'www.facebook.com/share/v/*' => Http::response('<html><head><meta property="og:url" content="https://www.facebook.com/reel/555555555555555/" /></head></html>'),
        ]);

        $this->saveFacebook('https://www.facebook.com/share/v/1XyZ/')
            ->assertSuccessful()
            ->assertJsonPath('data.link', 'https://www.facebook.com/reel/555555555555555/');
    }

    public function test_a_share_link_that_cannot_be_followed_says_what_to_paste_instead(): void
    {
        Http::fake(['www.facebook.com/share/r/*' => Http::response('<html>Log in to Facebook</html>')]);

        $this->saveFacebook('https://www.facebook.com/share/r/1AbCdEfGhI/')
            ->assertStatus(422)
            ->assertJsonValidationErrors('link')
            ->assertJsonPath('errors.link.0', trans('all.message.facebook_share_link'));
    }

    // Facebook's own "Embed" option (on the reel, ... then Embed) gives code,
    // not a link. Pasting that works too.
    public function test_facebooks_embed_code_can_be_pasted_instead_of_a_link(): void
    {
        $code = '<iframe src="https://www.facebook.com/plugins/video.php?height=476&href=https%3A%2F%2Fwww.facebook.com%2Freel%2F444444444444444%2F&show_text=false&width=267&t=0" width="267" height="476" style="border:none;overflow:hidden" scrolling="no" frameborder="0" allowfullscreen="true" allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share" allowFullScreen="true"></iframe>';

        $this->saveFacebook($code)
            ->assertSuccessful()
            ->assertJsonPath('data.link', 'https://www.facebook.com/reel/444444444444444/');
    }

    // Only a reel whose audience is Public plays on another website. Saved
    // anyway - Facebook may be wrong about the server - but the admin is told.
    public function test_a_reel_facebook_will_not_play_elsewhere_is_saved_with_a_warning(): void
    {
        $this->playerPage = '<div>Video Unavailable</div>';

        $this->saveFacebook('https://www.facebook.com/reel/333333333333333/')
            ->assertSuccessful()
            ->assertJsonPath('warning', trans('all.message.facebook_video_not_playable'));
    }

    public function test_the_video_id_is_read_from_every_address_form(): void
    {
        foreach ([
            'https://www.facebook.com/reel/1234567890123456/?mibextid=x' => '1234567890123456',
            'https://www.facebook.com/reels/1234567890123456'            => '1234567890123456',
            'https://www.facebook.com/suglowbd/videos/1234567890123456/' => '1234567890123456',
            'https://www.facebook.com/suglowbd/videos/nice-title/1234567890123456/' => '1234567890123456',
            'https://m.facebook.com/watch/?v=1234567890123456'           => '1234567890123456',
            'https://www.facebook.com/video.php?v=1234567890123456'      => '1234567890123456',
            'https://www.facebook.com/share/r/1AbCdEfGhI/'               => null,
            'https://www.facebook.com/suglowbd'                          => null,
        ] as $url => $id) {
            $this->assertSame($id, FacebookVideoLink::videoId($url), $url);
        }
    }

    public function test_a_non_facebook_link_is_refused_under_facebook(): void
    {
        $this->postJson('/api/admin/product/video/' . $this->product->id, [
            'video_provider' => VideoProvider::FACEBOOK,
            'link'           => 'https://www.youtube.com/watch?v=abcdefghijk',
        ])->assertStatus(422)->assertJsonValidationErrors('link');
    }

    public function test_an_unknown_provider_is_refused(): void
    {
        $this->postJson('/api/admin/product/video/' . $this->product->id, [
            'video_provider' => 24,
            'link'           => 'https://example.com/video',
        ])->assertStatus(422)->assertJsonValidationErrors('video_provider');
    }

    public function test_facebook_links_of_every_shape_become_the_player(): void
    {
        foreach ([
            'https://www.facebook.com/suglowbd/videos/1234567890123456/',
            'https://www.facebook.com/watch/?v=1234567890123456',
            'https://www.facebook.com/reel/1234567890123456',
            'https://fb.watch/abcDEF123/',
            'https://m.facebook.com/suglowbd/videos/1234567890123456',
        ] as $link) {
            $this->assertSame(
                'https://www.facebook.com/plugins/video.php?href=' . rawurlencode($link) . '&show_text=false&t=0',
                VideoEmbed::url(VideoProvider::FACEBOOK, $link),
                $link
            );
        }

        $player = 'https://www.facebook.com/plugins/video.php?href=x&show_text=false';
        $this->assertSame($player, VideoEmbed::url(VideoProvider::FACEBOOK, $player), 'an embed link is left alone');
    }

    // Facebook's player fills whatever frame it is given, so the frame has to
    // take the video's shape: a reel in a wide frame is a strip in the middle.
    public function test_a_facebook_video_is_vertical_unless_set_to_landscape(): void
    {
        $this->saveFacebook('https://www.facebook.com/suglowbd/videos/1234567890123456')
            ->assertSuccessful()->assertJsonPath('data.portrait', true);

        $this->saveFacebook('https://www.facebook.com/suglowbd/videos/6543210987654321', ['orientation' => VideoOrientation::LANDSCAPE])
            ->assertSuccessful()
            ->assertJsonPath('data.orientation', VideoOrientation::LANDSCAPE)
            ->assertJsonPath('data.portrait', false);
    }

    public function test_youtube_is_landscape_except_shorts_or_when_set_vertical(): void
    {
        $this->assertFalse(VideoEmbed::isPortrait(VideoProvider::YOUTUBE, 'https://www.youtube.com/watch?v=abcdefghijk', null));
        $this->assertTrue(VideoEmbed::isPortrait(VideoProvider::YOUTUBE, 'https://youtube.com/shorts/abcdefghijk', null));
        $this->assertTrue(VideoEmbed::isPortrait(VideoProvider::YOUTUBE, 'https://www.youtube.com/watch?v=abcdefghijk', VideoOrientation::PORTRAIT));
        $this->assertFalse(VideoEmbed::isPortrait(VideoProvider::FACEBOOK, 'https://www.facebook.com/reel/1', VideoOrientation::LANDSCAPE));
        $this->assertTrue(VideoEmbed::isPortrait(VideoProvider::FACEBOOK, 'https://www.facebook.com/watch/?v=1', null));
    }

    public function test_an_unknown_orientation_is_refused(): void
    {
        $this->postJson('/api/admin/product/video/' . $this->product->id, [
            'video_provider' => VideoProvider::FACEBOOK,
            'link'           => 'https://www.facebook.com/suglowbd/videos/1',
            'orientation'    => 7,
        ])->assertStatus(422)->assertJsonValidationErrors('orientation');
    }

    public function test_youtube_vimeo_and_dailymotion_watch_links_become_players(): void
    {
        $cases = [
            [VideoProvider::YOUTUBE, 'https://www.youtube.com/watch?v=abcdefghijk&t=10s', 'https://www.youtube.com/embed/abcdefghijk'],
            [VideoProvider::YOUTUBE, 'https://youtu.be/abcdefghijk?si=xyz', 'https://www.youtube.com/embed/abcdefghijk'],
            [VideoProvider::YOUTUBE, 'https://youtube.com/shorts/abcdefghijk', 'https://www.youtube.com/embed/abcdefghijk'],
            [VideoProvider::YOUTUBE, 'https://www.youtube.com/embed/abcdefghijk', 'https://www.youtube.com/embed/abcdefghijk'],
            [VideoProvider::VIMEO, 'https://vimeo.com/76979871', 'https://player.vimeo.com/video/76979871'],
            [VideoProvider::VIMEO, 'https://player.vimeo.com/video/76979871', 'https://player.vimeo.com/video/76979871'],
            [VideoProvider::DAILYMOTION, 'https://www.dailymotion.com/video/x8abcd1', 'https://www.dailymotion.com/embed/video/x8abcd1'],
            [VideoProvider::DAILYMOTION, 'https://dai.ly/x8abcd1', 'https://www.dailymotion.com/embed/video/x8abcd1'],
        ];

        foreach ($cases as [$provider, $link, $expected]) {
            $this->assertSame($expected, VideoEmbed::url($provider, $link), $link);
        }

        // Anything not recognised is passed through as it was saved.
        $this->assertSame('https://example.com/x', VideoEmbed::url(VideoProvider::YOUTUBE, 'https://example.com/x'));
    }
}
