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
        'target_chat_ids',
        'scrape_schedule_id',
        'scrape_snapshot_id',
        'status',
        'from_date',
        'to_date',
        'max_records',
        'download_key',
        'download_dir',
        'downloaded_count',
        'delivery_cursor',
        'sent_count',
        'failed_count',
        'zip_path',
        'delivered_at',
        'error_message',
        'delivery_error_message',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'target_chat_ids' => 'array',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ScrapeSchedule::class, 'scrape_schedule_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ScrapeSnapshot::class, 'scrape_snapshot_id');
    }
}
