<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $table = "transactions";
    protected $fillable = ['order_id', 'transaction_no', 'amount', 'payment_method', 'type', 'sign', 'user_id', 'admin_id', 'note', 'balance_before', 'balance_after'];
    protected $casts = [
        'id'             => 'integer',
        'order_id'       => 'integer',
        'transaction_no' => 'string',
        'amount'         => 'decimal:6',
        'payment_method' => 'string',
        'type'           => 'string',
        'sign'           => 'string',
        'user_id'        => 'integer',
        'admin_id'       => 'integer',
        'note'           => 'string',
        'balance_before' => 'decimal:6',
        'balance_after'  => 'decimal:6',
    ];

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function admin(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id', 'id');
    }
}
