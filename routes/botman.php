<?php
use App\Http\Controllers\BotManController;
use BotMan\BotMan\Middleware\ApiAi;

$botman = resolve('botman');

$dialogflow = ApiAi::create('b0b113319a1e409e86d931cecc67444c')->listenForAction();

// Apply global "received" middleware
$botman->middleware->received($dialogflow);

$botman->hears('Hi', function ($bot) {
    $bot->reply('Hello!');
});
$botman->hears('Start conversation', BotManController::class.'@startConversation');

//// Apply matching middleware per hears command
//$botman->hears('my_api_action', function (\BotMan\BotMan\BotMan $bot) {
//    // The incoming message matched the "my_api_action" on Dialogflow
//    // Retrieve Dialogflow information:
//    $extras = $bot->getMessage()->getExtras();
//    $apiReply = $extras['apiReply'];
//    $apiAction = $extras['apiAction'];
//    $apiIntent = $extras['apiIntent'];
//
//    $bot->reply("this is my reply");
//})->middleware($dialogflow);