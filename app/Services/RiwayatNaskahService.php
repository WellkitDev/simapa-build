<?php

namespace App\Services;

use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Pencatat dan pembaca riwayat naskah.
 *
 * Riwayat disimpan di `tb_title_progress_logs`, yang barisnya menempel pada satu
 * PROGRESS (satu order) — sementara berkas dan putaran perbaikan menempel pada JUDUL.
 * Satu judul bisa punya banyak order, jadi dua hal harus diputuskan:
 *
 *  - MENULIS: peristiwa milik judul dicatat sekali saja, pada progress paling awal yang
 *    masih hidup. Menulisnya ke tiap order akan menggandakan satu unggahan jadi lima
 *    baris identik.
 *  - MEMBACA: layar naskah menggabungkan riwayat SELURUH order sejudul, lalu membuang
 *    kembarannya. Tanpa itu, membuka order kedua sebuah buku menyembunyikan separuh
 *    sejarahnya — dan kartunya berjanji "semua aksi tercatat".
 */
class RiwayatNaskahService
{
    /**
     * Catat peristiwa milik JUDUL (berkas, putaran revisi) satu kali.
     *
     * Diam-diam tak melakukan apa pun bila judulnya tak punya progress hidup — mencatat
     * riwayat bukan alasan untuk menggagalkan unggahan yang sudah berhasil.
     */
    public function catatJudul(
        Title $title,
        string $event,
        User $actor,
        ?string $dari = null,
        ?string $ke = null,
        ?string $catatan = null
    ): ?TitleProgressLog {
        $progress = $this->progressUtama($title);
        if (! $progress) {
            return null;
        }

        return TitleProgressLog::create([
            'title_progress_id' => $progress->id,
            'event'             => $event,
            'from_value'        => $dari,
            'to_value'          => $ke,
            'changed_by'        => $actor->id,
            'note'              => $catatan,
            'is_correction'     => false,
        ]);
    }

    /**
     * Riwayat lengkap untuk layar naskah: seluruh order sejudul, tanpa kembaran.
     *
     * Perpindahan tahap dicatat pada SETIAP anggota grup (applyGroup memanggil
     * applyStatus per order), jadi menggabungkan begitu saja menghasilkan lima baris
     * "Maju tahap" yang sama persis. Kembarannya dibuang lewat sidik jari peristiwa.
     *
     * @return Collection<int,TitleProgressLog>
     */
    public function untukLayar(TitleProgress $progress): Collection
    {
        $key = $progress->orderDetail?->group_key;

        $logs = $key === null
            ? $progress->logs()->with('changedBy')->get()
            : TitleProgressLog::with('changedBy')
                ->whereIn('title_progress_id', TitleProgress::whereHas(
                    'orderDetail',
                    fn ($q) => $q->where('group_key', $key)
                )->pluck('id'))
                ->get();

        return $logs
            ->unique(fn (TitleProgressLog $l) => implode('|', [
                $l->event,
                $l->from_value,
                $l->to_value,
                $l->changed_by,
                $l->note,
                // Detik yang sama = satu peristiwa yang sama, dicatat per anggota grup.
                $l->created_at?->format('Y-m-d H:i:s'),
            ]))
            ->sortByDesc('created_at')
            ->values();
    }

    /**
     * Progress tempat peristiwa milik judul dicatat: yang paling awal dan masih hidup.
     *
     * Order yang ditarik (refund penuh) dilewati — riwayat naskah tak boleh menggantung
     * pada order yang sudah tak lagi bagian dari judulnya.
     */
    private function progressUtama(Title $title): ?TitleProgress
    {
        return TitleProgress::whereHas(
            'orderDetail',
            fn ($q) => $q->where('title_id', $title->id)
        )
            ->whereNull('withdrawn_at')
            ->whereNull('cancelled_at')
            ->orderBy('id')
            ->first();
    }
}
