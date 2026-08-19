<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Pemilik order (`tb_orders.user_id`) — siapa yang boleh menentukannya dan siapa
 * saja yang sah menerimanya.
 *
 * Kolom ini bukan label. Ia menggerakkan tiga hal sekaligus:
 *  - MarketingTargetService menghitung realisasi & komisi dari
 *    `Payment::income()->forOrdersOf($user)`, jadi pemilik order = penerima komisi;
 *  - marketing hanya melihat order dengan user_id miliknya, jadi salah pemilik
 *    berarti order hilang dari layar orang yang mengerjakannya;
 *  - kolom "Marketing" di daftar order, invoice, dan dashboard.
 *
 * Karena itu penentuannya dikunci di satu tempat, bukan disebar di dua controller:
 * order buku dan order jurnal harus tak mungkin berbeda aturan.
 */
class OrderOwnerService
{
    /** Hanya superadmin yang boleh menentukan order ini milik siapa. */
    public function bolehMemilih(User $aktor): bool
    {
        return $aktor->hasRole('superadmin');
    }

    /**
     * Kandidat pemilik: seluruh user ber-role marketing, ditambah aktornya sendiri
     * (superadmin lazimnya bukan marketing, tapi tetap boleh memegang ordernya).
     *
     * @param int|null $sertakan pemilik order SEKARANG, yang wajib selalu ikut
     *        walau ia bukan marketing. Tanpa ini, <select> yang tak memuat nilai
     *        berjalannya akan jatuh ke opsi pertama — menyimpan form edit tanpa
     *        menyentuh apa pun sudah cukup untuk memindahkan order beserta
     *        komisinya. Bukan kasus langka: di DB produksi 137 dari 152 order
     *        dimiliki superadmin, dan marketing yang pindah role keluar dari daftar.
     */
    public function pilihan(User $aktor, ?int $sertakan = null): Collection
    {
        return User::query()
            ->where(fn ($q) => $q
                ->whereHas('roles', fn ($r) => $r->where('name', 'marketing'))
                ->orWhere('id', $aktor->id)
                ->when($sertakan, fn ($qq) => $qq->orWhere('id', $sertakan)))
            ->orderBy('name')
            ->get();
    }

    /**
     * Aturan validasi untuk field `user_id`. Kosong bagi role yang tak berhak —
     * inputnya memang diabaikan diam-diam oleh tentukan(), jadi tak perlu
     * memberitahu bahwa field itu ada.
     */
    public function aturanValidasi(User $aktor, ?int $sertakan = null): array
    {
        if (! $this->bolehMemilih($aktor)) {
            return [];
        }

        return [
            'user_id' => ['nullable', Rule::in($this->pilihan($aktor, $sertakan)->pluck('id')->all())],
        ];
    }

    /**
     * id pemilik yang akan disimpan.
     *
     * Fail-closed: bagi siapa pun selain superadmin, field ini TIDAK dibaca sama
     * sekali — tanpa itu, satu POST langsung sudah cukup untuk memindahkan komisi
     * ke rekening capaian orang lain.
     */
    public function tentukan(User $aktor, mixed $diminta): int
    {
        if (! $this->bolehMemilih($aktor) || blank($diminta)) {
            return $aktor->id;
        }

        // Sudah lolos aturanValidasi(), tapi tentukan() juga dipakai dari jalur lain;
        // memeriksa ulang di sini membuat gerbangnya tak bergantung pada pemanggil.
        return $this->pilihan($aktor)->contains('id', (int) $diminta)
            ? (int) $diminta
            : $aktor->id;
    }

    /**
     * Versi untuk MENGEDIT order yang sudah ada.
     *
     * Bedanya dengan tentukan() krusial: di sini nilai jatuh-baliknya adalah
     * pemilik SEKARANG, bukan aktornya. Memakai tentukan() saat update berarti
     * siapa pun yang menyunting order — admin yang membetulkan nomor telepon,
     * misalnya — diam-diam merebut order itu beserta komisinya dari marketing
     * yang memilikinya.
     */
    public function tentukanUntukPerubahan(User $aktor, mixed $diminta, ?int $sekarang): ?int
    {
        if (! $this->bolehMemilih($aktor) || blank($diminta)) {
            return $sekarang;
        }

        // pilihan() diberi $sekarang supaya menyimpan ulang pemilik yang memang
        // sudah tercatat tak pernah ditolak, sekalipun ia bukan marketing.
        return $this->pilihan($aktor, $sekarang)->contains('id', (int) $diminta)
            ? (int) $diminta
            : $sekarang;
    }
}
