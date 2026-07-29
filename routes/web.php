<?php

use App\Http\Controllers\DeployController;
use App\Http\Controllers\DocsController;
use Eyika\Atom\Framework\Http\Route;

// GitHub push webhook → queues a redeploy (see DeployController + deploy.sh). Declared before
// the catch-all; it's POST so it never collides with the GET docs routes.
Route::post('/deploy/github', [DeployController::class, 'github']);

Route::get('/{resource?}/{version?}/{page1?}/{page2?}', [DocsController::class, 'generatePage']);
// Route::get('/', [DocsController::class, 'generatePage']);