<?php

namespace App\Models;

use App\Enums\Status;
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
    use HasFactory, InteractsWithMedia, SoftDeletes;

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

    public function scopeActive($query, $col = 'status')
    {
        return $query->where($col, Status::ACTIVE);
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

    /**
     * Resolve a conversion URL, falling back to the original upload.
     *
     * getUrl('preview') builds a path whether or not that file was ever written,
     * so a conversion that failed to generate produced a 404 and a broken-image
     * icon with no fallback — the collection is not empty, so the placeholder
     * branch below never ran. hasGeneratedConversion() reads the media row's
     * already-loaded JSON column, so this costs no disk I/O on listing pages.
     *
     * A file that is missing from disk despite being marked generated is handled
     * client-side by the @error placeholder swap, which needs no stat() call.
     */
    private function conversionUrl(?Media $media, string $conversion, string $fallback): string
    {
        if (!$media) {
            return asset($fallback);
        }

        if ($media->hasGeneratedConversion($conversion)) {
            return self::encodeMediaUrl($media->getUrl($conversion));
        }

        return self::encodeMediaUrl($media->getUrl());
    }

    /**
     * Percent-encode the path of a media URL.
     *
     * Spatie builds media URLs by concatenating the stored file name straight
     * into the path, unencoded. 125 of this catalogue's product images carry
     * characters that cannot survive that: 86 contain "&" and 59 contain
     * non-ASCII (em dashes, mostly, from pasted marketing copy). The browser
     * and the server then disagree about what was requested and the image 404s.
     *
     * Second and third images were hit hardest — their names come from longer
     * descriptive text — which is why a product's main image loaded while the
     * rest of its gallery did not.
     *
     * Only the path is touched; scheme, host and port are preserved, and the
     * slashes between segments are kept as separators. Safe to apply to clean
     * names, and there is no double-encoding risk because Spatie does no
     * encoding of its own here.
     */
    private static function encodeMediaUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['path'])) {
            return $url;
        }

        $path = implode('/', array_map('rawurlencode', explode('/', $parts['path'])));

        $prefix = '';
        if (isset($parts['scheme'], $parts['host'])) {
            $prefix = $parts['scheme'] . '://' . $parts['host'];
            if (isset($parts['port'])) {
                $prefix .= ':' . $parts['port'];
            }
        }

        return $prefix . $path;
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

    public function registerMediaConversions(Media $media = null): void
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
