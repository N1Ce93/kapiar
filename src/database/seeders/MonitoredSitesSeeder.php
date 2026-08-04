<?php

namespace Database\Seeders;

use App\Models\MonitoredSite;
use Illuminate\Database\Seeder;

class MonitoredSitesSeeder extends Seeder
{
    public function run(): void
    {
        $sites = [
            [
                'name' => 'Перший Запорізький',
                'base_url' => 'https://1news.zp.ua/',
                'source_type' => 'rss',
                'feed_url' => 'https://1news.zp.ua/feed/',
            ],
            [
                'name' => 'Газета МИГ',
                'base_url' => 'https://mig.com.ua/',
                'source_type' => 'rss',
                'feed_url' => 'https://mig.com.ua/feed/',
            ],
            [
                'name' => '061.ua',
                'base_url' => 'https://www.061.ua/',
                'source_type' => 'rss',
                'feed_url' => 'https://www.061.ua/rss',
            ],
            [
                'name' => 'ZPRZ.CITY',
                'base_url' => 'https://zprz.city/',
                'source_type' => 'rss',
                'feed_url' => 'https://zprz.city/feed',
            ],
            [
                'name' => 'Inform.zp.ua',
                'base_url' => 'https://www.inform.zp.ua/ru/',
                'source_type' => 'rss',
                'feed_url' => 'https://www.inform.zp.ua/ru/feed/',
            ],
            [
                'name' => 'SODA',
                'base_url' => 'https://www.soda.zp.ua/',
                'source_type' => 'rss',
                'feed_url' => 'https://www.soda.zp.ua/feed/',
            ],
            [
                'name' => 'ЗаБор',
                'base_url' => 'https://zabor.zp.ua/',
                'source_type' => 'html',
                'listing_url' => 'https://zabor.zp.ua/news',
            ],
        ];

        foreach ($sites as $site) {
            MonitoredSite::updateOrCreate(
                ['base_url' => $site['base_url']],
                $site + ['enabled' => true],
            );
        }
    }
}
