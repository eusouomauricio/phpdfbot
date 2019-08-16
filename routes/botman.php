<?php

use App\Http\Controllers\Bot\BotManController;
use BotMan\BotMan\Middleware\ApiAi;

$botman = resolve('botman');

$dialogflow = ApiAi::create(env('DIALOGFLOW_TOKEN'))->listenForAction();

// Apply global "received" middleware
$botman->middleware->received($dialogflow);

$botman->hears('/start', BotManController::class . '@start');

$botman->hears('DevBot (.*)', BotManController::class . '@conversation');