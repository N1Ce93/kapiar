<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailSubjectKeyword extends Model
{
    public const RESERVED_LABELS = [
        'CHAT',
        'SENT',
        'INBOX',
        'IMPORTANT',
        'TRASH',
        'DRAFT',
        'SPAM',
        'STARRED',
        'UNREAD',
        'CATEGORY_PERSONAL',
        'CATEGORY_SOCIAL',
        'CATEGORY_PROMOTIONS',
        'CATEGORY_UPDATES',
        'CATEGORY_FORUMS',
    ];

    protected $fillable = [
        'phrase',
        'label_name',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public static function isReservedLabel(string $name): bool
    {
        return in_array(mb_strtoupper(trim($name), 'UTF-8'), self::RESERVED_LABELS, true);
    }
}
