<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('messages/send', 'pages::messages.send')->name('messages.send');
    Route::livewire('contacts', 'pages::contacts.index')->name('contacts.index');
});

require __DIR__.'/settings.php';
