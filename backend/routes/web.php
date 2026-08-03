<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

// Public-disk fallback: serves /storage/* straight from disk when the
// `php artisan storage:link` symlink is missing (common on Windows, where
// creating symlinks needs admin rights). With the symlink present the web
// server answers first and this route never runs.
Route::get('storage/{path}', function (string $path) {
    $file = storage_path('app/public/'.$path);

    abort_if(str_contains($path, '..') || ! is_file($file), 404);

    return response()->file($file, ['Cache-Control' => 'public, max-age=86400']);
})->where('path', '.*');
