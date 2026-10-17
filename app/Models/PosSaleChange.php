<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One correction of a till sale: the whole sale reversed, or its payment
 * method changed. Append-only, like the cash ledger - the record of who
 * changed a sale, and why, can never itself be changed.
 */
class PosSaleChange extends Model
{
    const UPDATED_AT = null;

    public const REVERSE = 'reverse';
    public const METHOD  = 'method';

    protected $fillable = [
        'order_id',
        'outlet_id',
        'action',
        'amount',
        'from_method',
        'to_method',
        'from_note',
        'to_note',
        'reason',
        'created_by',
        'ip',
    ];

    protected $casts = [
        'id'          => 'integer',
        'order_id'    => 'integer',
        'outlet_id'   => 'integer',
        'amount'      => 'float',
        'from_method' => 'integer',
        'to_method'   => 'integer',
        'created_by'  => 'integer',
        'created_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('A sale correction is a record; it cannot be changed.');
        });

        static::deleting(function () {
            throw new LogicException('A sale correction is a record; it cannot be deleted.');
        });
    }

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function outlet(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }
}
