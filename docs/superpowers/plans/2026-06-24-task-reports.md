# Task Reports (Phase 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Report Harian (rekap otomatis dari `tb_tasks` + catatan + alur Kirim/Submit + lampiran Dropzone→Google Drive) & Report Bulanan (agregasi read-only) + Pemantauan Report untuk manager.

**Architecture:** Rekap **live** dari `tb_tasks` (nol input ganda) + 2 tabel baru (`tb_daily_reports` catatan/status, `tb_daily_report_files` metadata lampiran). `DailyReportService` (recap/monthly/submissions/getOrCreate) + `DailyReportController` (views + JSON endpoint upload/hapus file). Lampiran pakai Dropzone (drag-drop, progress, kompres gambar) → `GoogleDriveService`. Kepemilikan ditegakkan di controller; mutasi selalu milik sendiri.

**Tech Stack:** PHP 8.2 / Laravel 11, Spatie roles, Blade + Bootstrap 5, **Dropzone** (`assets/plugins/dropzone/dropzone.min.{js,css}`), **flatpickr**, **DataTables** (`assets/libs/datatables.net*`), existing `GoogleDriveService` (`uploadFile`/`deleteFile`/`getOrCreateFolderByPath`). Tanpa dependency baru.

**Spec:** `docs/superpowers/specs/2026-06-24-task-reports-design.md`

**Catatan env:** Tests pakai DB test via `.env.testing` (`RefreshDatabase`). DB error → MySQL/XAMPP mati: `Start-Process -FilePath "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini' -WindowStyle Hidden`, tunggu ~6 dtk, ulangi. Setelah selesai: `php artisan migrate` di dev (2 tabel). Tests memock `GoogleDriveService` (tak menyentuh Drive asli).

**Konvensi yang ditiru:** CSRF di JS dari `<meta name="_token">`. DataTables `@foreach` + `language.emptyTable`. Drive upload pola `ProfileController`/`PaymentBookController` (`__construct(GoogleDriveService $drive)` + `$this->drive->uploadFile($file,$folderId,true)`). Mock Drive di test pola `DetailOrderPaymentInvoiceTest` (`$m->shouldReceive('uploadFile')->andReturn([...])`).

---

## File Structure

**Create:** migrasi `2026_06_24_000004_create_tb_daily_reports_table.php`, `2026_06_24_000005_create_tb_daily_report_files_table.php`; `app/Models/DailyReport.php`, `app/Models/DailyReportFile.php`; `app/Services/DailyReportService.php`; `app/Http/Controllers/Pages/DailyReportController.php`; `resources/views/reports/{daily,monthly,submissions}.blade.php`; tests `tests/Unit/DailyReportServiceTest.php`, `tests/Feature/DailyReportControllerTest.php`, `tests/Feature/DailyReportPagesTest.php`.

**Modify:** `routes/web.php`, `resources/views/layouts/sidebar.blade.php`.

---

## Task 1: Migrations + Models

**Files:**
- Create: `database/migrations/2026_06_24_000004_create_tb_daily_reports_table.php`
- Create: `database/migrations/2026_06_24_000005_create_tb_daily_report_files_table.php`
- Create: `app/Models/DailyReport.php`, `app/Models/DailyReportFile.php`

- [ ] **Step 1: Migration `tb_daily_reports`**

Create `database/migrations/2026_06_24_000004_create_tb_daily_reports_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_daily_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('report_date');
            $table->text('note')->nullable();
            $table->string('status', 16)->default('draft'); // draft | submitted
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'report_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_daily_reports');
    }
};
```

- [ ] **Step 2: Migration `tb_daily_report_files`**

Create `database/migrations/2026_06_24_000005_create_tb_daily_report_files_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_daily_report_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_report_id')->constrained('tb_daily_reports')->cascadeOnDelete();
            $table->string('drive_file_id');
            $table->string('name');
            $table->string('url', 1024);
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_daily_report_files');
    }
};
```

- [ ] **Step 3: Model `DailyReport`**

Create `app/Models/DailyReport.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyReport extends Model
{
    use HasFactory;

    protected $table = 'tb_daily_reports';

    protected $fillable = ['user_id', 'report_date', 'note', 'status', 'submitted_at'];

    protected $casts = [
        'report_date'  => 'date',
        'submitted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function files()
    {
        return $this->hasMany(DailyReportFile::class);
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }
}
```

- [ ] **Step 4: Model `DailyReportFile`**

Create `app/Models/DailyReportFile.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyReportFile extends Model
{
    use HasFactory;

    protected $table = 'tb_daily_report_files';

    protected $fillable = ['daily_report_id', 'drive_file_id', 'name', 'url', 'mime', 'size', 'uploaded_by'];

    public function report()
    {
        return $this->belongsTo(DailyReport::class, 'daily_report_id');
    }
}
```

- [ ] **Step 5: Verify migrations healthy**

Run: `php artisan test --filter=PaymentBookCleanupTest`
Expected: PASS (RefreshDatabase migrates both new tables cleanly).

