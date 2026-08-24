<?php

namespace App\Console\Commands;

use App\Models\EmailSubjectKeyword;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('email-keywords:list')]
#[Description('List email subject keywords and Gmail labels')]
class EmailKeywordsListCommand extends Command
{
    public function handle(): int
    {
        $keywords = EmailSubjectKeyword::query()->orderBy('id')->get();

        $this->table(['ID', 'Phrase', 'Gmail label', 'Enabled'], $keywords->map(
            static fn (EmailSubjectKeyword $keyword): array => [
                $keyword->id,
                $keyword->phrase,
                $keyword->label_name,
                $keyword->enabled ? 'yes' : 'no',
            ],
        )->all());

        return self::SUCCESS;
    }
}
