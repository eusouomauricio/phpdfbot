<?php

namespace App\Commands;

use App\Models\Config;
use Telegram\Bot\Actions;
use Telegram\Bot\Commands\Command;

class RulesCommand extends Command
{
    /**
     * @var string Command Name
     */
    protected $name = 'rules';

    /**
     * @var string Command Description
     */
    protected $description = 'The group rules';

    /**
     * @inheritdoc
     */
    public function handle()
    {
        $this->replyWithChatAction(['action' => Actions::TYPING]);

        $rules = Config::where('key', 'rules')->first();

        $this->replyWithMessage([
            'parse_mode' => 'Markdown',
            'text' => $rules
        ]);
    }
}
