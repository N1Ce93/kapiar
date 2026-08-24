<?php

namespace Tests\Unit;

use App\Models\EmailSubjectKeyword;
use App\Services\Monitoring\EmailSubjectMatcher;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class EmailSubjectMatcherTest extends TestCase
{
    public function test_it_matches_any_phrase_in_the_subject_without_case_sensitivity(): void
    {
        $keywords = new Collection([
            new EmailSubjectKeyword(['phrase' => 'Оставить свой отзыв', 'label_name' => 'Відгуки']),
            new EmailSubjectKeyword(['phrase' => 'Новая жалоба', 'label_name' => 'Скарги']),
            new EmailSubjectKeyword(['phrase' => 'Не совпадает', 'label_name' => 'Другое']),
        ]);

        $matches = (new EmailSubjectMatcher)->match($keywords, 'ЗОКБ: ОСТАВИТЬ СВОЙ ОТЗЫВ / новая ЖАЛОБА');

        $this->assertSame(['Оставить свой отзыв', 'Новая жалоба'], array_map(
            static fn (EmailSubjectKeyword $keyword): string => $keyword->phrase,
            $matches,
        ));
    }
}
