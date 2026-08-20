<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderDetail extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'tb_order_details';

    protected $fillable = [
        'order_id', 'type', 'title_id', 'title', 'slug',
        'chapters', 'indexation',
        'naskah_type', 'publication_type',
        'cost_amount', 'group_key',
    ];

    protected static function booted(): void
    {
        // Kunci grup = identitas Title bila tertaut; jika tidak, turunan (type + judul).
        static::saving(function (OrderDetail $detail) {
            if ($detail->title_id !== null) {
                $detail->group_key = 'title:' . $detail->title_id;
            } elseif ($detail->type !== null && $detail->title !== null) {
                $detail->group_key = (new \App\Services\TitleArchiveService())
                    ->groupKeyFor($detail->type, $detail->title);
            }
        });
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function titleRef()
    {
        return $this->belongsTo(Title::class, 'title_id');
    }

    public function scopes()
    {
        return $this->belongsToMany(
            Scope::class,
            'tb_scope_orders',
            'order_detail_id',
            'scope_id'
        );
    }

    public function authors()
    {
        return $this->belongsToMany(
            Author::class,
            'tb_author_orders',
            'order_detail_id',
            'author_id'
        )->withPivot('position');
    }

    public function titleProgress()
    {
        return $this->hasOne(TitleProgress::class);
    }

    /**
     * Detail yang ordernya masih dihitung sebagai bagian judul — mengecualikan order
     * yang ditarik karena refund penuh.
     *
     * Dipasang di SETIAP tempat yang menjelajah "semua order sejudul". Tanpa ini satu
     * refund menahan bottleneck judul (manuscriptStatus) dan mematikan kelayakan arsip
     * (isPaidOff) untuk SELURUH penulis lain — buku 20 bab, satu penulis mundur, arsip
     * mati permanen buat 19 sisanya.
     *
     * `whereDoesntHave` sengaja: detail yang BELUM punya baris progress sama sekali
     * harus tetap ikut terhitung (order baru yang progressnya menyusul). Menuliskannya
     * sebagai `whereHas(... whereNull)` akan membuang mereka diam-diam.
     */
    public function scopeNotWithdrawn($query)
    {
        return $query->whereDoesntHave(
            'titleProgress',
            fn ($q) => $q->whereNotNull('withdrawn_at')
        );
    }

    /**
     * Naskahnya dikirim sendiri oleh author, bukan ditulis tim produksi.
     * Menentukan apakah tahap Pembuatan relevan sama sekali — order mandiri
     * melompatinya begitu file masuk.
     */
    public function naskahMandiri(): bool
    {
        return $this->naskah_type === 'mandiri';
    }

    /** Label pendek untuk ditampilkan di kolom Pelaksana / meta naskah. */
    public function naskahTypeLabel(): string
    {
        return $this->naskahMandiri() ? 'Naskah Mandiri' : 'Naskah Dibuatkan';
    }

    /**
     * Jenis naskah untuk SEKUMPULAN order sejudul → 'mandiri' | 'dibuatkan' | 'campuran'.
     *
     * `naskah_type` melekat pada order, bukan judul, dan satu judul bisa dicakup beberapa
     * order dengan jenis berbeda (di data nyata ada buku kolaborasi dengan 4 order
     * "dibuatkan" + 1 "mandiri"). Karena itu kartu papan yang mewakili satu grup TIDAK
     * boleh memakai jenis satu order perwakilan — hasilnya menyesatkan bagi empat order
     * lainnya. Kembalikan 'campuran' supaya layar mengaku tidak seragam.
     */
    public static function jenisNaskahGrup(iterable $details): string
    {
        $jenis = collect($details)->filter()
            ->map(fn (self $d) => $d->naskahMandiri() ? 'mandiri' : 'dibuatkan')
            ->unique()
            ->values();

        return $jenis->count() === 1 ? $jenis->first() : 'campuran';
    }

    /** Label siap tampil untuk hasil jenisNaskahGrup(). */
    public static function labelJenisNaskah(string $jenis): string
    {
        return match ($jenis) {
            'mandiri'   => 'Naskah Mandiri',
            'dibuatkan' => 'Naskah Dibuatkan',
            default     => 'Naskah Campuran',
        };
    }
}
