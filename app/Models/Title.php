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

        $statuses = $this->orderDetails
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

    /** Semua order tertaut sudah lunas (tak ada sisa/DP). */
    public function isPaidOff(): bool
    {
        $orders = $this->orderDetails->map->order->filter()->unique('id');
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

    /** Label rapi untuk satu status tahap manuskrip. */
    public static function stageLabel(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        return match ($status) {
            'loa'  => 'LoA',
            'isbn' => 'ISBN',
            default => Str::title(str_replace('_', ' ', $status)),
        };
    }
}
