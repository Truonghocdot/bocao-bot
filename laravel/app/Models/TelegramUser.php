<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramUser extends Model
{
    protected $fillable = [
        'chat_id',
        'username',
        'first_name',
        'last_name',
    ];

    /**
     * Tìm user theo @username (không phân biệt hoa thường, không có @).
     */
    public static function findByUsername(string $username): ?self
    {
        $clean = ltrim($username, '@');

        return static::whereRaw('LOWER(username) = ?', [mb_strtolower($clean)])->first();
    }
}
