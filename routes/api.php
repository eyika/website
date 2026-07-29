<?php

use App\Http\Controllers\DeployController;
use Eyika\Atom\Framework\Http\JsonResponse;
use Eyika\Atom\Framework\Http\Route;

Route::get('', function () {
    return JsonResponse::ok('hello world api');
});

// GitHub push webhook. Registered here (not just web.php) because GitHub sends
// Content-Type: application/json, which makes the api route map claim the request.
Route::post('/deploy/github', [DeployController::class, 'github']);
