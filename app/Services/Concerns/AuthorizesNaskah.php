<?php

namespace App\Services\Concerns;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Gerbang bersama modul Penugasan Naskah: izin aksi + batas bidang admin.
 * Dipakai AssignmentService & TitleProgressService supaya aturannya tidak
 * bercabang dua versi.
 */
trait AuthorizesNaskah
{
    protected function requirePermission(User $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new AuthorizationException('Anda tidak berhak melakukan aksi ini.');
        }
    }

    /**
     * Admin hanya berwenang atas bidangnya sendiri. `bidang` kosong berarti BELUM
     * di-scope (belum ada layar untuk mengisinya) — diperlakukan tanpa batas bidang,
     * bukan terkunci, supaya modul tetap jalan sebelum profil diisi. Superadmin bebas.
     */
    protected function requireBidang(User $actor, ?string $bidang): void
    {
        if ($actor->hasRole('superadmin') || $bidang === null) {
            return;
        }

        $actorBidang = $actor->profile?->bidang;
        if ($actorBidang !== null && $actorBidang !== $bidang) {
            throw new AuthorizationException('Naskah ini di luar bidang Anda.');
        }
    }
}
