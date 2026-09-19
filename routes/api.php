<?php

use App\Http\Controllers\NotificationDeliveryController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\IncidentController;

Route::post('/operations/monitor', [IncidentController::class, 'monitor'])->middleware('throttle:20,1');

Route::post('/notification-deliveries/quiz-receipt', [
    NotificationDeliveryController::class,
    'dispatchQuizReceipt',
])->middleware('throttle:600,1');
