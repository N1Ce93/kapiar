<?php

namespace App\Console\Commands;

use App\Services\Monitoring\TelegramClientService;
use danog\MadelineProto\API;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('telegram:login
    {--phone= : Telegram phone number in international format}
    {--code= : Telegram login code, if already requested}
    {--password= : Telegram 2FA password, if enabled}')]
#[Description('Authorize the Telegram user account used to read public channels')]
class TelegramLoginCommand extends Command
{
    public function handle(TelegramClientService $clientService): int
    {
        try {
            $this->warn('Before login, it is better to stop scheduler: docker compose stop marketing-scheduler');
            $this->info('Starting Telegram account authorization.');

            $client = $clientService->client();
            $authorization = $client->getAuthorization();

            if ($authorization === API::NOT_LOGGED_IN || $authorization === API::LOGGED_OUT) {
                $phone = $this->option('phone') ?: $this->ask('Telegram phone number, for example +380XXXXXXXXX');
                $client->phoneLogin((string) $phone);
                $authorization = $client->getAuthorization();
            }

            if ($authorization === API::WAITING_CODE) {
                $code = $this->option('code') ?: $this->ask('Telegram login code');
                $client->completePhoneLogin((string) $code);
                $authorization = $client->getAuthorization();
            }

            if ($authorization === API::WAITING_PASSWORD) {
                $password = $this->option('password') ?: $this->secret('Telegram 2FA password');
                $client->complete2faLogin((string) $password);
                $authorization = $client->getAuthorization();
            }

            if ($authorization === API::WAITING_SIGNUP) {
                $firstName = $this->ask('First name');
                $lastName = $this->ask('Last name', '');
                $client->completeSignup((string) $firstName, (string) $lastName);
                $authorization = $client->getAuthorization();
            }

            if ($authorization !== API::LOGGED_IN) {
                $this->error('Telegram account is not fully authorized. Current state: '.$this->authorizationLabel($authorization));

                return self::FAILURE;
            }

            $self = $client->getSelf();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Telegram account is authorized.');

        if (is_array($self)) {
            $username = $self['username'] ?? null;
            $name = trim(($self['first_name'] ?? '').' '.($self['last_name'] ?? ''));

            if ($username || $name) {
                $this->line('Account: '.($username ? '@'.$username : $name));
            }

            if (isset($self['id'])) {
                $this->line('Telegram ID: '.$self['id']);
            }

            $this->line('Session: '.$clientService->sessionPath());
        }

        $this->line('You can verify login with: php artisan telegram:status');

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
