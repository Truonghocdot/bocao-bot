<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScrapeSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_id',
        'target_chat_id',
        'from_date',
        'to_date',
        'is_active',
        'cron_expression',
        'days_back',
        'max_records',
        'last_download_key',
        'last_download_dir',
    ];

    public function jobs(): HasMany
    {
        return $this->hasMany(ScrapeJob::class);
    }
}
