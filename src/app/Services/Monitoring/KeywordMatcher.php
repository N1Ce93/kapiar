<?php

namespace App\Services\Monitoring;

use App\Models\Keyword;
use Illuminate\Support\Collection;

class KeywordMatcher
{
    /**
     * @param Collection<int, Keyword> $keywords
     * @return list<array{keyword:Keyword,matched_text:string,context:string}>
     */
    public function match(Collection $keywords, string $text): array
    {
        $matches = [];

        foreach ($keywords as $keyword) {
            $phrase = trim($keyword->phrase);

            if ($phrase === '') {
                continue;
            }

            $pattern = '~(?<![\p{L}\p{N}_])'.preg_quote($phrase, '~').'(?![\p{L}\p{N}_])~iu';

            if (! preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $matchedText = $match[0][0];
            $byteOffset = $match[0][1];
            $charOffset = mb_strlen(substr($text, 0, $byteOffset), 'UTF-8');
            $contextStart = max(0, $charOffset - 120);

            $matches[] = [
                'keyword' => $keyword,
                'matched_text' => $matchedText,
                'context' => trim(mb_substr($text, $contextStart, mb_strlen($matchedText, 'UTF-8') + 240, 'UTF-8')),
            ];
        }

        return $matches;
    }
}
