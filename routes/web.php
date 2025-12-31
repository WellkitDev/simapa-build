<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Pages\ProfileController;
use App\Http\Controllers\Pages\DebtBookController;
use App\Http\Controllers\Pages\FullPaymentBookController;
use App\Http\Controllers\Pages\OrderBookController;
use App\Http\Controllers\Pages\PaymentBookController;
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
    Route::prefix('books')->name('order.book.')->group(function () {
        Route::get('list', [OrderBookController::class, 'index'])->name('index');
        Route::get('create', [OrderBookController::class, 'create'])->name('create');
        Route::post('create', [OrderBookController::class, 'store'])->name('store');
        Route::get('show/{code_order}', [OrderBookController::class, 'show'])->name('show');
    });
    //order  journal
    Route::prefix('journal')->name('order.journal.')->group(function () {
        Route::get('create', [OrderJournalController::class, 'create'])->name('create');
        Route::post('create', [OrderJournalController::class, 'store'])->name('store');
        Route::get('show/{code_order}', [OrderJournalController::class, 'show'])->name('show');
    });

    Route::prefix('payments')->name('payment.')->group(function () {
        Route::get('list', [PaymentBookController::class, 'index'])->name('index');
        Route::get('{code_order}/create', [PaymentBookController::class, 'create'])->name('create');
        Route::post('{code_order}/create', [PaymentBookController::class, 'store'])->name('store');
        Route::post('approve/{id}', [PaymentBookController::class, 'approve'])->name('approve');
        Route::get('print/{code_order}', [PaymentBookController::class, 'printInvoice'])->name('printInvoice');

        //dp
        Route::get('dp', [DebtBookController::class, 'index'])->name('dp.index');

        //lunas
        Route::get('full', [FullPaymentBookController::class, 'index'])->name('fp.index');
    });

});

require __DIR__.'/auth.php';
