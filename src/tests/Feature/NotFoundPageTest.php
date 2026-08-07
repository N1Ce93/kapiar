<?php

namespace Tests\Feature;

use Tests\TestCase;

class NotFoundPageTest extends TestCase
{
    public function test_unknown_urls_return_default_not_found_page(): void
    {
        $response = $this->get('/unknown-page');

        $response->assertNotFound();
    }
}
