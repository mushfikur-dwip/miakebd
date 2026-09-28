<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashCount extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'outlet_id',
        'account',
        'expected',
        'counted',
        'variance',
        'denominations',
        'entry_id',
        'counted_by_id',
        'created_by',
    ];

    protected $casts = [
        'id'            => 'integer',
        'outlet_id'     => 'integer',
        'account'       => 'integer',
        'expected'      => 'float',
        'counted'       => 'float',
        'variance'      => 'float',
        'denominations' => 'array',
        'entry_id'      => 'integer',
        'counted_by_id' => 'integer',
        'created_by'    => 'integer',
        'created_at'    => 'datetime',
    ];

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    // The employee who counted the notes, as picked on the count form.
    public function countedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by_id', 'id');
    }
}
