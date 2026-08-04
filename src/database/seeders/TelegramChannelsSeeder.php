<?php

namespace Database\Seeders;

use App\Models\TelegramChannel;
use Illuminate\Database\Seeder;

class TelegramChannelsSeeder extends Seeder
{
    public function run(): void
    {
        TelegramChannel::updateOrCreate(
            ['username' => 'zoda_gov_ua'],
            [
                'title' => 'Запорізька обласна державна адміністрація',
                'url' => 'https://t.me/zoda_gov_ua',
                'telegram_peer' => '@zoda_gov_ua',
                'enabled' => true,
            ],
        );
    }
}
