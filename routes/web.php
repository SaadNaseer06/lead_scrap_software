<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;

// Authentication Routes
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// Protected Routes
Route::middleware('auth')->group(function () {
    Route::get('/', function () {
        return redirect()->route('dashboard');
    });
    
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');
    
    // Leads Routes
    Route::get('/leads', function () {
        return view('leads.index');
    })->name('leads.index');
    
    Route::middleware('role:admin,scrapper')->group(function () {
        Route::get('/leads/create', function () {
            return view('leads.create');
        })->name('leads.create');
    });
    
    Route::get('/leads/{id}', function ($id) {
        return view('leads.show', ['id' => $id]);
    })->name('leads.show');
});
