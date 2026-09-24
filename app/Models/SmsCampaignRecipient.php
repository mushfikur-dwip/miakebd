<?php

namespace App\Models;

use App\Enums\SmsRecipientStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SmsCampaignRecipient extends Model
{
    protected $table = "sms_campaign_recipients";

    protected $fillable = [
        'sms_campaign_id', 'user_id', 'name', 'country_code', 'phone', 'status', 'error'
    ];

    protected $casts = [
        'id'              => 'integer',
        'sms_campaign_id' => 'integer',
        'user_id'         => 'integer',
        'status'          => 'integer',
    ];

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', SmsRecipientStatus::PENDING);
    }
}
