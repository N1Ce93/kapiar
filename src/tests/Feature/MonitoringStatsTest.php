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
