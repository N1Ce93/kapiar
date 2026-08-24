<?php

namespace App\Console\Commands;

use App\Models\EmailSubjectKeyword;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('email-keywords:enable
    {keyword : Keyword ID or phrase}')]
#[Description('Enable an email subject keyword')]
class EmailKeywordsEnableCommand extends Command
{
    public function handle(): int
    {
        $keyword = $this->keyword((string) $this->argument('keyword'));

        if (! $keyword) {
            $this->error('Email keyword not found.');

            return self::FAILURE;
        }

        $keyword->forceFill(['enabled' => true])->save();
        $this->info('Email keyword enabled: '.$keyword->phrase);

        return self::SUCCESS;
    }

    private function keyword(string $identifier): ?EmailSubjectKeyword
    {
        $identifier = trim($identifier);

        if (ctype_digit($identifier)) {
            return EmailSubjectKeyword::find((int) $identifier);
        }

        return EmailSubjectKeyword::query()->get()->first(
            static fn (EmailSubjectKeyword $item): bool => mb_strtolower($item->phrase, 'UTF-8') === mb_strtolower($identifier, 'UTF-8'),
        );
    }
}
