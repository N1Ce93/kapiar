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

        $response = $this
            ->withSession(['site_access_granted' => true])
            ->get('/sites?month=2026-08');

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

        $response = $this
            ->withSession(['site_access_granted' => true])
            ->get('/telegram?month=2026-08');

        $response
            ->assertOk()
            ->assertViewHas('totalMentions', 2)
            ->assertViewHas('rows', fn ($rows): bool => $rows->firstWhere('name', 'Test Channel')['mentions_count'] === 2);
    }

    public function test_sites_page_shows_status_and_loads_posts_for_selected_month(): void
    {
        Carbon::setTestNow('2026-08-07 12:00:00');

        $site = MonitoredSite::create([
            'name' => 'Broken Site',
            'base_url' => 'https://broken.example.com',
            'source_type' => 'html',
            'enabled' => false,
            'consecutive_failures' => 4,
            'last_checked_at' => '2026-08-06 11:00:00',
            'last_error_at' => '2026-08-06 11:01:00',
            'last_error' => 'HTTP 500 from homepage',
            'disabled_at' => '2026-08-06 11:02:00',
            'disabled_reason' => 'auto-disabled after 4 consecutive failures',
        ]);
        $keyword = Keyword::create(['phrase' => 'match']);

        foreach ([5, 4, 3, 2, 1] as $day) {
            $article = Article::create([
                'monitored_site_id' => $site->id,
                'url' => 'https://broken.example.com/post-'.$day,
                'title' => 'August Post '.$day,
                'published_at' => '2026-08-0'.$day.' 10:00:00',
            ]);
            ArticleKeywordHit::create(['article_id' => $article->id, 'keyword_id' => $keyword->id, 'matched_text' => 'match']);
        }

        Article::create([
            'monitored_site_id' => $site->id,
            'url' => 'https://broken.example.com/no-hit-post',
            'title' => 'August Post Without Hit',
            'published_at' => '2026-08-06 10:00:00',
        ]);

        $oldArticle = Article::create([
            'monitored_site_id' => $site->id,
            'url' => 'https://broken.example.com/july-post',
            'title' => 'July Post',
            'published_at' => '2026-07-31 10:00:00',
        ]);
        ArticleKeywordHit::create(['article_id' => $oldArticle->id, 'keyword_id' => $keyword->id, 'matched_text' => 'match']);

        $response = $this
            ->withSession(['site_access_granted' => true])
            ->get('/sites?month=2026-08');

        $response
            ->assertOk()
            ->assertSee('Відключено')
            ->assertSee('HTTP 500 from homepage')
            ->assertSee('August Post 5')
            ->assertSee('August Post 4')
            ->assertSee('August Post 3')
            ->assertDontSee('August Post 2')
            ->assertDontSee('August Post Without Hit')
            ->assertDontSee('July Post');

        $this
            ->withSession(['site_access_granted' => true])
            ->getJson('/sites/'.$site->id.'/posts?month=2026-08&offset=3')
            ->assertOk()
            ->assertJsonPath('total', 5)
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('items.0.title', 'August Post 2')
            ->assertJsonPath('items.1.title', 'August Post 1');
    }

    public function test_telegram_page_loads_message_titles_for_selected_month(): void
    {
        Carbon::setTestNow('2026-08-07 12:00:00');

        $channel = TelegramChannel::create([
            'title' => 'News Channel',
            'username' => 'news_channel',
            'url' => 'https://t.me/news_channel',
            'telegram_peer' => '@news_channel',
            'enabled' => true,
        ]);
        $keyword = Keyword::create(['phrase' => 'match']);

        foreach ([4, 3, 2, 1] as $day) {
            $message = TelegramMessage::create([
                'telegram_channel_id' => $channel->id,
                'message_id' => $day,
                'text' => 'Telegram Post '.$day."\nFull body",
                'url' => 'https://t.me/news_channel/'.$day,
                'posted_at' => '2026-08-0'.$day.' 10:00:00',
            ]);
            TelegramMessageKeywordHit::create(['telegram_message_id' => $message->id, 'keyword_id' => $keyword->id, 'matched_text' => 'match']);
        }

        TelegramMessage::create([
            'telegram_channel_id' => $channel->id,
            'message_id' => 9,
            'text' => 'Telegram Post Without Hit',
            'posted_at' => '2026-08-05 10:00:00',
        ]);

        $oldMessage = TelegramMessage::create([
            'telegram_channel_id' => $channel->id,
            'message_id' => 10,
            'text' => 'July Telegram Post',
            'posted_at' => '2026-07-31 10:00:00',
        ]);
        TelegramMessageKeywordHit::create(['telegram_message_id' => $oldMessage->id, 'keyword_id' => $keyword->id, 'matched_text' => 'match']);

        $response = $this
            ->withSession(['site_access_granted' => true])
            ->get('/telegram?month=2026-08');

        $response
            ->assertOk()
            ->assertSee('Telegram Post 4')
            ->assertSee('Telegram Post 3')
            ->assertSee('Telegram Post 2')
            ->assertDontSee('Telegram Post 1')
            ->assertDontSee('Telegram Post Without Hit')
            ->assertDontSee('July Telegram Post');

        $this
            ->withSession(['site_access_granted' => true])
            ->getJson('/telegram/'.$channel->id.'/posts?month=2026-08&offset=3')
            ->assertOk()
            ->assertJsonPath('total', 4)
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('items.0.title', 'Telegram Post 1');
    }

    public function test_sites_day_range_filters_counts_initial_posts_and_loaded_posts(): void
    {
        Carbon::setTestNow('2026-08-07 12:00:00');

        $site = MonitoredSite::create([
            'name' => 'Daily Site',
            'base_url' => 'https://daily.example.com',
            'source_type' => 'html',
            'enabled' => true,
        ]);
        $keyword = Keyword::create(['phrase' => 'match']);
        $articles = [
            ['Before range', '2026-08-01 23:59:59'],
            ['Range Post 2', '2026-08-02 00:00:00'],
            ['Range Post 3', '2026-08-03 10:00:00'],
            ['Range Post 4', '2026-08-04 10:00:00'],
            ['Range Post 5', '2026-08-05 23:59:59'],
            ['After range', '2026-08-06 00:00:00'],
        ];

        foreach ($articles as [$title, $publishedAt]) {
            $article = Article::create([
                'monitored_site_id' => $site->id,
                'url' => 'https://daily.example.com/'.str($title)->slug(),
                'title' => $title,
                'published_at' => $publishedAt,
            ]);
            ArticleKeywordHit::create(['article_id' => $article->id, 'keyword_id' => $keyword->id, 'matched_text' => 'match']);
        }

        $response = $this
            ->withSession(['site_access_granted' => true])
            ->get('/sites?month=2026-08&from_day=2&to_day=5');

        $response
            ->assertOk()
            ->assertViewHas('totalMentions', 4)
            ->assertViewHas('dayFilterActive', true)
            ->assertViewHas('periodLabel', '02.08.2026 - 05.08.2026')
            ->assertViewHas('rows', function ($rows): bool {
                $row = $rows->firstWhere('name', 'Daily Site');

                return $row['mentions_count'] === 4
                    && $row['posts_count'] === 4
                    && $row['posts_has_more'] === true;
            })
            ->assertSee('Range Post 5')
            ->assertSee('Range Post 4')
            ->assertSee('Range Post 3')
            ->assertDontSee('Range Post 2')
            ->assertDontSee('Before range')
            ->assertDontSee('After range')
            ->assertSee('data-from-day="2"', false)
            ->assertSee('data-to-day="5"', false)
            ->assertSee('telegram?month=2026-08&amp;from_day=2&amp;to_day=5', false)
            ->assertDontSee('month=2026-07&amp;from_day=2', false);

        $this
            ->withSession(['site_access_granted' => true])
            ->getJson('/sites/'.$site->id.'/posts?month=2026-08&from_day=2&to_day=5&offset=3')
            ->assertOk()
            ->assertJsonPath('total', 4)
            ->assertJsonPath('has_more', false)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.title', 'Range Post 2');
    }

    public function test_telegram_day_range_filters_posted_and_fallback_dates_in_loaded_posts(): void
    {
        Carbon::setTestNow('2026-08-07 12:00:00');

        $channel = TelegramChannel::create([
            'title' => 'Daily Channel',
            'username' => 'daily_channel',
            'url' => 'https://t.me/daily_channel',
            'telegram_peer' => '@daily_channel',
            'enabled' => true,
        ]);
        $keyword = Keyword::create(['phrase' => 'match']);
        $messages = [
            ['Before range', '2026-08-01 23:59:59', false],
            ['Range Message 2', '2026-08-02 00:00:00', false],
            ['Range Message 3', '2026-08-03 10:00:00', false],
            ['Fallback Message 4', '2026-08-04 10:00:00', true],
            ['Range Message 5', '2026-08-05 23:59:59', false],
            ['After range', '2026-08-06 00:00:00', false],
        ];

        foreach ($messages as $index => [$text, $date, $useCreatedAt]) {
            $message = TelegramMessage::create([
                'telegram_channel_id' => $channel->id,
                'message_id' => $index + 1,
                'text' => $text,
                'url' => 'https://t.me/daily_channel/'.($index + 1),
                'posted_at' => $useCreatedAt ? null : $date,
            ]);

            if ($useCreatedAt) {
                $message->forceFill(['created_at' => $date, 'updated_at' => $date])->saveQuietly();
            }

            TelegramMessageKeywordHit::create(['telegram_message_id' => $message->id, 'keyword_id' => $keyword->id, 'matched_text' => 'match']);
        }

        $response = $this
            ->withSession(['site_access_granted' => true])
            ->get('/telegram?month=2026-08&from_day=2&to_day=5');

        $response
            ->assertOk()
            ->assertViewHas('totalMentions', 4)
            ->assertViewHas('rows', function ($rows): bool {
                $row = $rows->firstWhere('name', 'Daily Channel');

                return $row['mentions_count'] === 4
                    && $row['posts_count'] === 4
                    && $row['posts_has_more'] === true;
            })
            ->assertSee('Range Message 5')
            ->assertSee('Fallback Message 4')
            ->assertSee('Range Message 3')
            ->assertDontSee('Range Message 2')
            ->assertDontSee('Before range')
            ->assertDontSee('After range');

        $this
            ->withSession(['site_access_granted' => true])
            ->getJson('/telegram/'.$channel->id.'/posts?month=2026-08&from_day=2&to_day=5&offset=3')
            ->assertOk()
            ->assertJsonPath('total', 4)
            ->assertJsonPath('has_more', false)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.title', 'Range Message 2');
    }

    public function test_invalid_day_range_falls_back_to_the_full_selected_month(): void
    {
        Carbon::setTestNow('2026-08-07 12:00:00');

        $site = MonitoredSite::create([
            'name' => 'Fallback Site',
            'base_url' => 'https://fallback.example.com',
            'source_type' => 'html',
            'enabled' => true,
        ]);
        $keyword = Keyword::create(['phrase' => 'match']);

        foreach ([2, 20] as $day) {
            $article = Article::create([
                'monitored_site_id' => $site->id,
                'url' => 'https://fallback.example.com/post-'.$day,
                'published_at' => '2026-08-'.$day.' 10:00:00',
            ]);
            ArticleKeywordHit::create(['article_id' => $article->id, 'keyword_id' => $keyword->id, 'matched_text' => 'match']);
        }

        $this
            ->withSession(['site_access_granted' => true])
            ->get('/sites?month=2026-08&from_day=20&to_day=2')
            ->assertOk()
            ->assertViewHas('totalMentions', 2)
            ->assertViewHas('dayFilterActive', false)
            ->assertViewHas('selectedFromDay', null)
            ->assertViewHas('selectedToDay', null)
            ->assertViewHas('periodQuery', ['month' => '2026-08']);
    }

    public function test_sites_page_shows_paused_status_and_next_check_date(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        MonitoredSite::create([
            'name' => 'Paused Site',
            'base_url' => 'https://paused.example.com',
            'source_type' => 'html',
            'enabled' => true,
            'consecutive_failures' => 3,
            'next_check_at' => '2026-08-18 12:00:00',
            'last_error_at' => '2026-08-17 12:00:00',
            'last_error' => 'Connection failed',
            'last_error_type' => 'temporary',
            'paused_at' => '2026-08-17 12:00:00',
        ]);

        $this->withSession(['site_access_granted' => true])
            ->get('/sites')
            ->assertOk()
            ->assertSee('Призупинено до 18.08.2026 12:00')
            ->assertSee('Наступна перевірка')
            ->assertSee('Тимчасова');
    }
}
