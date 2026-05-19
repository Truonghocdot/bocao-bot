<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScrapeJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_id',
        'status',
        'from_date',
        'to_date',
        'max_records',
        'downloaded_count',
        'zip_path',
        'error_message',
    ];
}
