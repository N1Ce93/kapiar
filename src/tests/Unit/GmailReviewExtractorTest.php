<?php

namespace Tests\Unit;

use App\Services\Monitoring\GmailApiClient;
use App\Services\Monitoring\GmailReviewExtractor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GmailReviewExtractorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gmail.client_id' => 'gmail-client-id',
            'services.gmail.client_secret' => 'gmail-client-secret',
            'services.gmail.refresh_token' => 'gmail-refresh-token',
        ]);
        Cache::flush();
    }

    public function test_it_extracts_and_normalizes_the_marked_plain_text_section(): void
    {
        $body = "Заголовок\r\nТЕКСТ   СООБЩЕНИЯ:\r\n  Первая   строка  \r\n\r\n\r\nВторая строка\r\n -- \r\nПодпись";

        $review = (new GmailReviewExtractor(new GmailApiClient))->extract('message-1', [
            'payload' => $this->textPart('text/plain', $body),
        ]);

        $this->assertSame("Первая строка\n\nВторая строка", $review);
    }

    public function test_it_uses_the_whole_body_when_both_markers_are_not_present(): void
    {
        $body = "Вводная строка\nТекст сообщения:\nОтзыв без завершающего маркера";

        $review = (new GmailReviewExtractor(new GmailApiClient))->extract('message-1', [
            'payload' => $this->textPart('text/plain', $body),
        ]);

        $this->assertSame($body, $review);
    }

    public function test_it_uses_html_when_plain_text_is_absent(): void
    {
        $html = '<html><style>.hidden { display: none; }</style><body><p>Текст сообщения:</p><div>Очень &lt;хорошо&gt;<br>Спасибо</div><p>--</p><p>Подпись</p></body></html>';

        $review = (new GmailReviewExtractor(new GmailApiClient))->extract('message-1', [
            'payload' => $this->textPart('text/html', $html),
        ]);

        $this->assertSame("Очень <хорошо>\nСпасибо", $review);
    }

    public function test_it_loads_inline_text_from_an_attachment_id_but_ignores_file_attachments(): void
    {
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response(['access_token' => 'gmail-access-token', 'expires_in' => 3600]);
            }

            if (str_contains($request->url(), '/attachments/body-part')) {
                return Http::response(['data' => $this->encode("Текст сообщения:\nОтзыв из большой части\n--")]);
            }

            return Http::response(['unexpected' => $request->url()], 500);
        });

        $review = (new GmailReviewExtractor(new GmailApiClient))->extract('message-1', [
            'payload' => [
                'mimeType' => 'multipart/mixed',
                'parts' => [
                    [
                        'mimeType' => 'text/plain',
                        'filename' => '',
                        'body' => ['attachmentId' => 'body-part'],
                    ],
                    [
                        'mimeType' => 'text/plain',
                        'filename' => 'attached.txt',
                        'body' => ['attachmentId' => 'file-part'],
                    ],
                ],
            ],
        ]);

        $this->assertSame('Отзыв из большой части', $review);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/attachments/file-part'));
    }

    /** @return array<string,mixed> */
    private function textPart(string $mimeType, string $body): array
    {
        return [
            'mimeType' => $mimeType,
            'filename' => '',
            'body' => ['data' => $this->encode($body)],
        ];
    }

    private function encode(string $body): string
    {
        return rtrim(strtr(base64_encode($body), '+/', '-_'), '=');
    }
}
