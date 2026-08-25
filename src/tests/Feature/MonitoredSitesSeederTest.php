<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\MonitoredSite;
use Database\Seeders\MonitoredSitesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoredSitesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_upgrades_the_existing_ria_source_without_creating_a_duplicate(): void
    {
        $site = MonitoredSite::create([
            'name' => 'РИА Мелитополь',
            'base_url' => 'https://ria-m.tv/',
            'source_type' => 'html',
            'listing_url' => 'https://ria-m.tv/',
            'enabled' => true,
        ]);
        $article = Article::create([
            'monitored_site_id' => $site->id,
            'url' => 'https://ria-m.tv/news/1/article.html',
            'title' => 'Existing article',
        ]);

        $this->seed(MonitoredSitesSeeder::class);

        $site->refresh();
        $this->assertSame('https://ria-m.tv/ua/', $site->base_url);
        $this->assertSame('https://ria-m.tv/ua/news/', $site->listing_url);
        $this->assertSame('~^/ua/news/[0-9]+/[^/]+\.html$~u', $site->article_url_pattern);
        $this->assertSame($site->id, $article->fresh()->monitored_site_id);
        $this->assertSame(1, MonitoredSite::where('base_url', 'like', '%ria-m.tv%')->count());
    }
}