- [ ] **Step 6: Commit**

```
git add database/migrations/2026_06_24_000004_create_tb_daily_reports_table.php database/migrations/2026_06_24_000005_create_tb_daily_report_files_table.php app/Models/DailyReport.php app/Models/DailyReportFile.php
git commit -m "feat(report): daily_reports + report_files tables and models

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 2: `DailyReportService` (TDD)

**Files:**
- Create: `app/Services/DailyReportService.php`
- Test: `tests/Unit/DailyReportServiceTest.php`

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/DailyReportServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Task;
use App\Models\DailyReport;
use App\Services\DailyReportService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DailyReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private DailyReportService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new DailyReportService();
    }

    private function task(User $u, array $a = []): Task
    {
        return Task::create(array_merge(['user_id' => $u->id, 'title' => 'T', 'status' => 'todo', 'priority' => 'normal'], $a));
    }

    /** @test */
    public function recap_buckets_by_date_and_scope(): void
    {
        $u = User::factory()->create();
        $other = User::factory()->create();
        $today = Carbon::today();
        $this->task($u, ['title' => 'Sel', 'status' => 'done', 'completed_at' => $today]);
        $this->task($u, ['title' => 'Ker', 'status' => 'in_progress']);
        $this->task($other, ['title' => 'Orang', 'status' => 'done', 'completed_at' => $today]);

        $r = $this->svc->recapFor($u, $today);

        $this->assertSame(1, $r['counts']['selesai']);
        $this->assertSame('Sel', $r['selesai'][0]->title);
        $this->assertSame(1, $r['counts']['dikerjakan']); // hari ini → in_progress tampil
    }

    /** @test */
    public function in_progress_excluded_for_past_dates(): void
    {
        $u = User::factory()->create();
        $this->task($u, ['status' => 'in_progress']);

        $r = $this->svc->recapFor($u, Carbon::yesterday());

        $this->assertCount(0, $r['dikerjakan']);
    }

    /** @test */
    public function monthly_recap_on_time_and_late(): void
    {
        $u = User::factory()->create();
        $today = Carbon::today();
        $this->task($u, ['status' => 'done', 'completed_at' => $today, 'due_date' => $today->toDateString()]);                 // tepat
        $this->task($u, ['status' => 'done', 'completed_at' => $today, 'due_date' => $today->copy()->subDay()->toDateString()]); // telat
        $this->task($u, ['status' => 'done', 'completed_at' => $today]);                                                        // tanpa due → dikecualikan dari rate

        $m = $this->svc->monthlyRecap($u, (int) $today->year, (int) $today->month);

        $this->assertSame(3, $m['selesai']);
        $this->assertSame(1, $m['tepat_waktu']);
        $this->assertSame(1, $m['telat']);
        $this->assertSame(50.0, $m['on_time_rate']); // 1 dari 2 yang ber-due_date
    }

    /** @test */
    public function submissions_flags_submitted_and_counts(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $today = Carbon::today();
        $this->task($a, ['status' => 'done', 'completed_at' => $today]);
        DailyReport::create(['user_id' => $a->id, 'report_date' => $today->toDateString(), 'status' => 'submitted', 'submitted_at' => now()]);

        $rows = $this->svc->submissionsForDate($today);
        $ra = $rows->firstWhere('id', $a->id);
        $rb = $rows->firstWhere('id', $b->id);

        $this->assertTrue($ra['submitted']);
        $this->assertSame(1, $ra['selesai']);
        $this->assertFalse($rb['submitted']);
    }

    /** @test */
    public function get_or_create_report_idempotent(): void
    {
        $u = User::factory()->create();
        $today = Carbon::today();

        $r1 = $this->svc->getOrCreateReport($u, $today);
        $r2 = $this->svc->getOrCreateReport($u, $today);

        $this->assertSame($r1->id, $r2->id);
        $this->assertSame(1, DailyReport::where('user_id', $u->id)->count());
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=DailyReportServiceTest`
Expected: FAIL — `Class "App\Services\DailyReportService" not found`.

- [ ] **Step 3: Implement the service**

Create `app/Services/DailyReportService.php`:

