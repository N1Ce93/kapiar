<?php

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleKeywordHit;
use App\Models\Keyword;
use App\Models\MonitoredSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitesDeleteCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_a_site_and_related_monitoring_data(): void
    {
        $site = $this->site();
        $article = Article::create([
            'monitored_site_id' => $site->id,
            'url' => 'https://example.com/news/1',
            'title' => 'Example article',
        ]);
        $keyword = Keyword::create(['phrase' => 'example', 'enabled' => true]);
        $hit = ArticleKeywordHit::create([
            'article_id' => $article->id,
            'keyword_id' => $keyword->id,
            'matched_text' => 'example',
        ]);

        $this->artisan('sites:delete', ['id' => (string) $site->id, '--force' => true])
            ->expectsOutput("Site: Example News (ID: {$site->id})")
            ->expectsOutput('Articles to delete: 1')
            ->expectsOutput("Site deleted: Example News (ID: {$site->id}); articles deleted: 1")
            ->assertSuccessful();

        $this->assertDatabaseMissing('monitored_sites', ['id' => $site->id]);
        $this->assertDatabaseMissing('articles', ['id' => $article->id]);
        $this->assertDatabaseMissing('article_keyword_hits', ['id' => $hit->id]);
        $this->assertDatabaseHas('keywords', ['id' => $keyword->id]);
    }

    public function test_it_keeps_the_site_when_deletion_is_not_confirmed(): void
    {
        $site = $this->site();

        $this->artisan('sites:delete', ['id' => (string) $site->id])
            ->expectsConfirmation('Delete this site and all related articles?', 'no')
            ->expectsOutput('Deletion cancelled.')
            ->assertSuccessful();

        $this->assertDatabaseHas('monitored_sites', ['id' => $site->id]);
    }

    public function test_it_rejects_invalid_or_unknown_ids(): void
    {
        $this->artisan('sites:delete', ['id' => 'invalid'])
            ->expectsOutput('Site ID must be a positive integer.')
            ->assertFailed();

        $this->artisan('sites:delete', ['id' => '999'])
            ->expectsOutput('Site not found: 999')
            ->assertFailed();
    }

    private function site(): MonitoredSite
    {
        return MonitoredSite::create([
            'name' => 'Example News',
            'base_url' => 'https://example.com/',
            'source_type' => 'html',
            'listing_url' => 'https://example.com/news',
            'enabled' => true,
        ]);
    }
}
