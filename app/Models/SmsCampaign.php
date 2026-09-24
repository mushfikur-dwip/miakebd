<?php

namespace App\Models;

use App\Enums\SmsCampaignStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmsCampaign extends Model
{
    protected $table = "sms_campaigns";

    protected $fillable = ['title', 'message', 'status', 'total_count', 'sent_count', 'failed_count'];

    protected $casts = [
        'id'           => 'integer',
        'title'        => 'string',
        'message'      => 'string',
        'status'       => 'integer',
        'total_count'  => 'integer',
        'sent_count'   => 'integer',
        'failed_count' => 'integer',
    ];

    public function recipients(): HasMany
    {
        return $this->hasMany(SmsCampaignRecipient::class, 'sms_campaign_id', 'id');
    }

    public function isFinished(): bool
    {
        return $this->status === SmsCampaignStatus::COMPLETED;
    }
}
