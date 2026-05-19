<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScrapeSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_id',
        'is_active',
        'cron_expression',
        'days_back',
        'max_records',
    ];
}
