<?php

namespace App\Models;

use App\Enums\Status;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    protected $table = 'campaigns';

    protected $fillable = [
        'name',
        'slug',
        'type',
        'starts_at',
        'ends_at',
        'status',
    ];

    protected $casts = [
        'id'        => 'integer',
        'name'      => 'string',
        'slug'      => 'string',
        'type'      => 'integer',
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
        'status'    => 'integer',
    ];

    /**
     * Products in this campaign, carrying the hand-entered special price.
     *
     * `withPivot` is what makes `$product->pivot->special_price` available —
     * without it the page would fall back to the retail price and the whole
     * feature would silently do nothing.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'campaign_products')
            ->withPivot('special_price');
    }

    public function campaignProducts(): HasMany
    {
        return $this->hasMany(CampaignProduct::class, 'campaign_id', 'id');
    }

    /**
     * Everything the public site is allowed to price.
     *
     * All three conditions matter. `status` is the admin's on/off switch;
     * the window is what makes a campaign expire on its own. Filtering on
     * status alone would keep charging the special price forever, which is
     * exactly the failure a clearance sale must not have.
     *
     * `now()` is Asia/Dhaka (TIMEZONE in .env) and so are the stored
     * timestamps, so no conversion is needed here.
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('campaigns.status', Status::ACTIVE)
            ->where('campaigns.starts_at', '<=', now())
            ->where('campaigns.ends_at', '>=', now());
    }

    /** Whether this loaded row is inside its active window right now. */
    public function getIsRunningAttribute(): bool
    {
        return (int) $this->status === Status::ACTIVE
            && $this->starts_at !== null
            && $this->ends_at !== null
            && $this->starts_at->lte(now())
            && $this->ends_at->gte(now());
    }
}
