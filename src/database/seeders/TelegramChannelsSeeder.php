<?php

namespace Database\Seeders;

use App\Models\TelegramChannel;
use Illuminate\Database\Seeder;

class TelegramChannelsSeeder extends Seeder
{
    public function run(): void
    {
        $channels = [
            'info_zp' => 'info_zp',
            'truexazaporozie' => 'truexazaporozie',
            'etozp' => 'etozp',
            'bezbaidy' => 'bezbaidy',
            'zapolife' => 'ZapoLife',
            'zaporizhzhia_times' => 'zaporizhzhia_times',
            'zaborzp' => 'zaborzp',
            'prufzp' => 'prufzp',
            'ivan_fedorov_zp' => 'ivan_fedorov_zp',
            'zoda_gov_ua' => 'Запорізька обласна державна адміністрація',
            'zp_informue' => 'zp_informue',
            'zaporizka_sish' => 'zaporizka_sish',
            'avariyka_zaporizg' => 'avariyka_zaporizg',
            'five_redakcia' => 'five_redakcia',
            'riamelitopolua' => 'riamelitopolua',
            'akzentzp' => 'akzentzp',
            'sichemo' => 'sichemo',
            'huevoe_zaporozhye' => 'huevoe_zaporozhye',
            'mozofficial' => 'mozofficial',
            'suspilnezaporizhzhya' => 'suspilnezaporizhzhya',
            'zaporozhe0' => 'zaporozhe0',
            'it_is_zp_tg' => 'it_is_zp_tg',
            'zaporozhyenews' => 'ZaporozhyeNews',
            'onenews_zp' => 'onenews_zp',
            'zapnovini' => 'zapnovini',
            'pressorh' => 'pressorh',
            'informzp1' => 'informzp1',
            'forpost_zp' => 'forpost_zp',
            'tvmtmonline' => 'tvmtmonline',
            'zvzpua' => 'zvzpua',
            'prozaporizhzhia' => 'ProZaporizhzhia',
            'media_soda' => 'media_soda',
            'migcomua' => 'migcomua',
            'medinfo_zp' => 'medinfo_zp',
            'incentre' => 'incentre',
            'eyes_everywhere_ua' => 'eyes_everywhere_ua',
            'gnilayachereha' => 'gnilayachereha',
            'radar_zaporizhzhya' => 'radar_zaporizhzhya',
            'operatyvnohlep' => 'operatyvnohlep',
        ];

        foreach ($channels as $username => $title) {
            TelegramChannel::updateOrCreate(
                ['username' => $username],
                [
                    'title' => $title,
                    'url' => 'https://t.me/'.$username,
                    'telegram_peer' => '@'.$username,
                    'enabled' => true,
                ],
            );
        }
    }
}
