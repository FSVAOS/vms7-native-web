<?php
use \Modules\SmartCARSNative\Http\Controllers\Api\PilotController;
use \Modules\SmartCARSNative\Http\Controllers\Api\DataController;
use \Modules\SmartCARSNative\Http\Controllers\Api\PirepsController;
use \Modules\SmartCARSNative\Http\Controllers\Api\FlightsController;
use \Modules\SmartCARSNative\Http\Middleware\SCAuth;
use \Modules\SmartCARSNative\Http\Middleware\SCHeaders;

/**
 * This is publicly accessible
 */

Route::group(['middleware' => [SCHeaders::class]], function() {
    Route::match(['get', 'options'], '/', function () {
        return response()->json(["apiVersion" => "0.1.0", "handler" => "phpvms7-native"]);
    });
    Route::match(['post', 'options'], '/pilot/login', [PilotController::class, 'login']);
    Route::match(['post', 'options'], '/pilot/resume', [PilotController::class, 'resume']);
    Route::match(['post', 'options'], '/pilot/verify', [PilotController::class, 'verify']);
    Route::group(['middleware' => [SCAuth::class]], function() {
        Route::match(['get', 'options'], '/pilot/statistics', [PilotController::class, 'statistics']);
        Route::group(['prefix' => '/data', 'controller' => DataController::class], function () {
            Route::match(['get', 'options', 'post'], '/aircraft', 'aircraft');
            Route::match(['get', 'options', 'post'], '/airports', 'airports');
            Route::match(['get', 'options'], '/news', 'news');
        });
        Route::group(['prefix' => '/pireps', 'controller' => PirepsController::class], function () {
            Route::match(['get', 'options', 'post'], '/details', 'details');
            Route::match(['get', 'options', 'post'], '/search', 'search');
        });
        Route::group(['prefix' => '/flights', 'controller' => FlightsController::class], function () {
            Route::match(['post', 'options'], '/book', 'book');
            Route::match(['get', 'options'], '/bookings', 'bookings');
            Route::match(['post', 'options'], '/charter', 'charter');
            Route::match(['post', 'options'], '/complete', 'complete');
            Route::match(['get', 'options'], '/search', 'search');
            Route::match(['post', 'options'], '/unbook', 'unbook');
            Route::match(['post', 'options'], '/update', 'update');
        });
    });


});

/**
 * This is required to have a valid API key
 */