```php
<?php

namespace App\Services;

use App\Models\DailyReport;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DailyReportService
{
    /** Rekap tugas user untuk satu tanggal (live dari tb_tasks). */
    public function recapFor(User $user, Carbon $date): array
    {
        $selesai = Task::forUser($user->id)->whereDate('completed_at', $date)->orderByDesc('completed_at')->get();
        $dibuat  = Task::forUser($user->id)->whereDate('created_at', $date)->orderByDesc('created_at')->get();
        $dikerjakan = $date->isToday()
            ? Task::forUser($user->id)->where('status', 'in_progress')->get()
            : collect();

        return [
            'date'       => $date->toDateString(),
            'selesai'    => $selesai,
            'dibuat'     => $dibuat,
            'dikerjakan' => $dikerjakan,
            'counts'     => [
                'selesai'    => $selesai->count(),
                'dibuat'     => $dibuat->count(),
                'dikerjakan' => $dikerjakan->count(),
            ],
        ];
    }

    /** Agregasi bulanan (read-only). */
    public function monthlyRecap(User $user, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end   = $start->copy()->endOfMonth();

        $completed = Task::forUser($user->id)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$start, $end->copy()->endOfDay()])
            ->get();

        $withDue = $completed->filter(fn (Task $t) => $t->due_date !== null);
        $onTime  = $withDue->filter(fn (Task $t) => $t->completed_at->toDateString() <= $t->due_date->toDateString());

        $perHari = $completed->groupBy(fn (Task $t) => $t->completed_at->toDateString())->map->count();

        $reports = DailyReport::where('user_id', $user->id)
            ->whereBetween('report_date', [$start->toDateString(), $end->toDateString()])
            ->get()->keyBy(fn (DailyReport $r) => $r->report_date->toDateString());

        return [
            'year'         => $year,
            'month'        => $month,
            'selesai'      => $completed->count(),
            'tepat_waktu'  => $onTime->count(),
            'telat'        => $withDue->count() - $onTime->count(),
            'on_time_rate' => $withDue->count() > 0 ? round($onTime->count() / $withDue->count() * 100, 1) : null,
            'per_hari'     => $perHari,
            'reports'      => $reports,
            'dilaporkan'   => $reports->where('status', 'submitted')->count(),
        ];
    }

    /** Untuk Pemantauan: per user → sudah kirim? + jml selesai pada tanggal itu. */
    public function submissionsForDate(Carbon $date): Collection
    {
        $submitted = DailyReport::whereDate('report_date', $date)->where('status', 'submitted')->pluck('user_id')->flip();
        $doneCounts = Task::whereDate('completed_at', $date)->selectRaw('user_id, count(*) as c')->groupBy('user_id')->pluck('c', 'user_id');

        return User::orderBy('name')->get(['id', 'name'])->map(fn (User $u) => [
            'id'        => $u->id,
            'name'      => $u->name,
            'submitted' => $submitted->has($u->id),
            'selesai'   => (int) ($doneCounts[$u->id] ?? 0),
        ])->values();
    }

    /** Ambil/buat baris report untuk (user, tanggal) — agar catatan/lampiran punya induk. */
    public function getOrCreateReport(User $user, Carbon $date): DailyReport
    {
        return DailyReport::firstOrCreate(['user_id' => $user->id, 'report_date' => $date->toDateString()]);
    }
}
```

- [ ] **Step 4: Run, confirm PASS**

Run: `php artisan test --filter=DailyReportServiceTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```
git add app/Services/DailyReportService.php tests/Unit/DailyReportServiceTest.php
git commit -m "feat(report): DailyReportService (recap, monthly, submissions)

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 3: Controller + routes + endpoint tests (TDD)

**Files:**
- Create: `app/Http/Controllers/Pages/DailyReportController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/DailyReportControllerTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/DailyReportControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Task;
use App\Models\DailyReport;
use App\Services\GoogleDriveService;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class DailyReportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class); // default; sebagian test override
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    /** @test */
    public function save_note_creates_report(): void
    {
        $u = $this->user('production');
        $this->actingAs($u)->post(route('report.note'), ['date' => today()->toDateString(), 'note' => 'Kerja hari ini'])->assertRedirect();
        $this->assertDatabaseHas('tb_daily_reports', ['user_id' => $u->id, 'note' => 'Kerja hari ini', 'status' => 'draft']);
    }

    /** @test */
    public function submit_locks_report(): void
    {
        $u = $this->user('production');
        $this->actingAs($u)->post(route('report.submit'), ['date' => today()->toDateString()])->assertRedirect();
        $this->assertDatabaseHas('tb_daily_reports', ['user_id' => $u->id, 'status' => 'submitted']);

        $this->actingAs($u)->post(route('report.note'), ['date' => today()->toDateString(), 'note' => 'x'])->assertStatus(422);
    }

    /** @test */
    public function upload_file_stores_record(): void
    {
        $u = $this->user('production');
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('getOrCreateFolderByPath')->andReturn('folder-1');
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'drive-1', 'name' => 'foto.jpg', 'url' => 'https://drive/foto']);
        });

        $this->actingAs($u)->post(route('report.files.store'), [
            'date' => today()->toDateString(),
            'file' => UploadedFile::fake()->image('foto.jpg'),
        ])->assertOk()->assertJsonFragment(['name' => 'foto.jpg']);

        $this->assertDatabaseHas('tb_daily_report_files', ['drive_file_id' => 'drive-1', 'name' => 'foto.jpg']);
    }

    /** @test */
    public function cannot_upload_after_submit(): void
    {
        $u = $this->user('production');
        DailyReport::create(['user_id' => $u->id, 'report_date' => today()->toDateString(), 'status' => 'submitted', 'submitted_at' => now()]);

        $this->actingAs($u)->post(route('report.files.store'), [
            'date' => today()->toDateString(),
            'file' => UploadedFile::fake()->image('x.jpg'),
        ])->assertStatus(422);
    }

    /** @test */
    public function delete_file_removes_record(): void
    {
        $u = $this->user('production');
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('deleteFile')->andReturn(true);
        });
        $report = DailyReport::create(['user_id' => $u->id, 'report_date' => today()->toDateString()]);
        $file = $report->files()->create(['drive_file_id' => 'd1', 'name' => 'a.jpg', 'url' => 'u']);

        $this->actingAs($u)->delete(route('report.files.destroy', $file->id))->assertOk();
        $this->assertDatabaseMissing('tb_daily_report_files', ['id' => $file->id]);
    }

    /** @test */
    public function cannot_delete_others_file(): void
    {
        $a = $this->user('production');
        $b = $this->user('production');
        $report = DailyReport::create(['user_id' => $b->id, 'report_date' => today()->toDateString()]);
        $file = $report->files()->create(['drive_file_id' => 'd1', 'name' => 'a.jpg', 'url' => 'u']);

        $this->actingAs($a)->delete(route('report.files.destroy', $file->id))->assertForbidden();
    }

    /** @test */
    public function non_manager_cannot_open_submissions(): void
    {
        $this->actingAs($this->user('production'))->get(route('report.submissions'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=DailyReportControllerTest`
