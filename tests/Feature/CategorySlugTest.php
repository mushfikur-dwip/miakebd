<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Models\ProductCategory;
use App\Models\SlugRedirect;
use App\Services\ProductCategoryService;
use App\Http\Requests\ProductCategoryRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Category URLs stay put. Saving a category used to append its parent id or
 * the literal "NULL" to the slug on every other save.
 */
class CategorySlugTest extends TestCase
{
    use RefreshDatabase;

    /** The admin form's request, bound to the category being edited (if any). */
    private function request(array $data, ?ProductCategory $editing = null): ProductCategoryRequest
    {
        $request = ProductCategoryRequest::create('/', 'POST', $data + ['status' => Status::ACTIVE, 'parent_id' => 'NULL']);

        $route = new \Illuminate\Routing\Route('POST', 'admin/setting/product-category/{productCategory?}', []);
        $route->bind($request);
        if ($editing) {
            $route->setParameter('productCategory', $editing);
        }
        $request->setRouteResolver(fn () => $route);

        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        return $request;
    }

    public function test_saving_a_category_again_keeps_its_url(): void
    {
        $service  = app(ProductCategoryService::class);
        $category = $service->store($this->request(['name' => 'Baby Care']));

        $this->assertSame('baby-care', $category->slug);

        // After each save: the old code flipped to "baby-careNULL" on one save
        // and back on the next, so checking only at the end would miss it.
        $service->update($this->request(['name' => 'Baby Care', 'description' => 'Soft'], $category), $category);
        $this->assertSame('baby-care', $category->fresh()->slug);

        $service->update($this->request(['name' => 'Baby Care', 'description' => 'Softer'], $category), $category->fresh());
        $this->assertSame('baby-care', $category->fresh()->slug);
    }

    public function test_invisible_characters_are_stripped_from_the_name(): void
    {
        $category = app(ProductCategoryService::class)->store($this->request(['name' => "\x1DSkin Care "]));

        $this->assertSame('Skin Care', $category->name);
        $this->assertSame('skin-care', $category->slug);
    }

    public function test_a_second_category_with_the_same_name_gets_a_numbered_slug(): void
    {
        $service = app(ProductCategoryService::class);
        $service->store($this->request(['name' => 'Face & Body']));
        $second = $service->store($this->request(['name' => 'Face Body']));

        $this->assertSame('face-body-2', $second->slug);
    }

    public function test_a_renamed_category_redirects_from_its_old_url(): void
    {
        $service  = app(ProductCategoryService::class);
        $category = $service->store($this->request(['name' => 'Face Wash']));

        $service->update($this->request(['name' => 'Cleanser'], $category), $category);

        $this->assertSame('cleanser', $category->fresh()->slug);
        $this->get('/product-category/face-wash')->assertRedirect(rtrim(config('app.url'), '/') . '/product-category/cleanser')->assertStatus(301);
    }

    public function test_the_migration_repairs_slugs_the_old_bug_damaged(): void
    {
        $null  = ProductCategory::create(['name' => 'Baby Care', 'slug' => 'baby-careNULL', 'status' => Status::ACTIVE]);
        $digit = ProductCategory::create(['name' => 'Skin Care', 'slug' => 'skin-care6', 'status' => Status::ACTIVE]);
        $fine  = ProductCategory::create(['name' => 'Top 10', 'slug' => 'top-10', 'status' => Status::ACTIVE]);
        $pasted = ProductCategory::create(['name' => "\x1DMen Care", 'slug' => 'men-care6', 'status' => Status::ACTIVE]);

        (require database_path('migrations/2026_09_26_000001_create_slug_redirects_and_clean_category_slugs.php'))->up();

        $this->assertSame('baby-care', $null->fresh()->slug);
        $this->assertSame('skin-care', $digit->fresh()->slug);
        $this->assertSame('top-10', $fine->fresh()->slug);
        $this->assertSame('Men Care', $pasted->fresh()->name);
        $this->assertSame('men-care', $pasted->fresh()->slug);
        $this->assertSame('baby-care', SlugRedirect::target(SlugRedirect::PRODUCT_CATEGORY, 'baby-careNULL'));

        $this->get('/product-category/skin-care6')->assertStatus(301)
            ->assertRedirect(rtrim(config('app.url'), '/') . '/product-category/skin-care');
    }
}
