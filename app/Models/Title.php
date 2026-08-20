<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Title extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'tb_titles';

    public const JENIS = ['artikel', 'buku'];
    public const TIPE = ['mandiri', 'kolaborasi'];
    public const STATUSES = ['draft', 'menunggu', 'disetujui', 'ditolak'];
    public const INDEKSASI = [
        'none', 'SINTA 1', 'SINTA 2', 'SINTA 3', 'SINTA 4', 'SINTA 5', 'SINTA 6',
        'Scopus Q1', 'Scopus Q2', 'Scopus Q3', 'Scopus Q4', 'Copernicus', 'WoS', 'DOAJ', 'Garuda',
    ];

    protected $fillable = [
        'title', 'code', 'jenis', 'indeksasi', 'tipe_naskah', 'scope_id', 'assigned_to', 'status', 'asal', 'slug',
        'created_by', 'approved_by', 'approved_at', 'reject_note',
        'target_terbit', 'jurnal_target', 'jurnal_link', 'template_link', 'apc_info', 'catatan_publikasi',
        'deactivated_at', 'deactivated_by',
    ];

    protected $casts = ['approved_at' => 'datetime', 'target_terbit' => 'date', 'deactivated_at' => 'datetime'];

    public function chapters()
    {
        return $this->hasMany(TitleChapter::class)->orderBy('urutan');
    }

    public function scope()
    {
        return $this->belongsTo(Scope::class);
    }

    public function assignedMarketing()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function orderDetails()
    {
        return $this->hasMany(OrderDetail::class, 'title_id');
    }

    public function journalOptions()
    {
        return $this->hasMany(TitleJournalOption::class)->orderBy('urutan');
    }

    public function logs()
    {
        return $this->hasMany(TitleLog::class)->latest('created_at');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function deactivatedBy()
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    /** Judul nonaktif tetap ada di laporan/papan/arsip, tapi hilang dari dropdown order. */
    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    public function scopeActive($query)
    {
        return $query->whereNull('deactivated_at');
    }

    /**
     * Alasan judul TIDAK boleh dihapus, atau null bila boleh.
     *
     * "Order aktif" dihitung tanpa withTrashed() — disengaja: judul yang jadi yatim
     * karena order-nya dibatalkan memang seharusnya bisa dibersihkan. Karena hapusnya
     * soft, tb_order_details.title_id yang ber-nullOnDelete tidak ikut dikosongkan,
     * jadi riwayat tetap tertaut.
     *
     * Memakai hasil agregasi bila query pemanggil sudah menyediakannya (withCount +
     * withExists di TitleController::index()). Direktori memanggil metode ini sekali
     * per baris, dan tanpa itu tiga query per judul menumpuk jadi ratusan.
     */
    public function deleteBlockReason(): ?string
    {
        $orders = $this->orders_count ?? $this->orderDetails()->count();
        if ($orders > 0) {
            return 'Dipakai ' . $orders . ' order';
        }

        if ($this->book_isbn_exists ?? $this->bookIsbn()->exists()) {
            return 'Sudah punya ISBN';
        }

        if ($this->archive_exists ?? $this->archive()->exists()) {
            return 'Sudah diarsipkan';
        }

        return null;
    }

    public function isDeletable(): bool
    {
        return $this->deleteBlockReason() === null;
    }

    /**
     * Alasan judul TIDAK boleh diajukan, atau null bila boleh.
     *
     * Cerminan deleteBlockReason(), dipakai untuk MENONAKTIFKAN tombol Ajukan alih-alih
     * menyembunyikannya, supaya user tahu kenapa. Tanpa penjaga ini halaman detail masih
     * menampilkan Ajukan untuk judul disetujui (isEditable() sengaja memuat 'disetujui'),
     * lalu TitleService::submit() diam-diam tidak mengubah apa pun tapi tetap dijawab
     * "Judul diajukan." — pesan sukses palsu.
     */
    public function submitBlockReason(): ?string
    {
        if ($this->status === 'menunggu') {
            return 'Sudah diajukan, menunggu persetujuan';
        }

        if ($this->isApproved()) {
            return 'Sudah disetujui';
        }

        $orders = $this->orders_count ?? $this->orderDetails()->count();
        if ($orders > 0) {
            return 'Sudah dipakai ' . $orders . ' order';
        }

        return null;
    }

    public function isSubmittable(): bool
    {
        return $this->submitBlockReason() === null;
    }

    /**
     * Judul disetujui SENGAJA ikut editable: hampir semua judul lahir dari order dan
     * langsung berstatus 'disetujui' (TitleService::resolveForOrder), jadi aturan lama
     * mengunci setiap salah ketik selamanya. Status TIDAK turun ke 'menunggu' setelah
     * diedit; siapa yang boleh mengedit judul disetujui dijaga TitleController.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'ditolak', 'disetujui'], true);
    }

    public function isApproved(): bool
    {
        return $this->status === 'disetujui';
    }

    /** Tahap manuskrip judul = bottleneck (stage paling awal) di antara order tertaut. Null bila belum ada progress. */
    public function manuscriptStatus(): ?string
    {
        $stages = $this->jenis === 'buku' ? TitleProgress::BOOK_STAGES : TitleProgress::ARTICLE_STAGES;

        // Order yang ditarik (refund penuh) tak boleh ikut menentukan bottleneck —
        // satu penulis yang mundur akan menahan tahap seluruh buku selamanya.
        // Disaring lewat koleksi, BUKAN scope: $this->orderDetails di sini sudah
        // di-eager load pemanggilnya (Direktori Judul memuat ratusan baris sekaligus),
        // dan mengubahnya jadi query akan memulangkan N+1 yang sudah dibereskan.
        $statuses = $this->orderDetails
            ->reject(fn ($d) => optional($d->titleProgress)->withdrawn_at !== null)
            ->map(fn ($d) => optional($d->titleProgress)->status)
            ->filter();

        if ($statuses->isEmpty()) {
            return null;
        }

        return $statuses
            ->sortBy(fn ($s) => ($i = array_search($s, $stages, true)) === false ? PHP_INT_MAX : $i)
            ->first();
    }

    public function manuscriptStatusLabel(): ?string
    {
        return self::stageLabel($this->manuscriptStatus());
    }

    public function bookIsbn()
    {
        return $this->hasOne(BookIsbn::class);
    }

    public function docMarks()
    {
        return $this->hasMany(TitleDocMark::class);
    }

    public function docChecklist()
    {
        return $this->hasOne(TitleDocChecklist::class);
    }

    public function archive()
    {
        return $this->hasOne(TitleArchive::class);
    }

    public function archiveArtifacts()
    {
        return $this->hasMany(TitleArchiveArtifact::class)->orderBy('position');
    }

    /**
     * Semua order tertaut sudah lunas (tak ada sisa/DP).
     *
     * Order yang ditarik diabaikan: uangnya sudah dikembalikan, jadi menuntutnya lunas
     * berarti satu refund mematikan kelayakan arsip judul untuk semua penulis lain.
     * Sama seperti manuscriptStatus(), disaring lewat koleksi supaya tidak menambah
     * query per judul di daftar yang sudah eager-load.
     */
    public function isPaidOff(): bool
    {
        $orders = $this->orderDetails
            ->reject(fn ($d) => optional($d->titleProgress)->withdrawn_at !== null)
            ->map->order->filter()->unique('id');

        return $orders->isNotEmpty() && $orders->every(fn ($o) => $o->isLunas());
    }

    public function manuscriptIsFinal(): bool
    {
        return TitleProgress::isFinal((string) $this->manuscriptStatus());
    }

    /** Layak diarsipkan: semua order lunas DAN manuskrip final (terbit/publish). */
    public function archiveEligible(): bool
    {
        return $this->isPaidOff() && $this->manuscriptIsFinal();
    }

    /**
     * Total kekurangan bayar seluruh order judul ini (0 bila lunas).
     *
     * SENGAJA memakai uang yang benar-benar masuk (`paidNet()`), BUKAN `isLunas()`.
     * `isLunas()` menang duluan begitu ada invoice berstatus 'lunas', dan
     * PaymentBookController::approve() menstempel 'lunas' pada invoice SETIAP payment
     * yang disetujui — termasuk DP. Jadi order yang baru bayar DP 200rb dari 500rb sudah
     * dianggap lunas oleh gerbang arsip. Jalan pintas itu sengaja dipertahankan (dikunci
     * PaidNetTest::lunas_invoice_shortcut_still_wins) dan tidak diutak-atik di sini —
     * tapi ia persis alasan keputusan K2 ada: pembayaran tak boleh menghambat naskah,
     * namun di titik penutupan (arsip) kekurangannya harus kelihatan beserta ANGKAnya.
     * Angka inilah sumber kebenaran uang di layar arsip; gerbang "Ajukan" tetap pakai
     * isPaidOff(). Keduanya boleh berbeda — yang satu izin, yang satu fakta.
     *
     * Order yang ditarik tidak dihitung — uangnya sudah dikembalikan, dan menuntutnya
     * lunas berarti satu penulis yang mundur menahan arsip semua penulis lain.
     */
    public function sisaTagihan(): int
    {
        return (int) $this->orderDetails
            ->reject(fn ($d) => optional($d->titleProgress)->withdrawn_at !== null)
            ->sum(function ($d) {
                $order = $d->order;
                if ($order === null) {
                    return 0;
                }

                return max(0, (int) $d->cost_amount - $order->paidNet());
            });
    }

    /** Jumlah order judul ini yang ditarik karena refund. */
    public function jumlahDitarik(): int
    {
        return $this->orderDetails
            ->filter(fn ($d) => optional($d->titleProgress)->withdrawn_at !== null)
            ->count();
    }

    /**
     * Judul yang naskahnya sudah final tapi arsipnya belum diajukan/disetujui.
     *
     * Inilah satu-satunya pintu masuk ke halaman detail arsip untuk judul baru: sebelum
     * ini `archive.show` hanya ditaut dari daftar judul yang SUDAH punya baris arsip,
     * padahal satu-satunya cara membuat baris itu adalah menekan tombol di halaman
     * tersebut — lingkaran tertutup yang membuat arsip praktis tak bisa diajukan.
     *
     * Arsip berstatus 'ditolak' sengaja TIDAK dikecualikan: penolakan harus memulangkan
     * judulnya ke daftar kerja supaya bisa diperbaiki lalu diajukan ulang.
     *
     * Penyaringan tahap final dilakukan di PHP, bukan SQL: `manuscriptStatus()` adalah
     * bottleneck lintas order (dan mengecualikan order yang ditarik) yang tak punya
     * padanan SQL sederhana. Pra-saring `whereHas` menekan jumlah baris yang perlu
     * dihitung. Dipakai bersama oleh daftar Arsip dan ubin dashboard admin supaya
     * angka ubin tak pernah berbeda dari panjang daftarnya.
     */
    public static function siapDiarsipkan(): \Illuminate\Database\Eloquent\Collection
    {
        return static::query()
            ->whereDoesntHave('archive', fn ($q) => $q->whereIn('status', ['diajukan', 'disetujui']))
            ->whereHas('orderDetails.titleProgress', fn ($q) => $q->whereIn('status', TitleProgress::FINAL_STAGES))
            ->with(['orderDetails.titleProgress', 'orderDetails.order'])
            ->latest()
            ->get()
            ->filter->manuscriptIsFinal()
            ->values();
    }

    /** Buku yang manuskripnya sudah mencapai tahap 'isbn' (bottleneck ≥ index 'isbn'). */
    public function isbnEligible(): bool
    {
        if ($this->jenis !== 'buku') {
            return false;
        }
        $status = $this->manuscriptStatus();
        if ($status === null) {
            return false;
        }
        $stages  = TitleProgress::BOOK_STAGES;
        $reached = array_search($status, $stages, true);
        $isbnIdx = array_search('isbn', $stages, true);
        return $reached !== false && $reached >= $isbnIdx;
    }

    /**
     * Order yang memesan bab ke-$urutan.
     *
     * Pada buku KOLABORASI, `order_details.chapters` menyimpan **nomor bab** yang
     * dikontribusikan order itu (satu order = satu author = satu bab), bukan jumlah bab.
     * Dari sinilah asal naskah tiap bab diketahui: `naskah_type` order itulah yang
     * menentukan apakah babnya ditulis tim ('dibuatkan') atau dikirim authornya
     * ('mandiri'). Buku MANDIRI hanya punya satu order yang mencakup seluruh buku.
     *
     * Mengembalikan null bila babnya belum dipesan siapa pun (wajar: belum semua bab
     * terjual) — pemanggil harus memperlakukannya sebagai "belum diketahui", bukan
     * mengasumsikan dibuatkan.
     */
    public function orderForChapter(int $urutan): ?OrderDetail
    {
        $details = $this->relationLoaded('orderDetails')
            ? $this->orderDetails->loadMissing('titleProgress')
            : $this->orderDetails()->with('titleProgress')->get();

        /*
         | Order yang ditarik (refund penuh) tidak lagi memiliki babnya.
         |
         | Tanpa saringan ini pencabutan bab hanya jadi hiasan: ChapterAuthorService::
         | seedFromOrders() berjalan setiap kali halaman judul dibuka, melihat bab yang
         | baru dikosongkan, bertanya ke sini siapa pemesannya, dan memasang kembali
         | penulis yang baru saja dicabut. Terbukti begitu saat Task 5/6 diuji.
         |
         | `titleProgress` sengaja di-eager load: method ini dipanggil sekali per bab di
         | dalam perulangan, jadi memeriksanya lewat lazy load berarti N×N query untuk
         | buku bab banyak.
         |
         | Seluruh pemanggil sudah memperlakukan null sebagai "babnya belum dipesan
         | siapa pun" — itu persis arti yang benar untuk bab yang penulisnya mundur.
         */
        $details = $details->reject(fn ($d) => optional($d->titleProgress)->withdrawn_at !== null);

        if ($details->contains(fn ($d) => $d->type === 'bk_kolab')) {
            return $details->firstWhere(fn ($d) => $d->type === 'bk_kolab'
                && (int) $d->chapters === $urutan);
        }

        return $details->first();
    }

    /** Label rapi untuk satu status tahap manuskrip. */
    public static function stageLabel(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        return match ($status) {
            'loa'       => 'LoA',
            'isbn'      => 'ISBN',
            'pembuatan' => 'Pembuatan Naskah',
            default => Str::title(str_replace('_', ' ', $status)),
        };
    }
}
