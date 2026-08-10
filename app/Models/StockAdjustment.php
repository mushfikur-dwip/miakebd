<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'from_outlet_id',
        'to_outlet_id',
        'date',
        'reference_no',
        'note',
        'creator_type',
        'creator_id',
    ];

    protected $casts = [
        'id'             => 'integer',
        'type'           => 'integer',
        'from_outlet_id' => 'integer',
        'to_outlet_id'   => 'integer',
        'date'           => 'datetime',
        'reference_no'   => 'string',
        'note'           => 'string',
    ];

    public function stocks(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Stock::class, 'model');
    }

    public function fromOutlet(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'from_outlet_id', 'id');
    }

    public function toOutlet(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'to_outlet_id', 'id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id', 'id');
    }
}
