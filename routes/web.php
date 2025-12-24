<?php

use App\Http\Controllers\Pages\DebtBookController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Pages\ProfileController;
use App\Http\Controllers\Pages\OrderBookController;
use App\Http\Controllers\Pages\OrderJournalController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    //profile
    Route::prefix('profile')->group(function() {
        Route::get('', [ProfileController::class, 'index'])->name('profile');
        Route::patch('', [ProfileController::class, 'update'])->name('profile');
        Route::get('/profile-image/{fileId}', [ProfileController::class, 'profileImage'])
        ->name('profile.image')
        ->where('fileId', '[a-zA-Z0-9_-]+');
    });

    //Order book
    Route::prefix('order-books')->name('order.book.')->group(function () {
        Route::get('/', [OrderBookController::class, 'index'])->name('index');
        Route::get('create', [OrderBookController::class, 'create'])->name('create');
        Route::post('create', [OrderBookController::class, 'store'])->name('store');
        Route::get('show', [OrderBookController::class, 'show'])->name('show');
        Route::get('inv', [OrderBookController::class, 'inv'])->name('inv');

        //payment
        Route::get('debt', [DebtBookController::class, 'index'])->name('debt.index');
    });
    //order journal
    Route::prefix('order-journals')->name('order.journal.')->group(function () {
        Route::get('/', [OrderJournalController::class, 'index'])->name('index');
        Route::get('create', [OrderJournalController::class, 'create'])->name('create');
    });
});

require __DIR__.'/auth.php';
