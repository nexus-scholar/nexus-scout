<?php

use App\Http\Controllers\ThreadController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::get('projects', [ThreadController::class, 'index'])->name('projects.index');
    Route::get('projects/create', [ThreadController::class, 'create'])->name('projects.create');
    Route::post('projects', [ThreadController::class, 'store'])->name('projects.store');
    Route::get('interview/{thread}', [ThreadController::class, 'interview'])->name('projects.interview');
    Route::post('interview/{thread}/answers', [ThreadController::class, 'answers'])->name('projects.answers');
    Route::get('engine/{thread}', [ThreadController::class, 'engine'])->name('projects.engine');
    Route::get('validation/{thread}', [ThreadController::class, 'validation'])->name('projects.validation');
    Route::post('execute/{thread}', [ThreadController::class, 'execute'])->name('projects.execute');
});

require __DIR__.'/settings.php';
