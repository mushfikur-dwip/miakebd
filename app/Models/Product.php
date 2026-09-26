<?php

namespace App\Models;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Concerns\HasOutletStock;
use App\Models\Concerns\ResolvesMediaUrls;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Illuminate\Support\Facades\Auth;
use Spatie\Image\Enums\CropPosition;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\InteractsWithMedia;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Product extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes, HasOutletStock, ResolvesMediaUrls;

    protected $table = "products";
    protected $fillable = [
        'name',
        'slug',
        'sku',
        'product_category_id',
        'product_brand_id',
        'barcode_id',
        'unit_id',
        'buying_price',
        'selling_price',
        'variation_price',
        'status',
        'order',
        'can_purchasable',
        'pos_only',
        'show_stock_out',
        'maximum_purchase_quantity',
        'low_stock_quantity_warning',
        'weight',
        'warranty',
        'refundable',
        'description',
        'shipping_and_return',
        'add_to_flash_sale',
        'discount',
        'offer_start_date',
        'offer_end_date',
        'shipping_type',
        'shipping_cost',
        'is_product_quantity_multiply',

    ];
    protected array $dates = ['deleted_at'];
    protected $casts = [
        'id'                           => 'integer',
        'name'                         => 'string',
        'slug'                         => 'string',
        'sku'                          => 'string',
        'product_category_id'          => 'integer',
        'product_brand_id'             => 'integer',
        'barcode_id'                   => 'integer',
        'unit_id'                      => 'integer',
        'buying_price'                 => 'decimal:6',
        'selling_price'                => 'decimal:6',
        'variation_price'              => 'decimal:6',
        'status'                       => 'integer',
        'order'                        => 'integer',
        'can_purchasable'              => 'integer',
        'show_stock_out'               => 'integer',
        'maximum_purchase_quantity'    => 'integer',
        'low_stock_quantity_warning'   => 'integer',
        'weight'                       => 'string',
        'warranty'                     => 'string',
        'refundable'                   => 'integer',
        'description'                  => 'string',
        'shipping_and_return'          => 'string',
        'add_to_flash_sale'            => 'integer',
        'discount'                     => 'decimal:6',
        'offer_start_date'             => 'string',
        'offer_end_date'               => 'string',
        'shipping_type'                => 'integer',
        'shipping_cost'                => 'string',
        'is_product_quantity_multiply' => 'integer',

    ];

    /**
     * Products the website may show.
     *
     * A product marked "POS only" is stocked in the shop and sellable at the
     * till, but must not appear anywhere the public can reach: listings,
     * search, category and brand pages, flash sale, campaigns, promotions,
     * sections, related products, the sitemap, or its own URL.
     *
     * Written as "anything that is not explicitly YES", so a row that predates
     * the column, or one a bulk import left NULL, stays public. Hiding is
     * always the deliberate choice.
     */
    public function scopeStorefront($query)
    {
        return $query->where(function ($query) {
            $query->where('pos_only', '!=', Ask::YES)->orWhereNull('pos_only');
        });
    }

    public function scopeActive($query, $col = 'status')
    {
        return $query->where($col, Status::ACTIVE);
    }

    /**
     * Products that have a photo before products that do not.
     *
     * More than half the catalogue (650 of 1,146 in September 2026) had no
     * photo yet, and the default A-Z order put rows of "No Image Available"
     * tiles at the top of category pages - the first thing an ad click saw.
     * Applied ahead of the chosen order, so within each group that order still
     * holds. Callers use it only where the shopper did not ask for a specific
     * sort: a price sort must stay strictly by price.
     */
    public function scopePhotosFirst($query)
    {
        return $query->orderByRaw(
            'CASE WHEN EXISTS (SELECT 1 FROM media WHERE media.model_type = ? AND media.model_id = products.id AND media.collection_name = ?) THEN 0 ELSE 1 END',
            [$this->getMorphClass(), 'product']
        );
    }

    public function scopeRandAndLimitOrOrderBy($query, $rand = 0, $orderColumn = 'id', $orderType = 'asc')
    {
        if ($rand > 0) {
            return $query->inRandomOrder()->limit($rand);
        }
        return $query->orderBy($orderColumn, $orderType);
    }

    public function getImageAttribute(): string
    {
        if (!empty($this->getFirstMediaUrl('product'))) {
            return asset($this->getFirstMediaUrl('product'));
        }
        return asset('images/default/product/thumb.png');
    }

    public function getImagesAttribute(): array
    {
        $response = [];
        if (!empty($this->getFirstMediaUrl('product'))) {
            $images = $this->getMedia('product');
            foreach ($images as $image) {
                $response[] = $image['original_url'];
            }
        }
        return $response;
    }

    public function getThumbAttribute(): string
    {
        return $this->conversionUrl(
            $this->getMedia('product')->first(),
            'thumb',
            'images/default/product/thumb.png'
        );
    }

    public function getCoverAttribute(): string
    {
        return $this->conversionUrl(
            $this->getMedia('product')->first(),
            'cover',
            'images/default/product/cover.png'
        );
    }

    public function getPreviewAttribute(): string
    {
        return $this->conversionUrl(
            $this->getMedia('product')->first(),
            'preview',
            'images/default/product/preview.png'
        );
    }

    public function getPreviewsAttribute(): array
    {
        $response = [];
        foreach ($this->getMedia('product') as $image) {
            $response[] = $this->conversionUrl($image, 'preview', 'images/default/product/preview.png');
        }
        return $response;
    }

    /**
     * The admin gallery: same images as `previews`, but carrying the media id so
     * the order can be changed. Media order_column drives which image is the
     * hero — every accessor above, and the storefront, take ->first().
     */
    public function getGalleryAttribute(): array
    {
        $response = [];
        foreach ($this->getMedia('product') as $index => $image) {
            $response[] = [
                'id' => $image->id,
                // 'cover' (372x405), NOT 'preview' (1536x1536). Two reasons:
                //
                // 1. The preview files are missing from disk for much of the
                //    catalogue — generated_conversions still flags them as
                //    present, so hasGeneratedConversion() cannot detect it, and
                //    the admin gallery 404'd while the storefront rendered fine
                //    because the storefront only ever asks for cover/thumb.
                // 2. Even when present, a 1536px file for a 480px pane is ~15x
                //    the bytes for no visible gain.
                'url'     => $this->conversionUrl($image, 'cover', 'images/default/product/cover.png'),
                'thumb'   => $this->conversionUrl($image, 'thumb', 'images/default/product/thumb.png'),
                'is_hero' => $index === 0,
            ];
        }
        return $response;
    }

    public function getBarcodeImageAttribute(): string
    {
        if (!empty($this->getFirstMediaUrl('product-barcode'))) {
            return asset($this->getFirstMediaUrl('product-barcode'));
        }
        return '';
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->width(168)->height(180)->keepOriginalImageFormat()->sharpen(10);
        $this->addMediaConversion('cover')->width(372)->height(405)->keepOriginalImageFormat()->sharpen(10);
        $this->addMediaConversion('preview')->width(1536)->height(1536)->keepOriginalImageFormat()->sharpen(10);
    }

    public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id', 'id');
    }

    public function brand(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ProductBrand::class, 'product_brand_id', 'id');
    }

    public function barcode(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Barcode::class, 'barcode_id', 'id');
    }

    public function unit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'id');
    }

    public function variations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductVariation::class)->with('productAttribute');
    }

    public function orders(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Stock::class, 'model');
    }

    public function orderCountable(): HasMany
    {
        return $this->hasMany(Stock::class, 'product_id', 'id');
    }

    public function tags(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductTag::class, 'product_id', 'id');
    }

    public function reviews(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductReview::class, 'product_id', 'id');
    }

    public function videos(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductVideo::class, 'product_id', 'id');
    }

    public function seo(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ProductSeo::class, 'product_id', 'id');
    }

    public function scopeWithReviewRating($query)
    {
        $reviewsStar      = ProductReview::selectRaw('sum(star)')->whereColumn('product_id', 'products.id')->getQuery();
        $reviewsStarCount = ProductReview::selectRaw('count(product_id)')->whereColumn('product_id', 'products.id')->getQuery();
        $base             = $query->getQuery();
        if (is_null($base->columns)) {
            $query->select([$base->from . '.*']);
        }
        return $query->selectSub($reviewsStar, 'rating_star')->selectSub($reviewsStarCount, 'rating_star_count');
    }

    public function wishlist()
    {
        return $this->hasOne(Wishlist::class);
    }

    public function averageRating()
    {
        return $this->reviews()->avg('star');
    }

    public function reviewCount(): int
    {
        return $this->reviews()->count();
    }

    public function stocks(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Stock::class, 'item');
    }

    public function stockItems(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->stocks()->where('status', Status::ACTIVE);
    }

    public function taxes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductTax::class, 'product_id', 'id');
    }

    public function productTaxes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductTax::class);
    }

    public function productOrders(): HasMany
    {
        return $this->hasMany(Stock::class, 'product_id', 'id')->where('model_type', Order::class);
    }

    public function userReview(): \Illuminate\Database\Eloquent\Relations\hasOne
    {
        // Auth::user() is null for guests; guard so loading this relation on a
        // public page can't fatal on ->id of null.
        return $this->hasOne(ProductReview::class, 'product_id', 'id')->where('user_id', Auth::check() ? Auth::user()->id : 0);
    }
}
