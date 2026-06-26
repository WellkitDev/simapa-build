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
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Pages\MarketingTargetController;
use App\Http\Controllers\Pages\AnnouncementController;
use App\Http\Controllers\Pages\TaskController;
use App\Http\Controllers\Pages\DailyReportController;

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

    //notifications
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.readAll');

    //Order book
    Route::prefix('order')->name('order.')->group(function () {

        Route::get('buku/create', [OrderBookController::class, 'create'])->name('book.create')->middleware('role:marketing|manager|superadmin');
        Route::post('buku/create', [OrderBookController::class, 'store'])->name('book.store')->middleware('role:marketing|manager|superadmin');
        Route::get('buku/show/{code_order}', [OrderBookController::class, 'show'])->name('book.show')->middleware('role:marketing|manager|superadmin');
        Route::get('buku/update/{code_order}', [OrderBookController::class, 'edit'])->name('book.edit')->middleware('role:marketing|manager|superadmin');
        Route::put('buku/update/{code_order}', [OrderBookController::class, 'update'])->name('book.update')->middleware('role:marketing|manager|superadmin');

        Route::get('jurnal/create', [OrderJournalController::class, 'create'])->name('journal.create')->middleware('role:marketing|manager|superadmin');
        Route::post('jurnal/create', [OrderJournalController::class, 'store'])->name('journal.store')->middleware('role:marketing|manager|superadmin');
        Route::get('jurnal/show/{code_order}', [OrderJournalController::class, 'show'])->name('journal.show')->middleware('role:marketing|manager|superadmin');
        Route::get('jurnal/update/{code_order}', [OrderJournalController::class, 'edit'])->name('journal.edit')->middleware('role:marketing|manager|superadmin');
        Route::put('jurnal/update/{code_order}', [OrderJournalController::class, 'update'])->name('journal.update')->middleware('role:marketing|manager|superadmin');
    });
    //order  journal
    Route::prefix('management')->name('order.')->group(function () {
        Route::get('order', [OrderBookController::class, 'index'])->name('book.index')->middleware('role:marketing|manager|superadmin');
        Route::get('title', [OrderBookController::class, 'indexJudul'])->name('book.indexJudul')->middleware('role:marketing|manager|superadmin');
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

    Route::prefix('payments')->name('payment.')->middleware('role:marketing|manager|superadmin')->group(function () {
        Route::get('list', [PaymentBookController::class, 'index'])->name('index');
        Route::get('{code_order}/create', [PaymentBookController::class, 'create'])->name('create');
        Route::post('{code_order}/create', [PaymentBookController::class, 'store'])->name('store');
        Route::post('approve/{id}', [PaymentBookController::class, 'approve'])->name('approve')->middleware('role:manager|superadmin');
        Route::post('reject/{id}', [PaymentBookController::class, 'reject'])->name('reject')->middleware('role:manager|superadmin');
        Route::put('{id}', [PaymentBookController::class, 'update'])
            ->name('update')
            ->middleware('role:manager|superadmin');

        //dp
        Route::get('dp', [DebtBookController::class, 'index'])->name('dp.index');

        //lunas
        Route::get('full', [FullPaymentBookController::class, 'index'])->name('fp.index');
    });

    Route::prefix('invoices')->name('invoice.')->middleware('role:marketing|manager|superadmin')->group(function () {
        Route::get('',             [InvoiceController::class, 'index'])->name('index');
        Route::get('{id}',         [InvoiceController::class, 'show'])->name('show');
        Route::get('{id}/edit',    [InvoiceController::class, 'edit'])->name('edit')->middleware('role:manager|superadmin');
        Route::put('{id}',         [InvoiceController::class, 'update'])->name('update')->middleware('role:manager|superadmin');
        Route::post('{id}/status', [InvoiceController::class, 'updateStatus'])->name('updateStatus')->middleware('role:manager|superadmin');
        Route::post('{id}/cancel', [InvoiceController::class, 'cancel'])->name('cancel')->middleware('role:manager|superadmin');
        Route::post('{id}/refund', [InvoiceController::class, 'refund'])->name('refund')->middleware('role:manager|superadmin');
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

    Route::prefix('income')->name('income.')->middleware('role:marketing|manager|superadmin')->group(function () {
        Route::get('pemasukan',     [IncomeController::class, 'pemasukan'])->name('pemasukan');
        Route::get('pemasukan/pdf', [IncomeController::class, 'pemasukanPdf'])->name('pemasukan.pdf');
        Route::get('pemasukan/csv', [IncomeController::class, 'pemasukanCsv'])->name('pemasukan.csv');
        Route::get('piutang',       [IncomeController::class, 'piutang'])->name('piutang');
        Route::get('piutang/pdf',   [IncomeController::class, 'piutangPdf'])->name('piutang.pdf');
        Route::get('piutang/csv',   [IncomeController::class, 'piutangCsv'])->name('piutang.csv');
        Route::get('lunas',         [IncomeController::class, 'lunas'])->name('lunas');
        Route::get('lunas/pdf',     [IncomeController::class, 'lunasPdf'])->name('lunas.pdf');
        Route::get('lunas/csv',     [IncomeController::class, 'lunasCsv'])->name('lunas.csv');
    });
    
    Route::middleware('role:manager|superadmin')->group(function () {
        Route::get('marketing-target', [MarketingTargetController::class, 'index'])->name('marketing-target.index');
        Route::post('marketing-target', [MarketingTargetController::class, 'store'])->name('marketing-target.store');
        Route::post('marketing-target/{id}/paid', [MarketingTargetController::class, 'paid'])->name('marketing-target.paid');
        Route::delete('marketing-target/{id}', [MarketingTargetController::class, 'destroy'])->name('marketing-target.destroy');
    });

    Route::get('target', [MarketingTargetController::class, 'me'])
        ->name('marketing-target.me')
        ->middleware('role:marketing|manager|superadmin');

    // Tandai pengumuman dibaca: sengaja terbuka untuk SEMUA role terautentikasi (bukan hanya admin).
    Route::post('announcements/seen', [AnnouncementController::class, 'seen'])->name('announcement.seen');

    Route::middleware('role:superadmin|manager|admin')->group(function () {
        Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcement.index');
        Route::get('announcements/create', [AnnouncementController::class, 'create'])->name('announcement.create');
        Route::post('announcements', [AnnouncementController::class, 'store'])->name('announcement.store');
        Route::get('announcements/{id}/edit', [AnnouncementController::class, 'edit'])->name('announcement.edit');
        Route::put('announcements/{id}', [AnnouncementController::class, 'update'])->name('announcement.update');
        Route::delete('announcements/{id}', [AnnouncementController::class, 'destroy'])->name('announcement.destroy');
        Route::post('announcements/{id}/status', [AnnouncementController::class, 'status'])->name('announcement.status');
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

    Route::get('tasks', [TaskController::class, 'index'])->name('task.index');
    Route::get('tasks/board', [TaskController::class, 'board'])->name('task.board');
    Route::get('tasks/calendar', [TaskController::class, 'calendar'])->name('task.calendar');
    Route::get('tasks/events', [TaskController::class, 'events'])->name('task.events');
    Route::post('tasks/reorder', [TaskController::class, 'reorder'])->name('task.reorder');
    Route::post('tasks', [TaskController::class, 'store'])->name('task.store');
    Route::put('tasks/{id}', [TaskController::class, 'update'])->name('task.update');
    Route::delete('tasks/{id}', [TaskController::class, 'destroy'])->name('task.destroy');
    Route::patch('tasks/{id}/status', [TaskController::class, 'status'])->name('task.status');
    Route::patch('tasks/{id}/schedule', [TaskController::class, 'schedule'])->name('task.schedule');

    Route::middleware('role:manager|superadmin')->group(function () {
        Route::get('tasks/monitor', [TaskController::class, 'monitor'])->name('task.monitor');
    });

    Route::get('reports/daily', [DailyReportController::class, 'daily'])->name('report.daily');
    Route::post('reports/daily/note', [DailyReportController::class, 'saveNote'])->name('report.note');
    Route::post('reports/daily/submit', [DailyReportController::class, 'submit'])->name('report.submit');
    Route::post('reports/daily/files', [DailyReportController::class, 'storeFile'])->name('report.files.store');
    Route::delete('reports/daily/files/{id}', [DailyReportController::class, 'destroyFile'])->name('report.files.destroy');
    Route::get('reports/monthly', [DailyReportController::class, 'monthly'])->name('report.monthly');

    Route::middleware('role:manager|superadmin')->group(function () {
        Route::get('reports/submissions', [DailyReportController::class, 'submissions'])->name('report.submissions');
    });
});


Route::fallback(function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return redirect('/');
});

require __DIR__.'/auth.php';
