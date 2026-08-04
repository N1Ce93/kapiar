<?php

namespace Database\Seeders;

use App\Models\MonitoredSite;
use App\Services\Monitoring\UrlHelper;
use Illuminate\Database\Seeder;

class MonitoredSitesSeeder extends Seeder
{
    public function run(): void
    {
        $sites = [
            [
                'name' => '061.ua',
                'url' => 'https://www.061.ua/',
                'source_type' => 'rss',
                'feed_url' => 'https://www.061.ua/rss',
            ],
            [
                'name' => 'Inform.zp.ua',
                'url' => 'https://www.inform.zp.ua/',
            ],
            [
                'name' => 'Forpost',
                'url' => 'https://forpost.media/',
            ],
            [
                'name' => 'Акцент',
                'url' => 'https://akzent.zp.ua/',
            ],
            [
                'name' => 'Перший Запорізький',
                'url' => 'https://1news.zp.ua/',
                'source_type' => 'rss',
                'feed_url' => 'https://1news.zp.ua/feed/',
            ],
            [
                'name' => 'Газета МИГ',
                'url' => 'https://mig.com.ua/',
                'source_type' => 'rss',
                'feed_url' => 'https://mig.com.ua/feed/',
            ],
            [
                'name' => 'Індустріалка',
                'url' => 'https://iz.com.ua/',
            ],
            [
                'name' => 'VERGE',
                'url' => 'https://verge.zp.ua/',
            ],
            [
                'name' => 'Reporter UA',
                'url' => 'https://reporter-ua.com/',
            ],
            [
                'name' => 'Паноптикон',
                'url' => 'https://panoptikon.org/',
            ],
            [
                'name' => 'ZPRZ.CITY',
                'url' => 'https://zprz.city/',
                'source_type' => 'rss',
                'feed_url' => 'https://zprz.city/feed',
            ],
            [
                'name' => 'Суббота',
                'url' => 'https://subbota.ua/',
            ],
            [
                'name' => 'Позиция',
                'url' => 'https://poziciya.com.ua/',
            ],
            [
                'name' => 'Запорізька Правда',
                'url' => 'https://zv.zp.ua/',
            ],
            [
                'name' => 'ЗП Правда',
                'url' => 'https://zp-pravda.info/',
            ],
            [
                'name' => 'Січ',
                'url' => 'https://sich.zp.ua/',
            ],
            [
                'name' => 'Місто Запоріжжя',
                'url' => 'https://misto.zp.ua/',
            ],
            [
                'name' => 'TV5',
                'url' => 'https://tv5.zp.ua/',
            ],
            [
                'name' => 'Суспільне Запоріжжя',
                'url' => 'https://suspilne.media/zaporizhzhia/',
            ],
            [
                'name' => 'Локатор Медіа',
                'url' => 'https://lokatormedia.online/',
            ],
            [
                'name' => 'ЗаБор',
                'url' => 'https://zabor.zp.ua/',
                'source_type' => 'html',
                'listing_url' => 'https://zabor.zp.ua/news',
            ],
            ['name' => 'Depo Запоріжжя', 'url' => 'https://depo.ua/ukr/zaporizhzhya'],
            ['name' => 'RegionNews Запоріжжя', 'url' => 'https://regionnews.net.ua/zaporizhzhya/'],
            ['name' => 'News Blog', 'url' => 'https://news.blog.net.ua/'],
            ['name' => 'Про Бердянськ', 'url' => 'https://pro.berdiansk.biz/'],
            ['name' => 'Бердянськ 24', 'url' => 'https://berdiansk24.com/'],
            ['name' => 'Город Online', 'url' => 'https://gorod-online.net/'],
            ['name' => 'МВ', 'url' => 'https://mv.org.ua/'],
            ['name' => 'РИА Мелитополь', 'url' => 'https://ria-m.tv/'],
            ['name' => 'Енергодар News', 'url' => 'https://energodar.news/'],
            ['name' => 'EN Media', 'url' => 'https://en-media.tv/'],
            ['name' => 'Пологи Today', 'url' => 'https://pology.today/'],
            ['name' => 'Василівка.City', 'url' => 'https://vasilievka.city/'],
            ['name' => 'Промінь', 'url' => 'https://promin.media/'],
            ['name' => 'Справжнє', 'url' => 'https://spravzhne.media/'],
            ['name' => 'Sky Запоріжжя', 'url' => 'https://sky.zp.ua/'],
            ['name' => 'Infolight', 'url' => 'https://infolight.ua/'],
            ['name' => 'SODA', 'url' => 'https://soda.zp.ua/'],
            ['name' => 'MedInfo', 'url' => 'https://medinfo.zp.ua/'],
            ['name' => 'Відбудова Запоріжжя', 'url' => 'https://vidbudova.zp.ua/'],
            ['name' => 'Вместе', 'url' => 'https://vmestezp.org/'],
            ['name' => 'Запорізька Русь', 'url' => 'https://zr.zp.ua/'],
            ['name' => '24 Запоріжжя', 'url' => 'https://24.zp.ua/'],
            ['name' => 'Горожанин', 'url' => 'https://gorozhanin.info/'],
            ['name' => 'Big Kyiv', 'url' => 'https://bigkyiv.com.ua/'],
            ['name' => 'Укрінформ', 'url' => 'https://www.ukrinform.ua/'],
            ['name' => 'Рубрика', 'url' => 'https://rubryka.com/'],
            ['name' => '1+1', 'url' => 'https://1plus1.ua/'],
            ['name' => 'ICTV', 'url' => 'https://ictv.ua/'],
            ['name' => 'Вікна', 'url' => 'https://vikna.tv/'],
            ['name' => 'МОЗ України', 'url' => 'https://moz.gov.ua/'],
            ['name' => 'ДОЗ ЗОДА', 'url' => 'https://doz.zoda.gov.ua/'],
            ['name' => 'День', 'url' => 'https://day.kyiv.ua/'],
            ['name' => 'Преступности.НЕТ', 'url' => 'https://news.pn/'],
            ['name' => 'СтопКор', 'url' => 'https://stopcor.org/'],
            ['name' => 'Мальва TV', 'url' => 'https://malva.tv/'],
            ['name' => 'Репортер', 'url' => 'https://reporter.com.ua/'],
            ['name' => 'Апостроф', 'url' => 'https://apostrophe.ua/'],
            ['name' => 'Eplus', 'url' => 'https://eplus.com.ua/'],
            ['name' => 'РБК-Україна', 'url' => 'https://www.rbc.ua/ukr/'],
            ['name' => 'Інтерфакс-Україна', 'url' => 'https://interfax.com.ua/'],
            ['name' => 'УНІАН', 'url' => 'https://www.unian.ua/'],
            ['name' => 'Українська правда', 'url' => 'https://www.pravda.com.ua/'],
            ['name' => 'LB.ua', 'url' => 'https://lb.ua/'],
            ['name' => 'Радіо Свобода', 'url' => 'https://www.radiosvoboda.org/'],
            ['name' => 'Слово і діло', 'url' => 'https://www.slovoidilo.ua/'],
            ['name' => 'Запорізька міська рада', 'url' => 'https://zp.gov.ua/'],
            ['name' => 'Запорізька обласна рада', 'url' => 'https://zor.gov.ua/'],
            ['name' => 'Запорізька ОДА', 'url' => 'https://www.zoda.gov.ua/'],
            ['name' => 'Департамент охорони здоров’я ЗМР', 'url' => 'https://zp.gov.ua/department/6812'],
            ['name' => 'НСЗУ', 'url' => 'https://nszu.gov.ua/'],
            ['name' => 'Український центр трансплант-координації', 'url' => 'https://utcc.gov.ua/'],
            ['name' => 'Регіональні центри трансплантації', 'url' => 'https://utcc.gov.ua/regionalcenters/'],
            ['name' => 'УЦТК Запоріжжя', 'url' => 'https://utcc.gov.ua/regionalcenters/zaporizhzhia/'],
            ['name' => 'ДЕЦ МОЗ України', 'url' => 'https://www.dec.gov.ua/'],
            ['name' => 'Центр громадського здоров’я', 'url' => 'https://phc.org.ua/'],
            ['name' => 'Ліки Контроль', 'url' => 'https://likicontrol.com.ua/'],
            ['name' => 'Медична справа', 'url' => 'https://medplatforma.com.ua/'],
            ['name' => 'Український медичний часопис', 'url' => 'https://www.umj.com.ua/'],
            ['name' => 'Health-ua', 'url' => 'https://www.health-ua.com/'],
            ['name' => 'The Pharma Media', 'url' => 'https://www.thepharma.media/'],
            ['name' => 'Аптека', 'url' => 'https://www.apteka.ua/'],
            ['name' => 'Медична Україна', 'url' => 'https://medukr.org/'],
        ];

        foreach ($sites as $site) {
            $baseUrl = UrlHelper::normalizeBaseUrl($site['url']);
            $existing = MonitoredSite::query()->where('base_url', $baseUrl)->first();
            $sourceType = $site['source_type'] ?? $existing?->source_type ?? 'html';
            $feedUrl = $site['feed_url'] ?? $existing?->feed_url;
            $listingUrl = $site['listing_url'] ?? $existing?->listing_url ?? $baseUrl;

            MonitoredSite::updateOrCreate(
                ['base_url' => $baseUrl],
                [
                    'name' => $site['name'],
                    'source_type' => $sourceType,
                    'feed_url' => $sourceType === 'rss' ? $feedUrl : null,
                    'listing_url' => $sourceType === 'html' ? ($listingUrl ?: $baseUrl) : null,
                    'enabled' => true,
                ],
            );
        }
    }
}