Expected: FAIL — route `report.note` not defined.

- [ ] **Step 3: Controller**

Create `app/Http/Controllers/Pages/DailyReportController.php`:

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\DailyReport;
use App\Models\DailyReportFile;
use App\Models\User;
use App\Services\DailyReportService;
use App\Services\GoogleDriveService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class DailyReportController extends Controller
{
    public function __construct(private DailyReportService $service, private GoogleDriveService $drive) {}

    private function isManager(): bool
    {
        return Auth::user()->hasAnyRole(['manager', 'superadmin']);
    }

    /** User yang report-nya dilihat (manager boleh ?user_id=); selain itu diri sendiri. */
    private function viewedUser(Request $request): User
    {
        if ($this->isManager() && $request->filled('user_id')) {
            return User::findOrFail((int) $request->input('user_id'));
        }
        return Auth::user();
    }

    private function dateParam(Request $request): Carbon
    {
        return $request->filled('date') ? Carbon::parse($request->input('date')) : Carbon::today();
    }

    public function daily(Request $request)
    {
        $user = $this->viewedUser($request);
        $date = $this->dateParam($request);
        $report = DailyReport::with('files')->where('user_id', $user->id)->whereDate('report_date', $date)->first();

        return view('reports.daily', [
            'recap'     => $this->service->recapFor($user, $date),
            'date'      => $date,
            'owner'     => $user,
            'report'    => $report,
            'files'     => $report?->files ?? collect(),
            'isOwner'   => $user->id === Auth::id(),
            'submitted' => $report?->isSubmitted() ?? false,
        ]);
    }

    public function saveNote(Request $request)
    {
        $data = $request->validate(['date' => 'required|date', 'note' => 'nullable|string']);
        $report = $this->service->getOrCreateReport(Auth::user(), Carbon::parse($data['date']));
        abort_if($report->isSubmitted(), 422, 'Report sudah dikirim.');
        $report->update(['note' => $data['note'] ?? null]);

        return back()->with('success', 'Catatan disimpan.');
    }

    public function submit(Request $request)
    {
        $data = $request->validate(['date' => 'required|date']);
        $report = $this->service->getOrCreateReport(Auth::user(), Carbon::parse($data['date']));
        if (! $report->isSubmitted()) {
            $report->update(['status' => 'submitted', 'submitted_at' => now()]);
        }

        return back()->with('success', 'Report dikirim.');
    }

    public function storeFile(Request $request)
    {
        $data = $request->validate([
            'date' => 'required|date',
            'file' => 'required|file|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx|max:10240',
        ]);

        $report = $this->service->getOrCreateReport(Auth::user(), Carbon::parse($data['date']));
        if ($report->isSubmitted()) {
            return response()->json(['message' => 'Report sudah dikirim.'], 422);
        }

        $folderId = $this->drive->getOrCreateFolderByPath('SiMAPA/Reports/' . Auth::id() . '/' . Carbon::parse($data['date'])->format('Y-m'));
        $uploaded = $this->drive->uploadFile($request->file('file'), $folderId, true);
        if (! $uploaded) {
            return response()->json(['message' => 'Gagal mengunggah ke Google Drive.'], 500);
        }

        $file = $report->files()->create([
            'drive_file_id' => $uploaded['id'],
            'name'          => $uploaded['name'] ?? $request->file('file')->getClientOriginalName(),
            'url'           => $uploaded['url'] ?? '',
            'mime'          => $request->file('file')->getClientMimeType(),
            'size'          => $request->file('file')->getSize(),
            'uploaded_by'   => Auth::id(),
        ]);

        return response()->json(['id' => $file->id, 'name' => $file->name, 'url' => $file->url]);
    }

    public function destroyFile(int $id)
    {
        $file = DailyReportFile::with('report')->findOrFail($id);
        abort_if($file->report->user_id !== Auth::id(), 403);
        abort_if($file->report->isSubmitted(), 422, 'Report sudah dikirim.');

        $this->drive->deleteFile($file->drive_file_id);
        $file->delete();

        return response()->json(['ok' => true]);
    }

    public function monthly(Request $request)
    {
        $user = $this->viewedUser($request);
        $month = $request->filled('month') ? Carbon::parse($request->input('month') . '-01') : Carbon::today()->startOfMonth();

        return view('reports.monthly', [
            'recap' => $this->service->monthlyRecap($user, (int) $month->year, (int) $month->month),
            'month' => $month,
            'owner' => $user,
        ]);
    }

    public function submissions(Request $request)
    {
        $date = $this->dateParam($request);

        return view('reports.submissions', [
            'rows' => $this->service->submissionsForDate($date),
            'date' => $date,
        ]);
    }
}
```

- [ ] **Step 4: Routes**

In `routes/web.php`, add the import near the other `use App\Http\Controllers\Pages\...;` lines:

```php
use App\Http\Controllers\Pages\DailyReportController;
```

Inside the `Route::middleware('auth')->group(function () {` block, add (static routes; `submissions` gated to manager/superadmin):

```php
    Route::get('reports/daily', [DailyReportController::class, 'daily'])->name('report.daily');
    Route::post('reports/daily/note', [DailyReportController::class, 'saveNote'])->name('report.note');
    Route::post('reports/daily/submit', [DailyReportController::class, 'submit'])->name('report.submit');
    Route::post('reports/daily/files', [DailyReportController::class, 'storeFile'])->name('report.files.store');
    Route::delete('reports/daily/files/{id}', [DailyReportController::class, 'destroyFile'])->name('report.files.destroy');
    Route::get('reports/monthly', [DailyReportController::class, 'monthly'])->name('report.monthly');

    Route::middleware('role:manager|superadmin')->group(function () {
        Route::get('reports/submissions', [DailyReportController::class, 'submissions'])->name('report.submissions');
    });
```

- [ ] **Step 5: Run, confirm PASS**

Run: `php artisan test --filter=DailyReportControllerTest`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```
git add app/Http/Controllers/Pages/DailyReportController.php routes/web.php tests/Feature/DailyReportControllerTest.php
git commit -m "feat(report): controller + routes (note/submit/file upload via Drive)

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 4: Views + Dropzone + sidebar + page smoke tests (TDD)

**Files:**
- Create: `resources/views/reports/daily.blade.php`, `monthly.blade.php`, `submissions.blade.php`
- Modify: `resources/views/layouts/sidebar.blade.php`
- Test: `tests/Feature/DailyReportPagesTest.php`

- [ ] **Step 1: Write the failing page test**

Create `tests/Feature/DailyReportPagesTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Task;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class DailyReportPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    /** @test */
    public function owner_can_open_daily_report(): void
    {
        $u = $this->user('production');
        Task::create(['user_id' => $u->id, 'title' => 'TugasSelesai', 'status' => 'done', 'priority' => 'normal', 'completed_at' => now()]);

        $this->actingAs($u)->get(route('report.daily'))->assertOk()->assertSee('TugasSelesai');
    }

    /** @test */
    public function manager_can_open_monthly_and_submissions(): void
    {
        $manager = $this->user('manager');
        $emp = $this->user('production');

        $this->actingAs($manager)->get(route('report.monthly'))->assertOk();
        $this->actingAs($manager)->get(route('report.submissions'))->assertOk()->assertSee($emp->name);
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=DailyReportPagesTest`
Expected: FAIL — `View [reports.daily] not found`.

- [ ] **Step 3: Daily report view**

Create `resources/views/reports/daily.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Report Harian - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/plugins/flatpickr/flatpickr.min.css') }}" rel="stylesheet" />
<link href="{{ asset('assets/plugins/dropzone/dropzone.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php
    $prioBadge = ['high' => 'bg-danger', 'normal' => 'bg-secondary', 'low' => 'bg-info'];
    $prioLabel = ['high' => 'Tinggi', 'normal' => 'Normal', 'low' => 'Rendah'];
    $cards = [
        'selesai'    => ['Selesai hari ini', 'check-circle', 'text-success'],
        'dibuat'     => ['Ditugaskan / dibuat', 'plus-circle', 'text-primary'],
        'dikerjakan' => ['Sedang dikerjakan', 'loader', 'text-warning'],
    ];
@endphp

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">Report Harian</h5>
        <small class="text-muted">{{ $owner->name }}{{ $isOwner ? ' (saya)' : '' }} · {{ $date->translatedFormat('l, d M Y') }}</small>
    </div>
    <form method="GET" class="d-flex gap-1 align-items-center">
        @if(! $isOwner)<input type="hidden" name="user_id" value="{{ $owner->id }}">@endif
        <a href="{{ route('report.daily', array_filter(['user_id' => $isOwner ? null : $owner->id, 'date' => $date->copy()->subDay()->toDateString()])) }}" class="btn btn-sm btn-outline-secondary">&laquo;</a>
        <input type="text" name="date" id="reportDate" value="{{ $date->toDateString() }}" class="form-control form-control-sm" style="width:140px">
        <a href="{{ route('report.daily', array_filter(['user_id' => $isOwner ? null : $owner->id, 'date' => $date->copy()->addDay()->toDateString()])) }}" class="btn btn-sm btn-outline-secondary">&raquo;</a>
        <button class="btn btn-sm btn-primary">Lihat</button>
    </form>
</div>

<div class="row g-3 mb-3">
    @foreach($cards as $key => [$label, $icon, $color])
        <div class="col-md-4">
            <div class="card h-100"><div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i data-feather="{{ $icon }}" class="icon-sm {{ $color }}"></i>
                    <strong>{{ $label }}</strong>
                    <span class="badge bg-light text-muted">{{ $recap['counts'][$key] }}</span>
                </div>
                @forelse($recap[$key] as $task)
                    <div class="d-flex justify-content-between align-items-center border-bottom py-1">
                        <span style="font-size:13px">{{ $task->title }}</span>
                        <span class="badge {{ $prioBadge[$task->priority] }}">{{ $prioLabel[$task->priority] }}</span>
                    </div>
                @empty
                    <div class="text-muted text-center py-2" style="font-size:12px">{{ $key === 'dikerjakan' && ! $date->isToday() ? 'Hanya untuk hari ini' : 'Tidak ada' }}</div>
                @endforelse
            </div></div>
        </div>
    @endforeach
</div>

<div class="row g-3">
    <div class="col-md-7">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Catatan Harian</h6>
            <form method="POST" action="{{ route('report.note') }}">
                @csrf
                <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                <textarea name="note" class="form-control" rows="5" placeholder="Catatan tambahan (mis. meeting, kendala)..." {{ ($submitted || ! $isOwner) ? 'disabled' : '' }}>{{ $report?->note }}</textarea>
                @if($isOwner && ! $submitted)
                    <div class="mt-2 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Simpan Catatan</button>
                    </div>
                @endif
            </form>
            @if($isOwner)
                <hr>
                @if($submitted)
                    <span class="badge bg-success">Terkirim {{ optional($report->submitted_at)->translatedFormat('d M Y H:i') }}</span>
                @else
                    <form method="POST" action="{{ route('report.submit') }}" onsubmit="return confirm('Kirim report hari ini? Setelah dikirim tidak bisa diubah.')">
                        @csrf
                        <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                        <button type="submit" class="btn btn-sm btn-success">Kirim Report Hari Ini</button>
                    </form>
                @endif
            @elseif($submitted)
                <hr><span class="badge bg-success">Sudah dikirim</span>
            @endif
        </div></div>
    </div>
    <div class="col-md-5">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Lampiran</h6>
            @if($isOwner && ! $submitted)
                <div id="reportDropzone" class="dropzone mb-2" style="min-height:120px"></div>
            @endif
            <ul id="savedFiles" class="list-group list-group-flush">
                @foreach($files as $f)
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0" data-file="{{ $f->id }}">
                        <a href="{{ $f->url }}" target="_blank" style="font-size:13px"><i data-feather="paperclip" class="icon-xs me-1"></i>{{ $f->name }}</a>
                        @if($isOwner && ! $submitted)<button class="btn btn-xs btn-outline-danger" data-del-file="{{ $f->id }}">Hapus</button>@endif
                    </li>
                @endforeach
            </ul>
        </div></div>
    </div>
</div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/plugins/flatpickr/flatpickr.min.js') }}"></script>
<script src="{{ asset('assets/plugins/dropzone/dropzone.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
$(function () {
    var token = document.querySelector('meta[name="_token"]').getAttribute('content');
    if (window.flatpickr) flatpickr('#reportDate', { dateFormat: 'Y-m-d' });

    var saved = document.getElementById('savedFiles');
    function appendSaved(f) {
        var li = document.createElement('li');
        li.className = 'list-group-item d-flex justify-content-between align-items-center px-0';
        li.setAttribute('data-file', f.id);
        li.innerHTML = '<a href="' + f.url + '" target="_blank" style="font-size:13px">' + f.name + '</a>'
            + '<button class="btn btn-xs btn-outline-danger" data-del-file="' + f.id + '">Hapus</button>';
        saved.prepend(li);
    }
    function delFile(id, el) {
        fetch("{{ url('reports/daily/files') }}/" + id, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (r.ok && el) el.remove(); });
    }
    if (saved) saved.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-del-file]');
        if (btn) { e.preventDefault(); delFile(btn.getAttribute('data-del-file'), btn.closest('[data-file]')); }
    });

    var dzEl = document.getElementById('reportDropzone');
    if (dzEl && window.Dropzone) {
        Dropzone.autoDiscover = false;
        new Dropzone(dzEl, {
            url: "{{ route('report.files.store') }}",
            maxFiles: 10, maxFilesize: 10,
            acceptedFiles: "image/*,.pdf,.doc,.docx,.xls,.xlsx",
            addRemoveLinks: true,
            resizeWidth: 1600, resizeQuality: 0.8,
            params: { date: "{{ $date->toDateString() }}" },
            headers: { 'X-CSRF-TOKEN': token },
            dictDefaultMessage: 'Tarik &amp; lepas file ke sini atau klik untuk pilih',
            dictRemoveFile: 'Batal',
            init: function () {
                this.on('success', function (file, resp) { appendSaved(resp); this.removeFile(file); });
                this.on('error', function (file, msg) {
                    var m = (msg && msg.message) ? msg.message : 'Gagal mengunggah.';
                    var el = file.previewElement && file.previewElement.querySelector('[data-dz-errormessage]');
                    if (el) el.textContent = m;
                });
            }
        });
    }
    if (window.feather) feather.replace();
});
</script>
@endpush
```

- [ ] **Step 4: Monthly report view**

Create `resources/views/reports/monthly.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Report Bulanan - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">Report Bulanan</h5>
        <small class="text-muted">{{ $owner->name }} · {{ $month->translatedFormat('F Y') }}</small>
    </div>
    <form method="GET" class="d-flex gap-1">
        @if($owner->id !== auth()->id())<input type="hidden" name="user_id" value="{{ $owner->id }}">@endif
        <input type="month" name="month" value="{{ $month->format('Y-m') }}" class="form-control form-control-sm" style="width:160px">
        <button class="btn btn-sm btn-primary">Lihat</button>
    </form>
</div>

<div class="row g-2 mb-3">
    @php
        $kpis = [
            ['Selesai', $recap['selesai'], ''],
            ['Tepat waktu', $recap['tepat_waktu'], 'text-success'],
            ['Telat', $recap['telat'], 'text-danger'],
            ['On-time %', $recap['on_time_rate'] === null ? '—' : $recap['on_time_rate'] . '%', ''],
            ['Hari dilaporkan', $recap['dilaporkan'], ''],
        ];
    @endphp
    @foreach($kpis as [$lbl, $val, $cls])
        <div class="col"><div class="card"><div class="card-body py-2 text-center">
            <div class="text-muted" style="font-size:11px">{{ $lbl }}</div>
            <div class="fw-bold {{ $cls }}" style="font-size:20px">{{ $val }}</div>
        </div></div></div>
    @endforeach
</div>

<div class="card"><div class="card-body">
    <div class="table-responsive">
        <table class="table table-hover datatable" style="width:100%">
            <thead><tr><th>Tanggal</th><th>Selesai</th><th>Status</th><th>Catatan</th><th></th></tr></thead>
            <tbody>
                @php
                    $start = $month->copy()->startOfMonth();
                    $days = $month->copy()->endOfMonth()->day;
                @endphp
                @for($d = 1; $d <= $days; $d++)
                    @php
                        $cur = $start->copy()->day($d);
                        $key = $cur->toDateString();
                        $rep = $recap['reports']->get($key);
                        $selesai = $recap['per_hari']->get($key, 0);
                    @endphp
                    @if($selesai > 0 || $rep)
                        <tr>
                            <td>{{ $cur->translatedFormat('d M (D)') }}</td>
                            <td>{{ $selesai }}</td>
                            <td>@if($rep && $rep->isSubmitted())<span class="badge bg-success">Terkirim</span>@elseif($rep)<span class="badge bg-secondary">Draf</span>@else<span class="text-muted">—</span>@endif</td>
                            <td><small>{{ \Illuminate\Support\Str::limit($rep->note ?? '', 60) }}</small></td>
                            <td><a href="{{ route('report.daily', array_filter(['user_id' => $owner->id !== auth()->id() ? $owner->id : null, 'date' => $key])) }}" class="btn btn-xs btn-outline-primary">Buka</a></td>
                        </tr>
                    @endif
                @endfor
            </tbody>
        </table>
    </div>
</div></div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>$(function () { $('.datatable').DataTable({ pageLength: 31, order: [], language: { emptyTable: 'Belum ada aktivitas bulan ini.' } }); });</script>
@endpush
```

- [ ] **Step 5: Submissions (Pemantauan) view**

Create `resources/views/reports/submissions.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Pemantauan Report - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php $sudah = $rows->where('submitted', true)->count(); $belum = $rows->count() - $sudah; @endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">Pemantauan Report</h5>
        <small class="text-muted">{{ $date->translatedFormat('l, d M Y') }}</small>
    </div>
    <form method="GET" class="d-flex gap-1 align-items-center">
        <input type="text" name="date" id="subDate" value="{{ $date->toDateString() }}" class="form-control form-control-sm" style="width:140px">
        <button class="btn btn-sm btn-primary">Lihat</button>
    </form>
</div>

<div class="row g-2 mb-3">
    <div class="col"><div class="card"><div class="card-body py-2 text-center"><div class="text-muted" style="font-size:11px">Sudah kirim</div><div class="fw-bold text-success" style="font-size:20px">{{ $sudah }}</div></div></div></div>
    <div class="col"><div class="card"><div class="card-body py-2 text-center"><div class="text-muted" style="font-size:11px">Belum kirim</div><div class="fw-bold text-danger" style="font-size:20px">{{ $belum }}</div></div></div></div>
</div>

<div class="card"><div class="card-body">
    <div class="table-responsive">
        <table class="table table-hover datatable" style="width:100%">
            <thead><tr><th>Karyawan</th><th>Status</th><th>Selesai</th><th></th></tr></thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td>{{ $row['name'] }}</td>
                        <td>@if($row['submitted'])<span class="badge bg-success">Sudah kirim</span>@else<span class="badge bg-secondary">Belum</span>@endif</td>
                        <td>{{ $row['selesai'] }}</td>
                        <td><a href="{{ route('report.daily', ['user_id' => $row['id'], 'date' => $date->toDateString()]) }}" class="btn btn-xs btn-outline-primary">Buka report</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/plugins/flatpickr/flatpickr.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('plugin-styles')
<link href="{{ asset('assets/plugins/flatpickr/flatpickr.min.css') }}" rel="stylesheet" />
@endpush
@push('custom-scripts')
<script>$(function () { if (window.flatpickr) flatpickr('#subDate', { dateFormat: 'Y-m-d' }); $('.datatable').DataTable({ pageLength: 15, order: [], language: { emptyTable: 'Belum ada karyawan.' } }); });</script>
@endpush
```

- [ ] **Step 6: Sidebar menu**

In `resources/views/layouts/sidebar.blade.php`, find the `<li class="nav-item nav-category">Akun</li>` line. Insert this block immediately BEFORE it:

```blade
            <li class="nav-item nav-category">Report</li>
            <li class="nav-item {{ active_class(['reports/daily']) }}">
                <a href="{{ route('report.daily') }}" class="nav-link">
                    <i class="link-icon" data-feather="file-text"></i>
                    <span class="link-title">Report Harian</span>
                </a>
            </li>
            <li class="nav-item {{ active_class(['reports/monthly']) }}">
                <a href="{{ route('report.monthly') }}" class="nav-link">
                    <i class="link-icon" data-feather="calendar"></i>
                    <span class="link-title">Report Bulanan</span>
                </a>
            </li>
            @role(['manager', 'superadmin'])
                <li class="nav-item {{ active_class(['reports/submissions']) }}">
                    <a href="{{ route('report.submissions') }}" class="nav-link">
                        <i class="link-icon" data-feather="clipboard"></i>
                        <span class="link-title">Pemantauan Report</span>
                    </a>
                </li>
            @endrole
```

- [ ] **Step 7: Run, confirm PASS**

Run: `php artisan test --filter=DailyReportPagesTest`
Expected: PASS (2 tests).

- [ ] **Step 8: Commit**

```
git add resources/views/reports/daily.blade.php resources/views/reports/monthly.blade.php resources/views/reports/submissions.blade.php resources/views/layouts/sidebar.blade.php tests/Feature/DailyReportPagesTest.php
git commit -m "feat(report): daily/monthly/submissions views + Dropzone + sidebar

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 5: Verifikasi penuh

**Files:** none (verification only)

- [ ] **Step 1: Jalankan seluruh suite**

Run: `php artisan test`
Expected: PASS semua (247 sebelumnya + DailyReportServiceTest 5 + DailyReportControllerTest 7 + DailyReportPagesTest 2 = 261). Tidak ada yang merah.

- [ ] **Step 2: Smoke manual (opsional)**

Login `pia` (production) → menu **Report Harian**: lihat rekap otomatis (tugas selesai/dibuat hari ini), tulis catatan, **drag-drop file** (lihat progress bar + gambar terkompres), file tersimpan ke Google Drive, **Kirim Report** (terkunci). Buka **Report Bulanan** (KPI + tabel per hari). Login `manager` → **Pemantauan Report**: lihat siapa sudah/belum kirim, buka report karyawan.

---

## Catatan & Risiko

- **Dev/prod: `php artisan migrate`** untuk `tb_daily_reports` + `tb_daily_report_files`. Lihat [[migrate-dev-db-after-new-migration]].
- Rekap **live** dari `tb_tasks` (anchor `report_date`); angka historis stabil. `dikerjakan` hanya untuk hari ini (status tak dilog) — sengaja.
- Upload pakai `GoogleDriveService` + Dropzone (kompres gambar `resizeWidth/Quality`, progress bar bawaan). Folder via `getOrCreateFolderByPath('SiMAPA/Reports/{userId}/{Y-m}')`. Kegagalan Drive → JSON 500 (file tak tercatat).
- Mutasi (catatan/submit/upload/hapus) selalu untuk `Auth::user()`; manager `?user_id=` hanya untuk **melihat**. Terkunci saat `submitted`.
- Test memock `GoogleDriveService` (tak menyentuh Drive asli) — konsisten dgn test lain.
