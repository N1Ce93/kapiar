<?php

namespace Tests\Unit;

use App\Services\Monitoring\UrlHelper;
use PHPUnit\Framework\TestCase;

class UrlHelperTest extends TestCase
{
    public function test_it_preserves_meaningful_query_parameters(): void
    {
        $this->assertSame(
            'https://example.com/news.php?id=123&page=2',
            UrlHelper::cleanUrl('https://example.com/news.php?page=2&id=123'),
        );
    }

    public function test_it_removes_tracking_query_parameters(): void
    {
        $this->assertSame(
            'https://example.com/news?id=123',
            UrlHelper::cleanUrl('https://example.com/news?utm_source=telegram&id=123&fbclid=abc'),
        );
    }
}
