<?php
use App\Http\Controllers\BotManController;
use BotMan\BotMan\Middleware\ApiAi;
use BotMan\BotMan\BotMan;

$botman = resolve('botman');

$dialogflow = ApiAi::create(env('DIALOGFLOW_TOKEN'))->listenForAction();
$botman->middleware->received($dialogflow);
$botman->hears('.*', function (BotMan $bot) {

    $extras = $bot->getMessage()->getExtras();
    $apiReply = $extras['apiReply'];
    $apiAction = $extras['apiAction'];
    $apiIntent = $extras['apiIntent'];

    $bot->reply($apiReply);
//    $bot->reply("this is my reply: " . json_encode($extras));

})->middleware($dialogflow);

// Apply global "received" middleware
//$botman->middleware->received($dialogflow);
//
//$botman->hears('Hi', function ($bot) {
//    $bot->reply('Hello!');
//});
//$botman->hears('Start conversation', BotManController::class.'@startConversation');