<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleKeywordHit;
use App\Models\Keyword;
use App\Models\MonitoredSite;
use App\Models\TelegramChannel;
use App\Models\TelegramMessage;
use App\Models\TelegramMessageKeywordHit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MonitoringStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sites_page_counts_unique_articles_with_hits_for_selected_month(): void
    {
        Carbon::setTestNow('2026-08-07 12:00:00');

        $site = MonitoredSite::create([
            'name' => 'Test Site',
            'base_url' => 'https://example.com',
            'source_type' => 'html',
            'enabled' => true,
        ]);
        $firstKeyword = Keyword::create(['phrase' => 'first']);
        $secondKeyword = Keyword::create(['phrase' => 'second']);

        $articleWithTwoHits = Article::create([
            'monitored_site_id' => $site->id,
            'url' => 'https://example.com/one',
            'published_at' => '2026-08-02 10:00:00',
        ]);
        ArticleKeywordHit::create(['article_id' => $articleWithTwoHits->id, 'keyword_id' => $firstKeyword->id, 'matched_text' => 'first']);
        ArticleKeywordHit::create(['article_id' => $articleWithTwoHits->id, 'keyword_id' => $secondKeyword->id, 'matched_text' => 'second']);

        $articleWithFallbackDate = Article::create([
            'monitored_site_id' => $site->id,
            'url' => 'https://example.com/two',
            'discovered_at' => '2026-08-03 10:00:00',
        ]);
        ArticleKeywordHit::create(['article_id' => $articleWithFallbackDate->id, 'keyword_id' => $firstKeyword->id, 'matched_text' => 'first']);

        $articleWithoutHits = Article::create([
            'monitored_site_id' => $site->id,
            'url' => 'https://example.com/three',
            'published_at' => '2026-08-04 10:00:00',
        ]);
        $this->assertNotNull($articleWithoutHits);

        $oldArticle = Article::create([
            'monitored_site_id' => $site->id,
            'url' => 'https://example.com/old',
            'published_at' => '2026-07-04 10:00:00',
        ]);
        ArticleKeywordHit::create(['article_id' => $oldArticle->id, 'keyword_id' => $firstKeyword->id, 'matched_text' => 'first']);

        $response = $this->get('/monitoring/sites?month=2026-08');

        $response
            ->assertOk()
            ->assertViewHas('totalMentions', 2)
            ->assertViewHas('rows', fn ($rows): bool => $rows->firstWhere('name', 'Test Site')['mentions_count'] === 2);
    }

    public function test_telegram_page_counts_unique_messages_with_hits_for_selected_month(): void
    {
        Carbon::setTestNow('2026-08-07 12:00:00');

        $channel = TelegramChannel::create([
            'title' => 'Test Channel',
            'username' => 'test_channel',
            'url' => 'https://t.me/test_channel',
            'telegram_peer' => '@test_channel',
            'enabled' => true,
        ]);
        $firstKeyword = Keyword::create(['phrase' => 'first']);
        $secondKeyword = Keyword::create(['phrase' => 'second']);

        $messageWithTwoHits = TelegramMessage::create([
            'telegram_channel_id' => $channel->id,
            'message_id' => 1,
            'text' => 'first second',
            'posted_at' => '2026-08-02 10:00:00',
        ]);
        TelegramMessageKeywordHit::create(['telegram_message_id' => $messageWithTwoHits->id, 'keyword_id' => $firstKeyword->id, 'matched_text' => 'first']);
        TelegramMessageKeywordHit::create(['telegram_message_id' => $messageWithTwoHits->id, 'keyword_id' => $secondKeyword->id, 'matched_text' => 'second']);

        $messageWithFallbackDate = TelegramMessage::create([
            'telegram_channel_id' => $channel->id,
            'message_id' => 2,
            'text' => 'first',
            'created_at' => '2026-08-03 10:00:00',
            'updated_at' => '2026-08-03 10:00:00',
        ]);
        TelegramMessageKeywordHit::create(['telegram_message_id' => $messageWithFallbackDate->id, 'keyword_id' => $firstKeyword->id, 'matched_text' => 'first']);

        $messageWithoutHits = TelegramMessage::create([
            'telegram_channel_id' => $channel->id,
            'message_id' => 3,
            'text' => 'nothing',
            'posted_at' => '2026-08-04 10:00:00',
        ]);
        $this->assertNotNull($messageWithoutHits);

        $oldMessage = TelegramMessage::create([
            'telegram_channel_id' => $channel->id,
            'message_id' => 4,
            'text' => 'first',
            'posted_at' => '2026-07-04 10:00:00',
        ]);
        TelegramMessageKeywordHit::create(['telegram_message_id' => $oldMessage->id, 'keyword_id' => $firstKeyword->id, 'matched_text' => 'first']);

        $response = $this->get('/monitoring/telegram?month=2026-08');

        $response
            ->assertOk()
            ->assertViewHas('totalMentions', 2)
            ->assertViewHas('rows', fn ($rows): bool => $rows->firstWhere('name', 'Test Channel')['mentions_count'] === 2);
    }
}
