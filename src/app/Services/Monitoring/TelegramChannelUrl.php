<?php

namespace App\Services\Monitoring;

use InvalidArgumentException;

class TelegramChannelUrl
{
    /** @return array{username:string,url:string,peer:string} */
    public static function parse(string $value): array
    {
        $value = trim($value);
        $value = rtrim($value, '/');

        if (preg_match('~^https?://t\.me/([A-Za-z0-9_]+)$~i', $value, $match)) {
            $username = $match[1];
        } elseif (preg_match('~^@?([A-Za-z0-9_]{5,})$~', $value, $match)) {
            $username = $match[1];
        } else {
            throw new InvalidArgumentException('Telegram channel must be a public @username or https://t.me/username URL.');
        }

        return [
            'username' => strtolower($username),
            'url' => 'https://t.me/'.strtolower($username),
            'peer' => '@'.strtolower($username),
        ];
    }
}
