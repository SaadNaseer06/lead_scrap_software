<?php

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Http\Controllers\Auth\LoginController;

Broadcast::routes(['middleware' => ['web', 'auth']]);

// Authentication Routes
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// Protected Routes
Route::middleware('auth')->group(function () {
    Route::post('/webpush/subscribe', function (Request $request) {
        $validated = $request->validate([
            'endpoint' => 'required|string',
            'keys' => 'required|array',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
            'expirationTime' => 'nullable',
            'contentEncoding' => 'nullable|string',
        ]);

        $request->user()->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['contentEncoding'] ?? null,
        );

        return response()->json(['ok' => true]);
    })->name('webpush.subscribe');
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
    
    Route::middleware('role:scrapper')->group(function () {
        Route::get('/leads/create', function () {
            return view('leads.create');
        })->name('leads.create');
    });
    
    Route::match(['get', 'post'], '/leads/{id}', function ($id) {
        // If lead was removed from DB (e.g. creator cleared name), never show 404 – redirect with message
        if (!Lead::where('id', $id)->exists()) {
            return redirect()->route('dashboard')->with('message', 'Lead removed.');
        }
        return view('leads.show', ['id' => $id]);
    })->name('leads.show');
    
    // Sheets Routes
    Route::get('/sheets', function () {
        return view('sheets.index');
    })->name('sheets.index');

    Route::post('/notifications/mark-all-read', function () {
        $update = ['read' => true];
        if (Schema::hasColumn('notifications', 'read_at')) {
            $update['read_at'] = now();
        }

        DB::table('notifications')
            ->where('user_id', auth()->id())
            ->update($update);

        return redirect()->back();
    })->name('notifications.mark-all-read');
    
    // Users Routes (Admin Only) - Real-time with Livewire
    Route::middleware('role:admin')->group(function () {
        Route::get('/users', function () {
            return view('users.index');
        })->name('users.index');
        Route::get('/teams', function () {
            return view('teams.index');
        })->name('teams.index');
    });
});
