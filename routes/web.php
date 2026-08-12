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
use App\Http\Controllers\Pages\TitleController;
use App\Http\Controllers\Pages\AuthorController;
use App\Http\Controllers\Pages\JournalController;
use App\Http\Controllers\Pages\JournalSubmissionController;
use App\Http\Controllers\Pages\BookIsbnController;
use App\Http\Controllers\Pages\DocRequirementController;
use App\Http\Controllers\Pages\TitleDocCheckController;
use App\Http\Controllers\Pages\DataAssetController;

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

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard')->middleware(['auth', 'verified', 'access']);

Route::middleware(['auth', 'access'])->group(function () {
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

        Route::get('buku/create', [OrderBookController::class, 'create'])->name('book.create');
        Route::post('buku/create', [OrderBookController::class, 'store'])->name('book.store');
        Route::get('buku/show/{code_order}', [OrderBookController::class, 'show'])->name('book.show');
        Route::get('buku/update/{code_order}', [OrderBookController::class, 'edit'])->name('book.edit');
        Route::put('buku/update/{code_order}', [OrderBookController::class, 'update'])->name('book.update');

        Route::get('jurnal/create', [OrderJournalController::class, 'create'])->name('journal.create');
        Route::post('jurnal/create', [OrderJournalController::class, 'store'])->name('journal.store');
        Route::get('jurnal/show/{code_order}', [OrderJournalController::class, 'show'])->name('journal.show');
        Route::get('jurnal/update/{code_order}', [OrderJournalController::class, 'edit'])->name('journal.edit');
        Route::put('jurnal/update/{code_order}', [OrderJournalController::class, 'update'])->name('journal.update');

        Route::get('refund/{code_order}', [\App\Http\Controllers\Pages\RefundController::class, 'form'])->name('refund.form');
        Route::post('refund/{code_order}', [\App\Http\Controllers\Pages\RefundController::class, 'store'])->name('refund.store');
        Route::get('refund/{code_order}/pdf', [\App\Http\Controllers\Pages\RefundController::class, 'pdf'])->name('refund.pdf');
    });
    //order  journal
    Route::prefix('management')->name('order.')->group(function () {
        Route::get('order', [OrderBookController::class, 'index'])->name('book.index');
        Route::get('title', [OrderBookController::class, 'indexJudul'])->name('book.indexJudul');
        Route::get('title/details/{id}', [OrderBookController::class, 'detailJudul'])->name('indexJudul.detail');
        Route::get('title/order/{id}', [OrderBookController::class, 'progressDetail'])->name('indexJudul.progress');
    });

    Route::prefix('management')->group(function () {
        Route::get('title/{id}/logs', [TitleProgressController::class, 'logs'])
            ->name('title.progress.logs');
    });

    Route::prefix('management')->group(function () {
        Route::get('manuscript', [ManuscriptTrackerController::class, 'index'])
            ->name('manuscript.board');
    });

    Route::prefix('management/distribusi')->name('distribusi.')->group(function () {
        Route::get('artikel',              [\App\Http\Controllers\Pages\ArticleDistributionController::class, 'index'])->name('artikel.index');
        Route::get('artikel/{id}',         [\App\Http\Controllers\Pages\ArticleDistributionController::class, 'show'])->name('artikel.show')->whereNumber('id');
        Route::post('artikel/{id}/editor',    [\App\Http\Controllers\Pages\ArticleDistributionController::class, 'assignEditor'])->name('artikel.editor')->whereNumber('id');
        Route::post('artikel/{id}/tahap',     [\App\Http\Controllers\Pages\ArticleDistributionController::class, 'moveStage'])->name('artikel.tahap')->whereNumber('id');
        Route::post('artikel/{id}/prioritas', [\App\Http\Controllers\Pages\ArticleDistributionController::class, 'setPriority'])->name('artikel.prioritas')->whereNumber('id');
        Route::post('artikel/{id}/target',    [\App\Http\Controllers\Pages\ArticleDistributionController::class, 'setTarget'])->name('artikel.target')->whereNumber('id');
        Route::post('artikel/{id}/file',      [\App\Http\Controllers\Pages\ArticleDistributionController::class, 'uploadFile'])->name('artikel.file')->whereNumber('id');

        Route::get('buku',                    [\App\Http\Controllers\Pages\BookDistributionController::class, 'index'])->name('buku.index');
        Route::get('buku/{id}',               [\App\Http\Controllers\Pages\BookDistributionController::class, 'show'])->name('buku.show')->whereNumber('id');
        Route::post('buku/{id}/editor-semua', [\App\Http\Controllers\Pages\BookDistributionController::class, 'assignEditorAll'])->name('buku.editorSemua')->whereNumber('id');
        Route::post('buku/{id}/prioritas',    [\App\Http\Controllers\Pages\BookDistributionController::class, 'setPriority'])->name('buku.prioritas')->whereNumber('id');
        Route::post('buku/{id}/target',       [\App\Http\Controllers\Pages\BookDistributionController::class, 'setTarget'])->name('buku.target')->whereNumber('id');
        Route::post('buku/{id}/file',         [\App\Http\Controllers\Pages\BookDistributionController::class, 'uploadFile'])->name('buku.file')->whereNumber('id');
        Route::post('buku/chapter/{cp}/editor', [\App\Http\Controllers\Pages\BookDistributionController::class, 'assignChapterEditor'])->name('buku.chapter.editor')->whereNumber('cp');
        Route::post('buku/chapter/{cp}/tahap',  [\App\Http\Controllers\Pages\BookDistributionController::class, 'moveChapter'])->name('buku.chapter.tahap')->whereNumber('cp');
        Route::post('buku/chapter/{cp}/file',   [\App\Http\Controllers\Pages\BookDistributionController::class, 'uploadChapterFile'])->name('buku.chapter.file')->whereNumber('cp');
    });

    Route::prefix('payments')->name('payment.')->group(function () {
        Route::get('list', [PaymentBookController::class, 'index'])->name('index');
        Route::get('{code_order}/create', [PaymentBookController::class, 'create'])->name('create');
        Route::post('{code_order}/create', [PaymentBookController::class, 'store'])->name('store');
        Route::post('approve/{id}', [PaymentBookController::class, 'approve'])->name('approve');
        Route::post('reject/{id}', [PaymentBookController::class, 'reject'])->name('reject');
        Route::put('{id}', [PaymentBookController::class, 'update'])
            ->name('update');

        //dp
        Route::get('dp', [DebtBookController::class, 'index'])->name('dp.index');

        //lunas
        Route::get('full', [FullPaymentBookController::class, 'index'])->name('fp.index');
    });

    Route::prefix('invoices')->name('invoice.')->group(function () {
        Route::get('',             [InvoiceController::class, 'index'])->name('index');
        Route::get('{id}',         [InvoiceController::class, 'show'])->name('show');
        Route::get('{id}/edit',    [InvoiceController::class, 'edit'])->name('edit');
        Route::put('{id}',         [InvoiceController::class, 'update'])->name('update');
        Route::post('{id}/status', [InvoiceController::class, 'updateStatus'])->name('updateStatus');
        Route::post('{id}/cancel', [InvoiceController::class, 'cancel'])->name('cancel');
        Route::get('{id}/logs',    [InvoiceController::class, 'logs'])->name('logs');
        Route::get('{id}/pdf', [InvoiceController::class, 'pdf'])->name('pdf');
    });

    Route::prefix('tagihan')->name('tagihan.')->group(function () {
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

    Route::get('marketing-target', [MarketingTargetController::class, 'index'])->name('marketing-target.index');
    Route::post('marketing-target', [MarketingTargetController::class, 'store'])->name('marketing-target.store');
    Route::post('marketing-target/{id}/paid', [MarketingTargetController::class, 'paid'])->name('marketing-target.paid');
    Route::delete('marketing-target/{id}', [MarketingTargetController::class, 'destroy'])->name('marketing-target.destroy');

    Route::get('target', [MarketingTargetController::class, 'me'])
        ->name('marketing-target.me');

    // Tandai pengumuman dibaca: sengaja terbuka untuk SEMUA role terautentikasi (bukan hanya admin).
    Route::post('announcements/seen', [AnnouncementController::class, 'seen'])->name('announcement.seen');

    Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcement.index');
    Route::get('announcements/create', [AnnouncementController::class, 'create'])->name('announcement.create');
    Route::post('announcements', [AnnouncementController::class, 'store'])->name('announcement.store');
    Route::get('announcements/{id}/edit', [AnnouncementController::class, 'edit'])->name('announcement.edit');
    Route::put('announcements/{id}', [AnnouncementController::class, 'update'])->name('announcement.update');
    Route::delete('announcements/{id}', [AnnouncementController::class, 'destroy'])->name('announcement.destroy');
    Route::post('announcements/{id}/status', [AnnouncementController::class, 'status'])->name('announcement.status');

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

    Route::get('tasks/monitor', [TaskController::class, 'monitor'])->name('task.monitor');

    Route::get('reports/daily', [DailyReportController::class, 'daily'])->name('report.daily');
    Route::post('reports/daily/note', [DailyReportController::class, 'saveNote'])->name('report.note');
    Route::post('reports/daily/submit', [DailyReportController::class, 'submit'])->name('report.submit');
    Route::post('reports/daily/files', [DailyReportController::class, 'storeFile'])->name('report.files.store');
    Route::delete('reports/daily/files/{id}', [DailyReportController::class, 'destroyFile'])->name('report.files.destroy');
    Route::get('reports/monthly', [DailyReportController::class, 'monthly'])->name('report.monthly');

    Route::get('reports/submissions', [DailyReportController::class, 'submissions'])->name('report.submissions');

    // Direktori Judul — index & show accessible to all authenticated roles (incl. marketing)
    Route::get('titles', [TitleController::class, 'index'])->name('title.index');
    Route::get('titles/{id}', [TitleController::class, 'show'])->name('title.show')->whereNumber('id');
    Route::get('titles/create', [TitleController::class, 'create'])->name('title.create');
    Route::post('titles', [TitleController::class, 'store'])->name('title.store');
    Route::get('titles/{id}/edit', [TitleController::class, 'edit'])->name('title.edit')->whereNumber('id');
    Route::put('titles/{id}', [TitleController::class, 'update'])->name('title.update')->whereNumber('id');
    Route::delete('titles/{id}', [TitleController::class, 'destroy'])->name('title.destroy')->whereNumber('id');
    Route::post('titles/{id}/submit', [TitleController::class, 'submit'])->name('title.submit')->whereNumber('id');
    Route::post('titles/{id}/approve', [TitleController::class, 'approve'])->name('title.approve')->whereNumber('id');
    Route::post('titles/{id}/reject', [TitleController::class, 'reject'])->name('title.reject')->whereNumber('id');
    Route::put('titles/{id}/info', [TitleController::class, 'updateInfo'])->name('title.info.update')->whereNumber('id');
    Route::put('titles/{id}/chapter-authors', [TitleController::class, 'updateChapterAuthors'])->name('title.chapters.authors')->whereNumber('id');

    // Direktori Author (read-only) — list + detail riwayat order
    // Dibatasi: production & accounting tidak boleh akses.
    Route::get('authors', [AuthorController::class, 'index'])->name('author.index');
    Route::get('authors/{id}', [AuthorController::class, 'show'])->name('author.show')->whereNumber('id');

    // Direktori Jurnal — index & show accessible to all authenticated roles
    Route::get('journals', [JournalController::class, 'index'])->name('journal.index');
    Route::get('journals/{id}', [JournalController::class, 'show'])->name('journal.show')->whereNumber('id');
    Route::get('journals/create', [JournalController::class, 'create'])->name('journal.create');
    Route::post('journals', [JournalController::class, 'store'])->name('journal.store');
    Route::get('journals/{id}/edit', [JournalController::class, 'edit'])->name('journal.edit')->whereNumber('id');
    Route::put('journals/{id}', [JournalController::class, 'update'])->name('journal.update')->whereNumber('id');
    Route::delete('journals/{id}', [JournalController::class, 'destroy'])->name('journal.destroy')->whereNumber('id');
    Route::post('journals/{journal}/submissions', [JournalSubmissionController::class, 'store'])->name('journal.submission.store')->whereNumber('journal');
    Route::put('journals/submissions/{id}', [JournalSubmissionController::class, 'update'])->name('journal.submission.update')->whereNumber('id');
    Route::delete('journals/submissions/{id}', [JournalSubmissionController::class, 'destroy'])->name('journal.submission.destroy')->whereNumber('id');

    // Direktori ISBN — index utk semua staf; mutasi utk pengelola (production ikut, pemegang tahap isbn)
    Route::get('management/isbn', [BookIsbnController::class, 'index'])->name('isbn.index');
    Route::post('management/isbn', [BookIsbnController::class, 'store'])->name('isbn.store');
    Route::put('management/isbn/{id}', [BookIsbnController::class, 'update'])->name('isbn.update')->whereNumber('id');
    Route::delete('management/isbn/{id}', [BookIsbnController::class, 'destroy'])->name('isbn.destroy')->whereNumber('id');

    // Template checklist dokumen — CRUD superadmin
    Route::post('doc-requirements', [DocRequirementController::class, 'store'])->name('doc-req.store');
    Route::put('doc-requirements/{id}', [DocRequirementController::class, 'update'])->name('doc-req.update')->whereNumber('id');
    Route::delete('doc-requirements/{id}', [DocRequirementController::class, 'destroy'])->name('doc-req.destroy')->whereNumber('id');
    // Cek kelengkapan dokumen per judul — superadmin/admin
    Route::put('titles/{id}/doc-check', [TitleDocCheckController::class, 'save'])->name('title.doc.save')->whereNumber('id');
    Route::post('titles/{id}/doc-check/submit', [TitleDocCheckController::class, 'submit'])->name('title.doc.submit')->whereNumber('id');

    // Arsip Judul selesai
    Route::get('management/archive', [\App\Http\Controllers\Pages\TitleArchiveController::class, 'index'])->name('archive.index');
    Route::get('management/archive/{id}', [\App\Http\Controllers\Pages\TitleArchiveController::class, 'show'])->name('archive.show')->whereNumber('id');
    Route::get('management/archive/{id}/pdf', [\App\Http\Controllers\Pages\TitleArchiveController::class, 'pdf'])->name('archive.pdf')->whereNumber('id');
    Route::put('management/archive/{id}/artifacts', [\App\Http\Controllers\Pages\TitleArchiveController::class, 'saveArtifacts'])->name('archive.artifacts')->whereNumber('id');
    Route::post('management/archive/{id}/submit', [\App\Http\Controllers\Pages\TitleArchiveController::class, 'submit'])->name('archive.submit')->whereNumber('id');
    Route::post('management/archive/{id}/approve', [\App\Http\Controllers\Pages\TitleArchiveController::class, 'approve'])->name('archive.approve')->whereNumber('id');
    Route::post('management/archive/{id}/reject', [\App\Http\Controllers\Pages\TitleArchiveController::class, 'reject'])->name('archive.reject')->whereNumber('id');

    // Akuntansi — Jurnal Kas (superadmin/accounting)
    Route::get('accounting/overview', [\App\Http\Controllers\Pages\AccountingOverviewController::class, 'index'])->name('accounting.overview');
    Route::get('accounting/journal', [\App\Http\Controllers\Pages\CashEntryController::class, 'index'])->name('accounting.journal');
    Route::get('accounting/journal/export/csv', [\App\Http\Controllers\Pages\CashEntryController::class, 'exportCsv'])->name('accounting.journal.export.csv');
    Route::get('accounting/journal/export/pdf', [\App\Http\Controllers\Pages\CashEntryController::class, 'exportPdf'])->name('accounting.journal.export.pdf');
    Route::get('accounting/dashboard', [\App\Http\Controllers\Pages\AccountingDashboardController::class, 'index'])->name('accounting.dashboard');
    Route::get('accounting/recap/export/csv', [\App\Http\Controllers\Pages\AccountingDashboardController::class, 'exportCsv'])->name('accounting.recap.export.csv');
    Route::get('accounting/recap/export/pdf', [\App\Http\Controllers\Pages\AccountingDashboardController::class, 'exportPdf'])->name('accounting.recap.export.pdf');
    Route::get('accounting/distribution', [\App\Http\Controllers\Pages\ProfitDistributionController::class, 'index'])->name('accounting.distribution');
    Route::put('accounting/distribution/settings', [\App\Http\Controllers\Pages\ProfitDistributionController::class, 'updateSetting'])->name('accounting.distribution.settings');
    Route::post('accounting/distribution/rule', [\App\Http\Controllers\Pages\ProfitDistributionController::class, 'storeRule'])->name('accounting.distribution.rule.store');
    Route::put('accounting/distribution/rule/{id}', [\App\Http\Controllers\Pages\ProfitDistributionController::class, 'updateRule'])->name('accounting.distribution.rule.update')->whereNumber('id');
    Route::delete('accounting/distribution/rule/{id}', [\App\Http\Controllers\Pages\ProfitDistributionController::class, 'destroyRule'])->name('accounting.distribution.rule.destroy')->whereNumber('id');
    Route::get('accounting/assumption', [\App\Http\Controllers\Pages\CashAssumptionController::class, 'index'])->name('accounting.assumption');
    Route::post('accounting/assumption/margin', [\App\Http\Controllers\Pages\CashAssumptionController::class, 'storeMargin'])->name('accounting.assumption.margin.store');
    Route::put('accounting/assumption/margin/{id}', [\App\Http\Controllers\Pages\CashAssumptionController::class, 'updateMargin'])->name('accounting.assumption.margin.update')->whereNumber('id');
    Route::delete('accounting/assumption/margin/{id}', [\App\Http\Controllers\Pages\CashAssumptionController::class, 'destroyMargin'])->name('accounting.assumption.margin.destroy')->whereNumber('id');
    Route::post('accounting/assumption/expense', [\App\Http\Controllers\Pages\CashAssumptionController::class, 'storeExpense'])->name('accounting.assumption.expense.store');
    Route::put('accounting/assumption/expense/{id}', [\App\Http\Controllers\Pages\CashAssumptionController::class, 'updateExpense'])->name('accounting.assumption.expense.update')->whereNumber('id');
    Route::delete('accounting/assumption/expense/{id}', [\App\Http\Controllers\Pages\CashAssumptionController::class, 'destroyExpense'])->name('accounting.assumption.expense.destroy')->whereNumber('id');
    Route::get('accounting/target', [\App\Http\Controllers\Pages\BudgetTargetController::class, 'index'])->name('accounting.target');
    Route::put('accounting/target', [\App\Http\Controllers\Pages\BudgetTargetController::class, 'updateTarget'])->name('accounting.target.update');
    Route::post('accounting/entry', [\App\Http\Controllers\Pages\CashEntryController::class, 'store'])->name('accounting.entry.store');
    Route::put('accounting/entry/{id}', [\App\Http\Controllers\Pages\CashEntryController::class, 'update'])->name('accounting.entry.update')->whereNumber('id');
    Route::delete('accounting/entry/{id}', [\App\Http\Controllers\Pages\CashEntryController::class, 'destroy'])->name('accounting.entry.destroy')->whereNumber('id');
    Route::post('accounting/category', [\App\Http\Controllers\Pages\CashCategoryController::class, 'store'])->name('accounting.category.store');
    Route::put('accounting/category/{id}', [\App\Http\Controllers\Pages\CashCategoryController::class, 'update'])->name('accounting.category.update')->whereNumber('id');
    Route::delete('accounting/category/{id}', [\App\Http\Controllers\Pages\CashCategoryController::class, 'destroy'])->name('accounting.category.destroy')->whereNumber('id');
    Route::post('accounting/account', [\App\Http\Controllers\Pages\CashAccountController::class, 'store'])->name('accounting.account.store');
    Route::put('accounting/account/{id}', [\App\Http\Controllers\Pages\CashAccountController::class, 'update'])->name('accounting.account.update')->whereNumber('id');
    Route::delete('accounting/account/{id}', [\App\Http\Controllers\Pages\CashAccountController::class, 'destroy'])->name('accounting.account.destroy')->whereNumber('id');
    Route::post('accounting/transfer', [\App\Http\Controllers\Pages\CashEntryController::class, 'transfer'])->name('accounting.transfer.store');

    // Kunci/buka kunci periode = tata kelola, bukan pembukuan harian → superadmin saja.
    Route::post('accounting/period/lock', [\App\Http\Controllers\Pages\CashPeriodController::class, 'lock'])->name('accounting.period.lock');
    Route::post('accounting/period/unlock', [\App\Http\Controllers\Pages\CashPeriodController::class, 'unlock'])->name('accounting.period.unlock');
    Route::get('accounting/audit', [\App\Http\Controllers\Pages\CashPeriodController::class, 'audit'])->name('accounting.audit');
    Route::get('accounting/profit', [\App\Http\Controllers\Pages\ProfitAnalysisController::class, 'index'])->name('accounting.profit');

    //Gudang Data
    Route::get('gudang', [DataAssetController::class, 'index'])->name('data.index');
    Route::get('gudang/tambah', [DataAssetController::class, 'create'])->name('data.create');
    Route::post('gudang', [DataAssetController::class, 'store'])->name('data.store');
    Route::get('gudang/{id}/edit', [DataAssetController::class, 'edit'])->name('data.edit')->whereNumber('id');
    Route::put('gudang/{id}', [DataAssetController::class, 'update'])->name('data.update')->whereNumber('id');
    Route::get('gudang/{id}/download', [DataAssetController::class, 'download'])->name('data.download')->whereNumber('id');
    Route::delete('gudang/{id}', [DataAssetController::class, 'destroy'])->name('data.destroy')->whereNumber('id');

    // Slip Gaji Karyawan (superadmin/accounting) — admin
    Route::get('salary/slip', [\App\Http\Controllers\Pages\SalarySlipController::class, 'index'])->name('salary.slip.index');
    Route::get('salary/slip/create', [\App\Http\Controllers\Pages\SalarySlipController::class, 'create'])->name('salary.slip.create');
    Route::post('salary/slip', [\App\Http\Controllers\Pages\SalarySlipController::class, 'store'])->name('salary.slip.store');
    Route::get('salary/slip/{id}', [\App\Http\Controllers\Pages\SalarySlipController::class, 'show'])->name('salary.slip.show')->whereNumber('id');
    Route::get('salary/slip/{id}/edit', [\App\Http\Controllers\Pages\SalarySlipController::class, 'edit'])->name('salary.slip.edit')->whereNumber('id');
    Route::put('salary/slip/{id}', [\App\Http\Controllers\Pages\SalarySlipController::class, 'update'])->name('salary.slip.update')->whereNumber('id');
    Route::delete('salary/slip/{id}', [\App\Http\Controllers\Pages\SalarySlipController::class, 'destroy'])->name('salary.slip.destroy')->whereNumber('id');
    Route::get('salary/slip/{id}/pdf', [\App\Http\Controllers\Pages\SalarySlipController::class, 'pdf'])->name('salary.slip.pdf')->whereNumber('id');
    Route::post('salary/slip/{id}/send', [\App\Http\Controllers\Pages\SalarySlipController::class, 'send'])->name('salary.slip.send')->whereNumber('id');

    // Slip Gaji — self-service (semua user login; akses own-data dicek di controller)
    Route::get('slip-gaji-saya', [\App\Http\Controllers\Pages\EmployeeSalarySlipController::class, 'me'])->name('salary.slip.me');
    Route::get('slip-gaji-saya/{id}/pdf', [\App\Http\Controllers\Pages\EmployeeSalarySlipController::class, 'pdf'])->name('salary.slip.me.pdf')->whereNumber('id');

    /* ===================== LAYANAN / JASA (modul standalone) ===================== */
    Route::prefix('layanan')->name('service.')->group(function () {
        // Invoice layanan
        Route::get ('invoice',        [\App\Http\Controllers\Pages\ServiceInvoiceController::class, 'index'])  ->name('invoice.index');
        Route::get ('invoice/create', [\App\Http\Controllers\Pages\ServiceInvoiceController::class, 'create']) ->name('invoice.create');
        Route::post('invoice',        [\App\Http\Controllers\Pages\ServiceInvoiceController::class, 'store'])  ->name('invoice.store');
        Route::get ('invoice/{id}',   [\App\Http\Controllers\Pages\ServiceInvoiceController::class, 'show'])   ->name('invoice.show')->whereNumber('id');
        Route::get   ('invoice/{id}/edit', [\App\Http\Controllers\Pages\ServiceInvoiceController::class, 'edit'])   ->name('invoice.edit')->whereNumber('id');
        Route::put   ('invoice/{id}',      [\App\Http\Controllers\Pages\ServiceInvoiceController::class, 'update']) ->name('invoice.update')->whereNumber('id');
        Route::delete('invoice/{id}',      [\App\Http\Controllers\Pages\ServiceInvoiceController::class, 'destroy'])->name('invoice.destroy')->whereNumber('id');
        Route::post('invoice/{id}/status', [\App\Http\Controllers\Pages\ServiceInvoiceController::class, 'status'])->name('invoice.status')->whereNumber('id');
        Route::post('invoice/{id}/cancel', [\App\Http\Controllers\Pages\ServiceInvoiceController::class, 'cancel'])->name('invoice.cancel')->whereNumber('id');
        Route::post  ('invoice/{id}/payment',              [\App\Http\Controllers\Pages\ServiceInvoicePaymentController::class, 'store'])  ->name('invoice.payment.store')->whereNumber('id');
        Route::delete('invoice/{id}/payment/{paymentId}',  [\App\Http\Controllers\Pages\ServiceInvoicePaymentController::class, 'destroy'])->name('invoice.payment.destroy')->whereNumber('id')->whereNumber('paymentId');
        Route::get('invoice/{id}/pdf', [\App\Http\Controllers\Pages\ServiceInvoiceController::class, 'pdf'])->name('invoice.pdf')->whereNumber('id')->middleware('throttle:export');

        // Katalog
        Route::get   ('katalog',      [\App\Http\Controllers\Pages\ServiceCatalogController::class, 'index'])  ->name('catalog.index');
        Route::post  ('katalog',      [\App\Http\Controllers\Pages\ServiceCatalogController::class, 'store'])  ->name('catalog.store');
        Route::put   ('katalog/{id}', [\App\Http\Controllers\Pages\ServiceCatalogController::class, 'update']) ->name('catalog.update')->whereNumber('id');
        Route::delete('katalog/{id}', [\App\Http\Controllers\Pages\ServiceCatalogController::class, 'destroy'])->name('catalog.destroy')->whereNumber('id');

        // Klien jasa
        Route::get   ('klien',      [\App\Http\Controllers\Pages\ServiceClientController::class, 'index'])  ->name('client.index');
        Route::post  ('klien',      [\App\Http\Controllers\Pages\ServiceClientController::class, 'store'])  ->name('client.store');
        Route::get   ('klien/{id}', [\App\Http\Controllers\Pages\ServiceClientController::class, 'show'])   ->name('client.show')->whereNumber('id');
        Route::put   ('klien/{id}', [\App\Http\Controllers\Pages\ServiceClientController::class, 'update']) ->name('client.update')->whereNumber('id');
        Route::delete('klien/{id}', [\App\Http\Controllers\Pages\ServiceClientController::class, 'destroy'])->name('client.destroy')->whereNumber('id');
    });
});


Route::fallback(function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return redirect('/');
});

require __DIR__.'/auth.php';
