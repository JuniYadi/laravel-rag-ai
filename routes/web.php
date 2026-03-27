<?php

use App\Livewire\DocumentUploader;
use App\Livewire\RagChat;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // RAG Routes
    Route::get('documents', DocumentUploader::class)->name('documents');
    Route::get('rag-chat', RagChat::class)->name('rag.chat');
});

require __DIR__.'/settings.php';
