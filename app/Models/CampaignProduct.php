<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignProduct extends Model
{
    protected $table = 'campaign_products';

    protected $fillable = ['campaign_id', 'product_id', 'special_price'];

    protected $casts = [
        'id'            => 'integer',
        'campaign_id'   => 'integer',
        'product_id'    => 'integer',
        'special_price' => 'float',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id', 'id');
    }

    /**
     * withTrashed so the admin list still names a product that was soft
     * deleted after being added — an unnamed row is impossible to clean up.
     * The public page filters deleted products out separately.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id')->withTrashed();
    }
}
