<?php

namespace App\Services;

use App\Commands\NewOpportunityCommand;
use App\Commands\OptionsCommand;
use App\Console\Commands\BotPopulateChannel;
use App\Models\Opportunity;
use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\BotsManager;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\CallbackQuery;
use Telegram\Bot\Objects\Document;
use Telegram\Bot\Objects\Message;
use Telegram\Bot\Objects\PhotoSize;
use Telegram\Bot\Objects\Update;

/**
 * Class CommandsHandler
 */
class CommandsHandler
{

    /** @var string */
    private $botName;

    /** @var Api */
    private $telegram;

    /** @var Update */
    private $update;

    /**
     * CommandsHandler constructor.
     * @param BotsManager $botsManager
     * @param string $botName
     * @param Update $update
     * @throws TelegramSDKException
     */
    public function __construct(BotsManager $botsManager, string $botName, Update $update)
    {
        $this->botName = $botName;
        $this->update = $update;
        $this->telegram = $botsManager->bot($botName);
        $this->processUpdate($update);
    }

    /**
     * @param BotsManager $botsManager
     * @param string $botName
     * @param Update $update
     * @return CommandsHandler
     * @throws TelegramSDKException
     */
    public static function make(BotsManager $botsManager, string $botName, Update $update)
    {
        return new static($botsManager, $botName, $update);
    }

    /**
     * Process the update coming from bot interface
     *
     * @param Update $update
     * @return mixed
     * @throws TelegramSDKException
     */
    private function processUpdate(Update $update): void
    {
        /** @var Message $message */
        $message = $update->getMessage();
        /** @var CallbackQuery $callbackQuery */
        $callbackQuery = $update->get('callback_query');

        if (strpos($message->text, '/') === 0) {
            $command = explode(' ', $message->text);
            $this->processCommand($command[0]);
        }
        if (filled($callbackQuery)) {
            $this->processCallbackQuery($callbackQuery);
        } elseif (filled($message)) {
            $this->processMessage($message);
        }
    }

    /**
     * Process the Callback query coming from bot interface
     *
     * @param CallbackQuery $callbackQuery
     * @throws TelegramSDKException
     */
    private function processCallbackQuery(CallbackQuery $callbackQuery): void
    {
        $data = $callbackQuery->get('data');
        $data = explode(' ', $data);
        if (is_numeric($data[1])) {
            $opportunity = Opportunity::find($data[1]);
        }
        switch ($data[0]) {
            case Opportunity::CALLBACK_APPROVE:
                if ($opportunity) {
                    $opportunity->status = Opportunity::STATUS_ACTIVE;
                    $opportunity->save();
                    Artisan::call(
                        'bot:populate:channel',
                        [
                            'process' => 'send',
                            'opportunity' => $opportunity->id
                        ]
                    );
                }
                break;
            case Opportunity::CALLBACK_REMOVE:
                if ($opportunity) {
                    $opportunity->delete();
                }
                break;
            case OptionsCommand::OPTIONS_COMMAND:
                if (in_array($data[1], [BotPopulateChannel::COMMAND_PROCESS, BotPopulateChannel::COMMAND_NOTIFY], true)) {
                    Artisan::call('bot:populate:channel', ['process' => $data[1]]);
                    $this->sendMessage('Done!');
                }
                break;
            default:
                Log::info('SWITCH_DEFAULT', $data);
                $this->processCommand($data[0]);
                break;
        }
        try {
            $this->telegram->deleteMessage([
                'chat_id' => config('telegram.admin'),
                'message_id' => $callbackQuery->message->messageId
            ]);
        } catch (TelegramResponseException $exception) {
            Log::info('DELETE_MESSAGE', [$callbackQuery->message]);
            /**
             * A message can only be deleted if it was sent less than 48 hours ago.
             * Bots can delete outgoing messages in groups and supergroups.
             * Bots granted can_post_messages permissions can delete outgoing messages in channels.
             * If the bot is an administrator of a group, it can delete any message there.
             * If the bot has can_delete_messages permission in a supergroup or a channel, it can delete any message there. Returns True on success.
             */
            if ($exception->getCode() != 400) {
                throw $exception;
            }
        }
    }

    /**
     * Process the messages coming from bot interface
     *
     * @param Message $message
     * @throws Exception
     */
    private function processMessage(Message $message): void
    {
        /** @var Message $reply */
        $reply = $message->getReplyToMessage();
        /** @var PhotoSize $photos */
        /** @var PhotoSize $photo */
        $photos = $message->photo;
        /** @var Document $document */
        $document = $message->document;
        /** @var string $caption */
        $caption = $message->caption;

        if (filled($reply) && $reply->from->isBot && $reply->text === NewOpportunityCommand::TEXT) {
            $opportunity = new Opportunity();
            if (blank($message->text) && blank($caption)) {
                throw new Exception('Envie um texto para a vaga, ou o nome da vaga na legenda da imagem/documento.');
            }
            $text = $message->text ?? $caption;
            $title = str_replace("\n", ' ', $text);
            $opportunity->title = substr($title, 0, 50);
            $opportunity->description = $text;
            $opportunity->status = Opportunity::STATUS_INACTIVE;
            $opportunity->files = collect();
            $opportunity->telegram_user_id = $message->from->id;
            $opportunity->url = $this->botName;
            $opportunity->origin = 'telegram';

            if (filled($photos)) {
                foreach ($photos as $photo) {
                    $opportunity->addFile($photo);
                }
            }
            if (filled($document)) {
                $opportunity->addFile($document->first());
            }

            $opportunity->save();

            $this->sendOpportunityToApproval($opportunity, $message);
        }
        $newMembers = $message->newChatMembers;
        Log::info('NEW_MEMBER', [$newMembers]);

        // TODO: Think more about this
        /*if (filled($newMembers)) {
            foreach ($newMembers as $newMember) {
                $name = $newMember->getFirstName();
                return $this->telegram->getCommandBus()->execute('start', $this->update, $name);
            }
        }*/
    }

    /**
     * Send opportunity to approval
     *
     * @param Opportunity $opportunity
     * @param Message $message
     */
    private function sendOpportunityToApproval(Opportunity $opportunity, Message $message): void
    {
        Artisan::call(
            'bot:populate:channel',
            [
                'process' => 'approval',
                'opportunity' => $opportunity->id,
                'message' => $message->messageId,
                'chat' => $message->chat->id,
            ]
        );
    }

    /**
     * Process the command
     *
     * @param string $command
     */
    private function processCommand(string $command): void
    {
        $command = str_replace('/', '', $command);

        $commands = $this->telegram->getCommands();

        if (array_key_exists($command, $commands)) {
            $this->telegram->processCommand($this->update);
        }
    }

    /**
     * Process the command
     *
     * @param string $command
     */
    private function triggerCommand(string $command): void
    {
        $command = str_replace('/', '', $command);

        $commands = $this->telegram->getCommands();

        if (array_key_exists($command, $commands)) {
            $this->telegram->triggerCommand($command, $this->update);
        }
    }

    /**
     * @param $message
     * @throws TelegramSDKException
     */
    private function sendMessage($message)
    {
        $this->telegram->sendMessage([
            'chat_id' => $this->update->getChat()->id,
            'reply_to_message_id' => $this->update->getMessage()->messageId,
            'text' => $message
        ]);
    }
}
