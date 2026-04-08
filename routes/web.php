<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SocialAuthController;
use App\Livewire\DocumentUploader;
use App\Livewire\RagChat;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('auth/google', [SocialAuthController::class, 'redirect'])->name('auth.google.redirect');
Route::get('auth/google/callback', [SocialAuthController::class, 'callback'])->name('auth.google.callback');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // RAG Routes
    Route::get('documents', DocumentUploader::class)->name('documents');
    Route::get('rag-chat', RagChat::class)->name('rag.chat');
});

require __DIR__.'/settings.php';
