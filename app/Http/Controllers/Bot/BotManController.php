<?php

namespace App\Http\Controllers\Bot;

use App\Conversations\StartConversation;
use App\Http\Controllers\Controller;
use BotMan\BotMan\BotMan;
use Illuminate\Http\Request;

class BotManController extends Controller
{
    /**
     * Place your BotMan logic here.
     */
    public function handle()
    {
        $botman = app('botman');

        $botman->listen();
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function tinker()
    {
        return view('tinker');
    }

    /**
     * Loaded through routes/botman.php
     * @param  BotMan $bot
     */
    public function conversation (BotMan $bot, $text) {
        $extras = $bot->getMessage()->getExtras();
        $apiReply = $extras['apiReply'];
        //$apiAction = $extras['apiAction'];
        //$apiIntent = $extras['apiIntent'];

        $bot->reply($apiReply);
    }

    /**
     * @param BotMan $bot
     */
    public function start(BotMan $bot)
    {
        $bot->startConversation(new StartConversation());
    }
}
