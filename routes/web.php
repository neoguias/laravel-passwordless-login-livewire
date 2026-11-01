<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/panel');

Route::livewire('/login', 'pages::login')->middleware(['guest', 'throttle:30,1'])->name('login');
Route::livewire('/panel', 'pages::panel')->middleware('auth')->name('dashboard');
