<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Pages\ProfileController;
use App\Http\Controllers\Pages\DebtBookController;
use App\Http\Controllers\Pages\FullPaymentBookController;
use App\Http\Controllers\Pages\IncomeController;
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
Route::get('/template', function () {
    return view('layouts.template.template');
});

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard')->middleware(['auth', 'verified']);
// Route::get('/dashboard', function () {
// })->middleware(['auth', 'verified'])->name('dashboard');

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
    Route::prefix('order')->name('order.')->group(function () {

        Route::get('buku/create', [OrderBookController::class, 'create'])->name('book.create');
        Route::post('buku/create', [OrderBookController::class, 'store'])->name('book.store');
        Route::get('buku/show/{code_order}', [OrderBookController::class, 'show'])->name('book.show');
        Route::get('buku/update/{code_order}', [OrderBookController::class, 'edit'])->name('book.edit');
        Route::put('buku/update/{code_order}', [OrderBookController::class, 'update'])->name('book.update');

        Route::get('jurnal/create', [OrderJournalController::class, 'create'])->name('journal.create');
        Route::post('jurnal/create', [OrderJournalController::class, 'store'])->name('journal.store');
        Route::get('jurnal/show/{code_order}', [OrderJournalController::class, 'show'])->name('journal.show');
    });
    //order  journal
    Route::prefix('management')->name('order.')->group(function () {
        Route::get('order', [OrderBookController::class, 'index'])->name('book.index');
        Route::get('title', [OrderBookController::class, 'indexJudul'])->name('book.indexJudul');
        Route::get('title/details/{id}', [OrderBookController::class, 'detailJudul'])->name('indexJudul.detail');
    });

    Route::prefix('payments')->name('payment.')->group(function () {
        Route::get('list', [PaymentBookController::class, 'index'])->name('index');
        Route::get('{code_order}/create', [PaymentBookController::class, 'create'])->name('create');
        Route::post('{code_order}/create', [PaymentBookController::class, 'store'])->name('store');
        Route::post('approve/{id}', [PaymentBookController::class, 'approve'])->name('approve');
        Route::post('reject/{id}', [PaymentBookController::class, 'reject'])->name('reject');
        Route::get('print/{code_order}', [PaymentBookController::class, 'printInvoice'])->name('printInvoice');

        //dp
        Route::get('dp', [DebtBookController::class, 'index'])->name('dp.index');

        //lunas
        Route::get('full', [FullPaymentBookController::class, 'index'])->name('fp.index');
    });

    Route::prefix('income')->name('income.')->group(function () {
        Route::get('order', [IncomeController::class, 'indexOrderIncome'])->name('order');
        Route::get('payment', [IncomeController::class, 'indexPaymentIncome'])->name('payment');
        Route::get('pending', [IncomeController::class, 'incomeReport'])->name('pending');
        Route::get('full-payment', [IncomeController::class, 'incomeLunas'])->name('lunas');
    });

});

require __DIR__.'/auth.php';
