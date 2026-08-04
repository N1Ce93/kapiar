<?php

namespace Tests\Unit;

use App\Services\Monitoring\TelegramChannelUrl;
use PHPUnit\Framework\TestCase;

class TelegramChannelUrlTest extends TestCase
{
    public function test_it_parses_public_channel_url(): void
    {
        $parsed = TelegramChannelUrl::parse('https://t.me/zoda_gov_ua/');

        $this->assertSame('zoda_gov_ua', $parsed['username']);
        $this->assertSame('https://t.me/zoda_gov_ua', $parsed['url']);
        $this->assertSame('@zoda_gov_ua', $parsed['peer']);
    }

    public function test_it_parses_public_channel_username(): void
    {
        $parsed = TelegramChannelUrl::parse('@ZODA_gov_ua');

        $this->assertSame('zoda_gov_ua', $parsed['username']);
    }
}
