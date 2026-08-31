<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleKeywordHit;
use App\Models\Keyword;
use App\Models\MonitoredSite;
use App\Models\TelegramChannel;
use App\Models\TelegramMessage;
use App\Models\TelegramMessageKeywordHit;
use App\Services\Reporting\MonthlyReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MonthlyReportDataServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_grouped_monthly_data_with_unique_keyword_phrases(): void
    {
        $site = MonitoredSite::create([
            'name' => 'Main Site',
            'base_url' => 'https://example.com',
            'source_type' => 'html',
            'enabled' => true,
        ]);
        MonitoredSite::create([
            'name' => 'Empty Site',
            'base_url' => 'https://empty.example.com',
            'source_type' => 'html',
            'enabled' => true,
        ]);
        $alpha = Keyword::create(['phrase' => 'alpha']);
        $beta = Keyword::create(['phrase' => 'beta']);

        $newerArticle = Article::create([
            'monitored_site_id' => $site->id,
            'url' => 'https://example.com/newer',
            'title' => "  Newer\narticle  ",
            'published_at' => '2026-08-20 10:00:00',
        ]);
        ArticleKeywordHit::create(['article_id' => $newerArticle->id, 'keyword_id' => $beta->id, 'matched_text' => 'beta']);
        ArticleKeywordHit::create(['article_id' => $newerArticle->id, 'keyword_id' => $alpha->id, 'matched_text' => 'alpha']);

        $fallbackArticle = Article::create([
            'monitored_site_id' => $site->id,
            'url' => 'https://example.com/fallback',
            'discovered_at' => '2026-08-10 09:00:00',
        ]);
        ArticleKeywordHit::create(['article_id' => $fallbackArticle->id, 'keyword_id' => $alpha->id, 'matched_text' => 'alpha']);

        $outsideArticle = Article::create([
            'monitored_site_id' => $site->id,
            'url' => 'https://example.com/september',
            'published_at' => '2026-09-01 00:00:00',
        ]);
        ArticleKeywordHit::create(['article_id' => $outsideArticle->id, 'keyword_id' => $alpha->id, 'matched_text' => 'alpha']);

        $channel = TelegramChannel::create([
            'title' => 'Main Channel',
            'username' => 'main_channel',
            'url' => 'https://t.me/main_channel',
            'telegram_peer' => '@main_channel',
            'enabled' => true,
        ]);
        TelegramChannel::create([
            'title' => 'Empty Channel',
            'username' => 'empty_channel',
            'url' => 'https://t.me/empty_channel',
            'telegram_peer' => '@empty_channel',
            'enabled' => true,
        ]);
        $message = TelegramMessage::create([
            'telegram_channel_id' => $channel->id,
            'message_id' => 101,
            'text' => str_repeat('Длинный заголовок ', 10)."\nПолный текст",
            'url' => 'https://t.me/main_channel/101',
            'posted_at' => '2026-08-15 12:00:00',
        ]);
        TelegramMessageKeywordHit::create(['telegram_message_id' => $message->id, 'keyword_id' => $beta->id, 'matched_text' => 'beta']);
        TelegramMessageKeywordHit::create(['telegram_message_id' => $message->id, 'keyword_id' => $alpha->id, 'matched_text' => 'alpha']);

        $report = app(MonthlyReportDataService::class)->forPeriod(
            Carbon::parse('2026-08-01 00:00:00'),
            Carbon::parse('2026-09-01 00:00:00'),
        );

        $this->assertCount(1, $report['sites']);
        $this->assertSame('Main Site', $report['sites'][0]['name']);
        $this->assertCount(2, $report['sites'][0]['items']);
        $this->assertSame('Newer article', $report['sites'][0]['items'][0]['name']);
        $this->assertSame('alpha, beta', $report['sites'][0]['items'][0]['keywords']);
        $this->assertSame('https://example.com/fallback', $report['sites'][0]['items'][1]['name']);

        $this->assertCount(1, $report['telegram']);
        $this->assertSame('Main Channel', $report['telegram'][0]['name']);
        $this->assertCount(1, $report['telegram'][0]['items']);
        $this->assertSame(110, mb_strlen($report['telegram'][0]['items'][0]['name'], 'UTF-8'));
        $this->assertStringEndsWith('...', $report['telegram'][0]['items'][0]['name']);
        $this->assertSame('alpha, beta', $report['telegram'][0]['items'][0]['keywords']);
    }
}
