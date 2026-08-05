<?php

namespace App\Services\Monitoring;

use danog\MadelineProto\API;
use danog\MadelineProto\Logger as MadelineLogger;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings\Logger as LoggerSettings;
use RuntimeException;

class TelegramClientService
{
    public function client(): API
    {
        $apiId = config('services.telegram.api_id');
        $apiHash = config('services.telegram.api_hash');

        if (! $apiId || ! $apiHash) {
            throw new RuntimeException('Fill TELEGRAM_API_ID and TELEGRAM_API_HASH in src/.env before using Telegram Client API.');
        }

        $sessionPath = $this->sessionPath();
        $sessionDir = dirname($sessionPath);

        if (! is_dir($sessionDir)) {
            mkdir($sessionDir, 0775, true);
        }

        $settings = new Settings();
        $settings->setAppInfo((new AppInfo())
            ->setApiId((int) $apiId)
            ->setApiHash((string) $apiHash));
        $settings->setLogger((new LoggerSettings())
            ->setType(MadelineLogger::LOGGER_FILE)
            ->setExtra(storage_path('logs/madelineproto.log'))
            ->setLevel(MadelineLogger::LEVEL_FATAL));

        return new API($sessionPath, $settings);
    }

    public function start(): array
    {
        return $this->client()->start();
    }

    public function sessionPath(): string
    {
        $path = (string) config('services.telegram.session');

        if ($path === '') {
            $path = storage_path('app/telegram/client.session');
        }

        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
