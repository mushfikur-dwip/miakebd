<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One movement of money in one branch account.
 *
 * Append-only: a row is never edited or deleted, by anyone. A wrong entry is
 * put right with a reversal row pointing at it, so the history always shows
 * both what happened and who corrected it.
 */
class CashEntry extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'outlet_id',
        'account',
        'type',
        'amount',
        'balance_after',
        'order_id',
        'reverses_id',
        'group_ref',
        'reference',
        'party',
        'note',
        'created_by',
        'ip',
    ];

    protected $casts = [
        'id'            => 'integer',
        'outlet_id'     => 'integer',
        'account'       => 'integer',
        'type'          => 'integer',
        'amount'        => 'float',
        'balance_after' => 'float',
        'order_id'      => 'integer',
        'reverses_id'   => 'integer',
        'created_by'    => 'integer',
        'created_at'    => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Cash entries cannot be changed. Post a reversal instead.');
        });

        static::deleting(function () {
            throw new LogicException('Cash entries cannot be deleted. Post a reversal instead.');
        });
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function reversedBy(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CashEntry::class, 'reverses_id', 'id');
    }
}
