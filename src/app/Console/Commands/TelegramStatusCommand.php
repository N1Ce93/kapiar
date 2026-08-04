<?php

namespace App\Console\Commands;

use App\Services\Monitoring\TelegramClientService;
use danog\MadelineProto\API;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('telegram:status')]
#[Description('Show whether the Telegram user account session is authorized')]
class TelegramStatusCommand extends Command
{
    public function handle(TelegramClientService $clientService): int
    {
        $this->line('Session: '.$clientService->sessionPath());
        $this->line('Session exists: '.(is_dir($clientService->sessionPath()) ? 'yes' : 'no'));

        try {
            $client = $clientService->client();
            $authorization = $client->getAuthorization();
        } catch (Throwable $exception) {
            $message = $exception->getMessage();

            if (str_contains($message, 'AUTH_KEY_UNREGISTERED')) {
                $this->error('Telegram account is not authorized. Run: php artisan telegram:login');

                return self::FAILURE;
            }

            $this->error($message);

            return self::FAILURE;
        }

        $this->line('Authorization state: '.$this->authorizationLabel($authorization));

        if ($authorization !== API::LOGGED_IN) {
            $this->error('Telegram account is not fully authorized. Run: php artisan telegram:login');

            return self::FAILURE;
        }

        try {
            $self = $client->getSelf();
        } catch (Throwable $exception) {
            $this->warn('Authorized, but account info could not be read: '.$exception->getMessage());

            return self::SUCCESS;
        }

        if (is_array($self)) {
            $username = $self['username'] ?? null;
            $name = trim(($self['first_name'] ?? '').' '.($self['last_name'] ?? ''));

            if ($username || $name) {
                $this->line('Account: '.($username ? '@'.$username : $name));
            }

            if (isset($self['id'])) {
                $this->line('Telegram ID: '.$self['id']);
            }
        }

        $this->info('Telegram account is authorized.');

        return self::SUCCESS;
    }

    private function authorizationLabel(int $authorization): string
    {
        return match ($authorization) {
            API::LOGGED_IN => 'LOGGED_IN',
            API::NOT_LOGGED_IN => 'NOT_LOGGED_IN',
            API::WAITING_CODE => 'WAITING_CODE',
            API::WAITING_PASSWORD => 'WAITING_PASSWORD',
            API::WAITING_SIGNUP => 'WAITING_SIGNUP',
            API::LOGGED_OUT => 'LOGGED_OUT',
            default => 'UNKNOWN('.$authorization.')',
        };
    }
}
