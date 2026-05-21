<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramChat extends Model
{
    protected $fillable = [
        'chat_id',
        'type',
        'title',
        'username',
        'is_bot_member',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'is_bot_member' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public static function findByUsername(string $username): ?self
    {
        $clean = ltrim($username, '@');

        return static::whereRaw('LOWER(username) = ?', [mb_strtolower($clean)])->first();
    }

    public function displayLabel(): string
    {
        if ($this->username) {
            return '@' . $this->username;
        }

        if ($this->title) {
            return $this->title;
        }

        return $this->chat_id;
    }

    public function isGroupLike(): bool
    {
        return in_array($this->type, ['group', 'supergroup'], true);
    }
}
