<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashPinFailure extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['user_id', 'outlet_id', 'action', 'ip'];

    protected $casts = [
        'id'         => 'integer',
        'user_id'    => 'integer',
        'outlet_id'  => 'integer',
        'created_at' => 'datetime',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
