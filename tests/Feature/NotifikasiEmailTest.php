<?php

namespace Tests\Feature;

use App\Models\ManuscriptFile;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Notifications\DatabaseNotification;
use App\Services\GoogleDriveService;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Email hanya untuk peristiwa yang MENUNTUT PERBUATAN penerimanya.
 *
 * Sampai 2026-08-23 DatabaseNotification::via() mengembalikan ['database'] saja — tak
 * satu pun peristiwa naskah atau tugas pernah mengirim email. Sekarang bisa, tapi
 * digerbangi per peristiwa: satu naskah normal melewati tujuh tahap, dan tujuh email
 * per naskah membuat orang menyaring habis seluruhnya — termasuk yang mendesak.
 */
class NotifikasiEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class)->shouldIgnoreMissing();
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role, array $extra = []): User
    {
        $u = User::factory()->create($extra);
        $u->assignRole($role);

        return $u;
    }

    private function naskah(string $status = 'pembuatan'): TitleProgress
    {
        $title = Title::create([
            'title' => 'Naskah Email ' . fake()->unique()->words(2, true),
            'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
            'link_terbit' => 'https://uji.test/' . fake()->unique()->slug(),
        ]);
        $detail = OrderDetail::factory()->create([
            'order_id' => Order::factory()->create()->id, 'type' => 'at_mandiri',
            'title' => $title->title, 'title_id' => $title->id,
        ]);

        return TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => $status,
            'assigned_role' => TitleProgress::getHandlerForStatus($status),
            'started_at' => now(),
        ]);
    }

    /** Saluran yang dipakai notifikasi untuk satu penerima. */
    private function saluran(User $penerima): array
    {
        $dikirim = [];
        Notification::assertSentTo($penerima, DatabaseNotification::class,
            function ($n, $channels) use (&$dikirim) {
                $dikirim = $n->via($channels[0] ? $channels[0] : null) ?: [];

                return true;
            });

        return $dikirim;
    }

    // ─── yang HARUS berkirim email ───

    /** @test */
    public function tugas_naskah_ke_pelaksana_berkirim_email(): void
    {
        Notification::fake();

        $budi  = $this->user('production');
        $rina  = $this->user('admin');
        $p     = $this->naskah();

        app(Notifier::class)->naskahDistribusi($p, $budi, $rina);

        Notification::assertSentTo($budi, DatabaseNotification::class,
            fn ($n) => in_array('mail', $n->via($budi), true));
    }

    /** @test */
    public function permintaan_revisi_berkirim_email(): void
    {
        Notification::fake();

        $budi = $this->user('production');
        $rina = $this->user('admin');
        $p    = $this->naskah('revisi');

        $putaran = \App\Models\ManuscriptRevision::create([
            'title_id' => $p->orderDetail->title_id, 'round' => 1,
            'stage' => 'revisi', 'from_stage' => 'submit',
            'requested_by' => $rina->id, 'assigned_to' => $budi->id,
            'request_note' => 'Metodologi diperjelas',
        ]);

        app(Notifier::class)->naskahRevisiDiminta($putaran, $rina);

        Notification::assertSentTo($budi, DatabaseNotification::class,
            fn ($n) => in_array('mail', $n->via($budi), true));
    }

    /** @test */
    public function unggahan_gagal_berkirim_email(): void
    {
        Notification::fake();

        $budi = $this->user('production');
        $p    = $this->naskah();

        $berkas = ManuscriptFile::create([
            'title_id' => $p->orderDetail->title_id, 'slot' => 'masuk',
            'status' => 'gagal', 'version' => 1,
            'original_name' => 'gagal.docx', 'uploaded_by' => $budi->id,
        ]);

        app(Notifier::class)->unggahanGagal($berkas, 'Drive menolak');

        Notification::assertSentTo($budi, DatabaseNotification::class,
            fn ($n) => in_array('mail', $n->via($budi), true));
    }

    /** @test */
    public function tugas_papan_dan_tenggangnya_berkirim_email(): void
    {
        Notification::fake();

        $budi = $this->user('production');
        $rina = $this->user('admin');
        $task = Task::create([
            'user_id' => $budi->id, 'title' => 'Rapikan berkas',
            'status' => 'todo', 'due_date' => now()->addDay(),
        ]);

        app(Notifier::class)->taskAssigned($task, $rina);
        app(Notifier::class)->deadlineReminder($task);

        Notification::assertSentToTimes($budi, DatabaseNotification::class, 2);
        Notification::assertSentTo($budi, DatabaseNotification::class,
            fn ($n) => in_array('mail', $n->via($budi), true));
    }

    /**
     * Naskah yang DIKEMBALIKAN menuntut seseorang mengerjakannya ulang — itu email.
     *
     * @test
     */
    public function naskah_dikembalikan_berkirim_email(): void
    {
        Notification::fake();

        $pj   = $this->user('admin');
        $sa   = $this->user('superadmin');
        $p    = $this->naskah('editing');
        $p->update(['pj_user_id' => $pj->id]);

        app(Notifier::class)->naskahTahapBerubah($p->fresh(), $sa, 'editing', 'pembuatan');

        Notification::assertSentTo($pj, DatabaseNotification::class,
            fn ($n) => in_array('mail', $n->via($pj), true));
    }

    // ─── yang TIDAK boleh berkirim email ───

    /**
     * Satu naskah normal melewati tujuh tahap. Tujuh email per naskah membuat orang
     * menyaring habis seluruhnya, termasuk yang benar-benar mendesak.
     *
     * @test
     */
    public function naskah_yang_maju_cukup_jadi_lonceng(): void
    {
        Notification::fake();

        $pj = $this->user('admin');
        $sa = $this->user('superadmin');
        $p  = $this->naskah('editing');
        $p->update(['pj_user_id' => $pj->id]);

        app(Notifier::class)->naskahTahapBerubah($p->fresh(), $sa, 'editing', 'submit');

        Notification::assertSentTo($pj, DatabaseNotification::class,
            fn ($n) => ! in_array('mail', $n->via($pj), true));
    }

    /** @test */
    public function naskah_terbit_cukup_jadi_lonceng(): void
    {
        Notification::fake();

        $marketing = $this->user('marketing');
        $p = $this->naskah('publish');
        $p->orderDetail->order->update(['user_id' => $marketing->id]);

        app(Notifier::class)->naskahPublished($p->fresh(), $this->user('superadmin'));

        Notification::assertSentTo($marketing, DatabaseNotification::class,
            fn ($n) => ! in_array('mail', $n->via($marketing), true));
    }

    // ─── pagar ───

    /**
     * Penerima tanpa alamat email tak boleh menggagalkan notifikasinya — lonceng tetap
     * harus sampai.
     *
     * `users.email` di skema ini NOT NULL, jadi keadaan itu tak bisa dibuat lewat basis
     * data; gerbangnya diuji langsung pada objeknya. Ia memang pengaman lapis kedua —
     * dan lapis kedua yang tak diuji sama saja dengan tak ada.
     *
     * @test
     */
    public function penerima_tanpa_email_tetap_dapat_lonceng(): void
    {
        $notif = new DatabaseNotification([
            'category' => 'naskah', 'title' => 'Uji', 'message' => 'Uji',
            'url' => '/', 'icon' => 'bell', 'email' => true,
        ]);

        foreach ([null, '', '   '] as $kosong) {
            $penerima = new User(['name' => 'Tanpa Email']);
            $penerima->email = $kosong;

            $this->assertSame(['database'], $notif->via($penerima),
                'Email kosong hanya mematikan salurannya, bukan seluruh notifikasinya.');
        }
    }

    /** @test */
    public function penanda_email_tak_ikut_tersimpan_di_lonceng(): void
    {
        $budi = $this->user('production');

        $notif = new DatabaseNotification([
            'category' => 'naskah', 'title' => 'Uji', 'message' => 'Pesan',
            'url' => '/', 'icon' => 'bell', 'email' => true, 'aksi' => 'Buka',
        ]);

        $data = $notif->toArray($budi);

        $this->assertArrayNotHasKey('email', $data, 'Penanda pengiriman bukan isi notifikasi.');
        $this->assertArrayNotHasKey('aksi', $data);
        $this->assertSame('Pesan', $data['message']);
    }

    /**
     * Pengiriman email menunggu SMTP — tak seorang pun boleh menunggu itu di dalam
     * request. Antreannya sudah jalan lewat schedule:run di produksi.
     *
     * @test
     */
    public function notifikasi_diantrekan_bukan_dikirim_di_dalam_request(): void
    {
        $this->assertInstanceOf(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            new DatabaseNotification([
                'category' => 'naskah', 'title' => 'x', 'message' => 'x',
                'url' => '/', 'icon' => 'bell',
            ])
        );
    }
}
