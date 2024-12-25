<?php

use App\Http\Controllers\DocsController;
use Eyika\Atom\Framework\Http\Route;

Route::get('/{resource?}/{version?}/{page1?}/{page2?}', [DocsController::class, 'generatePage']);
// Route::get('/', [DocsController::class, 'generatePage']);