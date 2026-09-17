<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScrapeSnapshot extends Model
{
    protected $fillable = [
        'from_date',
        'to_date',
        'status',
        'source_total_records',
        'source_total_pages',
        'last_seen_total_records',
        'last_seen_total_pages',
        'scraped_pages',
        'expected_files',
        'downloaded_files',
        'download_key',
        'download_dir',
        'checked_at',
        'completed_at',
        'last_used_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function files(): HasMany
    {
        return $this->hasMany(ScrapeSnapshotFile::class)->orderBy('global_index');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(ScrapeJob::class);
    }
}
