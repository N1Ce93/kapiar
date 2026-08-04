<?php

namespace Tests\Unit;

use App\Models\Keyword;
use App\Services\Monitoring\KeywordMatcher;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class KeywordMatcherTest extends TestCase
{
    public function test_it_matches_keyword_as_separate_word(): void
    {
        $matcher = new KeywordMatcher();

        $matches = $matcher->match(new Collection([
            new Keyword(['phrase' => 'ЗОКБ']),
        ]), 'Новина про ЗОКБ у Запоріжжі.');

        $this->assertCount(1, $matches);
        $this->assertSame('ЗОКБ', $matches[0]['matched_text']);
    }

    public function test_it_does_not_match_keyword_inside_another_word(): void
    {
        $matcher = new KeywordMatcher();

        $matches = $matcher->match(new Collection([
            new Keyword(['phrase' => 'ЗОКБ']),
        ]), 'Текст із префіксЗОКБсуфікс без окремого слова.');

        $this->assertCount(0, $matches);
    }
}
