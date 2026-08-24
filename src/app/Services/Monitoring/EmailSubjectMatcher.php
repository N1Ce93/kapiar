<?php

namespace App\Services\Monitoring;

use App\Models\EmailSubjectKeyword;
use Illuminate\Support\Collection;

class EmailSubjectMatcher
{
    /**
     * @param  Collection<int, EmailSubjectKeyword>  $keywords
     * @return list<EmailSubjectKeyword>
     */
    public function match(Collection $keywords, string $subject): array
    {
        $matches = [];

        foreach ($keywords as $keyword) {
            $phrase = trim($keyword->phrase);

            if ($phrase !== '' && mb_stripos($subject, $phrase, 0, 'UTF-8') !== false) {
                $matches[] = $keyword;
            }
        }

        return $matches;
    }
}
