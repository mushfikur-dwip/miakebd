<?php

namespace App\Models;

use App\Enums\Ask;
use App\Models\Concerns\ResolvesMediaUrls;
use Spatie\Image\Enums\Fit;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\Image\Enums\CropPosition;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Notifications\Notifiable;
use Spatie\MediaLibrary\InteractsWithMedia;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Foundation\Auth\User as Authenticatable;


class User extends Authenticatable implements HasMedia
{
    use InteractsWithMedia;
    use ResolvesMediaUrls;
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = "users";
    protected $dates = ["deleted_at"];
    protected $fillable = [
        'name',
        'email',
        'password',
        'username',
        'phone',
        'country_code',
        'is_guest',
        'status',
        'email_verified_at'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */

    protected $casts = [
        'id'                => 'integer',
        'name'              => 'string',
        'email'             => 'string',
        'password'          => 'hashed',
        'username'          => 'string',
        'phone'             => 'string',
        'country_code'      => 'string',
        'is_guest'          => 'integer',
        'status'            => 'integer',
        'email_verified_at' => 'datetime',
    ];

    /**
     * Excludes guest-checkout rows from account lookups. Several guest rows can
     * share one phone number and none of them has a usable password, so any
     * query that resolves a customer by phone or email has to filter them out
     * or it may return a guest instead of the real account.
     *
     * Written as "not a guest" so a legacy row holding 0 or NULL still matches.
     */
    /** POS orders this user was picked as "Sale By" for. */
    public function salesOrders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Order::class, 'sales_by_id', 'id');
    }

    /**
     * Staff as the Employees page defines them: any role except Admin and
     * Customer. The POS "Sale By" picker and its validation both use this, so
     * they can never disagree about who may be credited with a sale.
     */
    public function scopeEmployees($query)
    {
        return $query->whereHas('roles', fn($roles) => $roles->whereNotIn('id', [\App\Enums\Role::ADMIN, \App\Enums\Role::CUSTOMER]));
    }

    /**
     * Adds sales_count and sales_amount; see Order::scopeCountedAsSale().
     * With dates, only sales placed in that period count (the month view).
     */
    public function scopeWithSalesTotals($query, ?string $from = null, ?string $to = null)
    {
        $sales = fn($orders) => $orders->countedAsSale()->placedBetween($from, $to);

        return $query
            ->withCount(['salesOrders as sales_count' => $sales])
            ->withSum(['salesOrders as sales_amount' => $sales], 'total');
    }

    public function loadSalesTotals(?string $from = null, ?string $to = null): static
    {
        $sales = fn($orders) => $orders->countedAsSale()->placedBetween($from, $to);

        return $this
            ->loadCount(['salesOrders as sales_count' => $sales])
            ->loadSum(['salesOrders as sales_amount' => $sales], 'total');
    }

    public function scopeNotGuest($query)
    {
        return $query->where(function ($builder) {
            $builder->where('is_guest', '!=', Ask::YES)->orWhereNull('is_guest');
        });
    }

    public function getImageAttribute(): string
    {
        if (!empty($this->getFirstMediaUrl('profile'))) {
            return asset($this->getFirstMediaUrl('profile'));
        }
        return asset('images/required/profile.png');
    }

    public function getFirstNameAttribute(): string
    {
        $name = explode(' ', $this->name, 2);
        return $name[0];
    }

    public function getLastNameAttribute(): string
    {
        $name = explode(' ', $this->name, 2);
        return !empty($name[1]) ? $name[1] : '';
    }

    public function getThumbAttribute(): string
    {
        return $this->conversionUrl(
            $this->getMedia('profile')->last(),
            'thumb',
            'images/required/profile.png'
        );
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->fit(Fit::Fill, 338, 338)->keepOriginalImageFormat()->sharpen(10);
    }

    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Order::class, 'user_id', 'id');
    }

    public function addresses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Address::class);
    }


    public function getMyRoleAttribute()
    {
        return $this->roles->pluck('id', 'id')->first();
    }

    public function getrole(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\Spatie\Permission\Models\Role::class, 'id', 'myrole');
    }
    public function returnOrders()
    {
        return $this->hasMany(ReturnOrder::class, 'user_id', 'id');
    }
}
