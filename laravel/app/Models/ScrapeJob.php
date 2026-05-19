<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapeJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_id',
        'target_chat_id',
        'scrape_schedule_id',
        'status',
        'from_date',
        'to_date',
        'max_records',
        'download_key',
        'download_dir',
        'downloaded_count',
        'zip_path',
        'delivered_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ScrapeSchedule::class, 'scrape_schedule_id');
    }
}
