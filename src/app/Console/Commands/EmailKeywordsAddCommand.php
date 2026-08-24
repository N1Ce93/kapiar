<?php

namespace App\Console\Commands;

use App\Models\EmailSubjectKeyword;
use App\Models\GmailProcessingMessage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('email-keywords:add
    {phrase : Phrase to find in the email subject}')]
#[Description('Add or update an email subject keyword and its Gmail label')]
class EmailKeywordsAddCommand extends Command
{
    public function handle(): int
    {
        $phrase = trim((string) $this->argument('phrase'));

        if ($phrase === '' || mb_strlen($phrase, 'UTF-8') > 255) {
            $this->error('Keyword must contain between 1 and 255 characters.');

            return self::FAILURE;
        }

        $label = trim((string) $this->ask('Название Gmail-ярлыка'));

        if ($label === '' || mb_strlen($label, 'UTF-8') > 255) {
            $this->error('Label name must contain between 1 and 255 characters.');

            return self::FAILURE;
        }

        if (EmailSubjectKeyword::isReservedLabel($label)) {
            $this->error('Use a custom Gmail label, not a system label such as INBOX, SPAM, or SENT.');

            return self::FAILURE;
        }

        $keyword = EmailSubjectKeyword::query()->get()->first(
            static fn (EmailSubjectKeyword $item): bool => mb_strtolower($item->phrase, 'UTF-8') === mb_strtolower($phrase, 'UTF-8'),
        );

        if ($keyword) {
            $keyword->forceFill(['label_name' => $label, 'enabled' => true])->save();
            $this->refreshPendingLabels($keyword->phrase);
            $this->info('Email keyword updated and enabled: '.$keyword->phrase.' -> '.$label);
        } else {
            EmailSubjectKeyword::create(['phrase' => $phrase, 'label_name' => $label, 'enabled' => true]);
            $this->info('Email keyword added: '.$phrase.' -> '.$label);
        }

        return self::SUCCESS;
    }

    private function refreshPendingLabels(string $updatedPhrase): void
    {
        $keywords = EmailSubjectKeyword::query()->get()->keyBy('phrase');

        foreach (GmailProcessingMessage::query()->get() as $processing) {
            if (! in_array($updatedPhrase, $processing->matched_keywords, true)) {
                continue;
            }

            $labels = [];

            foreach ($processing->matched_keywords as $phrase) {
                if ($keywords->has($phrase)) {
                    $labels[] = $keywords->get($phrase)->label_name;
                }
            }

            $processing->forceFill([
                'target_labels' => array_values(array_unique($labels)),
                'last_error' => null,
            ])->save();
        }
    }
}
