<?php

namespace App\Services\Monitoring;

class GmailReviewExtractor
{
    public function __construct(private readonly GmailApiClient $gmail) {}

    /** @param array<string,mixed> $message */
    public function extract(string $messageId, array $message): string
    {
        $parts = ['plain' => [], 'html' => []];
        $this->collectTextParts($messageId, $message['payload'] ?? [], $parts);

        $text = $parts['plain'] !== []
            ? implode("\n\n", $parts['plain'])
            : implode("\n\n", array_map($this->htmlToText(...), $parts['html']));
        $text = $this->normalize($text);

        if ($text === '') {
            return '';
        }

        if (! preg_match('~(?:^|\n)[ \t]*Текст[ \t]+сообщения:[ \t]*(?:\n|$)~iu', $text, $start, PREG_OFFSET_CAPTURE)) {
            return $text;
        }

        $startOffset = $start[0][1] + strlen($start[0][0]);
        $afterMarker = substr($text, $startOffset);

        if (! preg_match('~(?:^|\n)[ \t]*--[ \t]*(?:\n|$)~u', $afterMarker, $end, PREG_OFFSET_CAPTURE)) {
            return $text;
        }

        return $this->normalize(substr($afterMarker, 0, $end[0][1]));
    }

    /**
     * @param  array<string,mixed>  $part
     * @param  array{plain:list<string>,html:list<string>}  $collected
     */
    private function collectTextParts(string $messageId, array $part, array &$collected): void
    {
        if ($this->isAttachment($part)) {
            return;
        }

        foreach ($part['parts'] ?? [] as $child) {
            if (is_array($child)) {
                $this->collectTextParts($messageId, $child, $collected);
            }
        }

        $mimeType = strtolower((string) ($part['mimeType'] ?? ''));

        if (! in_array($mimeType, ['text/plain', 'text/html'], true)) {
            return;
        }

        $encoded = (string) ($part['body']['data'] ?? '');

        if ($encoded === '' && ! empty($part['body']['attachmentId'])) {
            $encoded = $this->gmail->attachmentData($messageId, (string) $part['body']['attachmentId']);
        }

        $text = $this->decodeBody($encoded, $this->charset($part));

        if (trim($text) !== '') {
            $collected[$mimeType === 'text/plain' ? 'plain' : 'html'][] = $text;
        }
    }

    /** @param array<string,mixed> $part */
    private function isAttachment(array $part): bool
    {
        if (trim((string) ($part['filename'] ?? '')) !== '') {
            return true;
        }

        foreach ($part['headers'] ?? [] as $header) {
            if (strcasecmp((string) ($header['name'] ?? ''), 'Content-Disposition') === 0) {
                return str_starts_with(strtolower(trim((string) ($header['value'] ?? ''))), 'attachment');
            }
        }

        return false;
    }

    /** @param array<string,mixed> $part */
    private function charset(array $part): ?string
    {
        foreach ($part['headers'] ?? [] as $header) {
            if (strcasecmp((string) ($header['name'] ?? ''), 'Content-Type') !== 0) {
                continue;
            }

            if (preg_match('~charset\s*=\s*["\']?([^;"\'\s]+)~i', (string) ($header['value'] ?? ''), $match)) {
                return $match[1];
            }
        }

        return null;
    }

    private function decodeBody(string $encoded, ?string $charset): string
    {
        if ($encoded === '') {
            return '';
        }

        $encoded = strtr($encoded, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            return '';
        }

        if ($charset && strcasecmp($charset, 'UTF-8') !== 0) {
            try {
                return mb_convert_encoding($decoded, 'UTF-8', $charset);
            } catch (\ValueError) {
                // Ignore an invalid charset declaration and inspect the decoded bytes below.
            }
        }

        if (! mb_check_encoding($decoded, 'UTF-8')) {
            $detected = mb_detect_encoding($decoded, ['Windows-1251', 'ISO-8859-1'], true);

            if ($detected) {
                return mb_convert_encoding($decoded, 'UTF-8', $detected);
            }
        }

        return $decoded;
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html) ?? $html;
        $html = preg_replace('~<br\s*/?>|</(?:p|div|li|tr|h[1-6])>~i', "\n", $html) ?? $html;

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}", "\0"], ["\n", "\n", ' ', ''], $text);
        $lines = array_map(
            static fn (string $line): string => trim(preg_replace('~[ \t]+~u', ' ', $line) ?? $line),
            explode("\n", $text),
        );
        $text = implode("\n", $lines);
        $text = preg_replace("~\n{3,}~", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
