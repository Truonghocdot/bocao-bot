<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapeSnapshotFile extends Model
{
    protected $fillable = [
        'scrape_snapshot_id',
        'page_number',
        'global_index',
        'company_name',
        'filename',
        'relative_path',
        'size_bytes',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ScrapeSnapshot::class, 'scrape_snapshot_id');
    }

    public function absolutePath(): string
    {
        return rtrim((string) $this->snapshot->download_dir, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.$this->relative_path;
    }
}
