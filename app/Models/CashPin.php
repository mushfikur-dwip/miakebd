<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashPin extends Model
{
    protected $fillable = ['outlet_id', 'pin_hash', 'updated_by'];

    protected $hidden = ['pin_hash'];

    protected $casts = [
        'id'         => 'integer',
        'outlet_id'  => 'integer',
        'updated_by' => 'integer',
    ];
}
