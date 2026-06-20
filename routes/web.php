<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Pages\ProfileController;
use App\Http\Controllers\Pages\DebtBookController;
use App\Http\Controllers\Pages\FullPaymentBookController;
use App\Http\Controllers\Pages\IncomeController;
use App\Http\Controllers\Pages\ManagementUserController;
use App\Http\Controllers\Pages\OrderBookController;
use App\Http\Controllers\Pages\PaymentBookController;
use App\Http\Controllers\Pages\OrderJournalController;
use App\Http\Controllers\Pages\TitleProgressController;
use App\Http\Controllers\Pages\ManuscriptTrackerController;
use App\Http\Controllers\Pages\InvoiceController;

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
    return redirect()->route('login');
});

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard')->middleware(['auth', 'verified']);

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
        Route::get('title/order/{id}', [OrderBookController::class, 'progressDetail'])->name('indexJudul.progress');
    });

    Route::prefix('management')->group(function () {
        Route::post('title/{id}/update-status', [TitleProgressController::class, 'update'])
            ->name('title.progress.update')
            ->middleware('role:production|manager|superadmin');
        Route::get('title/{id}/logs', [TitleProgressController::class, 'logs'])
            ->name('title.progress.logs');
    });

    Route::prefix('management')->group(function () {
        Route::get('manuscript', [ManuscriptTrackerController::class, 'index'])
            ->name('manuscript.board')
            ->middleware('role:production|manager|superadmin');
        Route::post('manuscript/{id}/move', [ManuscriptTrackerController::class, 'move'])
            ->name('manuscript.move')
            ->middleware('role:production|manager|superadmin');
        Route::post('manuscript/{id}/assign', [ManuscriptTrackerController::class, 'assign'])
            ->name('manuscript.assign')
            ->middleware('role:production|manager|superadmin');
        Route::post('manuscript/{id}/priority', [ManuscriptTrackerController::class, 'priority'])
            ->name('manuscript.priority')
            ->middleware('role:production|manager|superadmin');
        Route::post('manuscript/{id}/reviewed', [ManuscriptTrackerController::class, 'reviewed'])
            ->name('manuscript.reviewed')
            ->middleware('role:manager|superadmin');
        Route::post('manuscript/{id}/target', [ManuscriptTrackerController::class, 'target'])
            ->name('manuscript.target')
            ->middleware('role:marketing|production|manager|superadmin');
        Route::post('manuscript/{id}/clear-log', [ManuscriptTrackerController::class, 'clearLog'])
            ->name('manuscript.clearLog')
            ->middleware('role:superadmin');
    });

    Route::prefix('payments')->name('payment.')->group(function () {
        Route::get('list', [PaymentBookController::class, 'index'])->name('index');
        Route::get('{code_order}/create', [PaymentBookController::class, 'create'])->name('create');
        Route::post('{code_order}/create', [PaymentBookController::class, 'store'])->name('store');
        Route::post('approve/{id}', [PaymentBookController::class, 'approve'])->name('approve');
        Route::post('reject/{id}', [PaymentBookController::class, 'reject'])->name('reject');
        Route::put('{id}', [PaymentBookController::class, 'update'])
            ->name('update')
            ->middleware('role:manager|superadmin');

        //dp
        Route::get('dp', [DebtBookController::class, 'index'])->name('dp.index');

        //lunas
        Route::get('full', [FullPaymentBookController::class, 'index'])->name('fp.index');
    });

    Route::prefix('invoices')->name('invoice.')->group(function () {
        Route::get('',             [InvoiceController::class, 'index'])->name('index');
        Route::get('create',       [InvoiceController::class, 'create'])->name('create');
        Route::post('',            [InvoiceController::class, 'store'])->name('store');
        Route::get('{id}',         [InvoiceController::class, 'show'])->name('show');
        Route::get('{id}/edit',    [InvoiceController::class, 'edit'])->name('edit');
        Route::put('{id}',         [InvoiceController::class, 'update'])->name('update');
        Route::post('{id}/status', [InvoiceController::class, 'updateStatus'])->name('updateStatus');
        Route::post('{id}/cancel', [InvoiceController::class, 'cancel'])->name('cancel');
        Route::post('{id}/refund', [InvoiceController::class, 'refund'])->name('refund');
        Route::get('{id}/logs',    [InvoiceController::class, 'logs'])->name('logs');
        Route::get('{id}/pdf', [InvoiceController::class, 'pdf'])->name('pdf');
    });

    Route::prefix('tagihan')->name('tagihan.')->middleware('role:marketing|manager|superadmin')->group(function () {
        Route::get('',                [\App\Http\Controllers\Pages\TagihanController::class, 'index'])->name('index');
        Route::get('create',          [\App\Http\Controllers\Pages\TagihanController::class, 'create'])->name('create');
        Route::post('',               [\App\Http\Controllers\Pages\TagihanController::class, 'store'])->name('store');
        Route::get('{id}',            [\App\Http\Controllers\Pages\TagihanController::class, 'show'])->name('show');
        Route::get('{id}/edit',       [\App\Http\Controllers\Pages\TagihanController::class, 'edit'])->name('edit');
        Route::put('{id}',            [\App\Http\Controllers\Pages\TagihanController::class, 'update'])->name('update');
        Route::post('{id}/approve',   [\App\Http\Controllers\Pages\TagihanController::class, 'approve'])->name('approve');
        Route::post('{id}/reject',    [\App\Http\Controllers\Pages\TagihanController::class, 'reject'])->name('reject');
        Route::post('{id}/cancel',    [\App\Http\Controllers\Pages\TagihanController::class, 'cancel'])->name('cancel');
        Route::get('{id}/pdf',        [\App\Http\Controllers\Pages\TagihanController::class, 'pdf'])->name('pdf');
        Route::get('{id}/buat-order', [\App\Http\Controllers\Pages\TagihanController::class, 'buatOrder'])->name('buatOrder');
    });

    Route::prefix('income')->name('income.')->group(function () {
        Route::get('order', [IncomeController::class, 'indexOrderIncome'])->name('order');
        Route::get('payment', [IncomeController::class, 'indexPaymentIncome'])->name('payment');
        Route::get('pending', [IncomeController::class, 'incomeReport'])->name('pending');
        Route::get('full-payment', [IncomeController::class, 'incomeLunas'])->name('lunas');
    });
    
    //Route User management
    Route::prefix('user-management')->group(function() {
        Route::get('', [ManagementUserController::class, 'index'])->name('user.management');
        Route::post('', [ManagementUserController::class, 'store'])->name('user.management.store');
        Route::put('edit/{user}', [ManagementUserController::class, 'update'])->name('user.management.update');
        Route::delete('destroy/{user}', [ManagementUserController::class, 'destroy'])->name('user.management.destroy');
        Route::post('{user}/restore', [ManagementUserController::class, 'restore'])->name('user.management.restore')->withTrashed();
        Route::post('{user}/force-delete', [ManagementUserController::class, 'forceDelete'])
        ->name('user.management.forceDelete')
        ->withTrashed();
    })->middleware(['can:access-usermanagement']);
});


Route::fallback(function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return redirect('/');
});

require __DIR__.'/auth.php';
