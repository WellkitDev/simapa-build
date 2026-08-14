#!/usr/bin/env bash
#
# deploy.sh — langkah-langkah yang HARUS dijalankan di server setiap selesai
# mengunggah rilis baru. Paket deploy hanya mengganti kode; database tidak
# ikut berubah dengan sendirinya.
#
# Pakai (dari cPanel → Cron Jobs, atau terminal):
#
#   /home/avidpedi/simapav2.avidpedia.com/deploy.sh
#
# Semua keluaran dicatat ke storage/logs/deploy.log SEKALIGUS dicetak ke stdout,
# sehingga cPanel juga mengirimkannya lewat email cron. Berhenti di langkah
# pertama yang gagal dan keluar dengan kode ≠ 0, supaya kegagalan tidak terlewat.
#
# Opsi:
#   --tanpa-hak-akses   lewati seeder hak akses (lihat peringatan di bawah)
#   --uji-coba          tampilkan rencana penyelarasan data tanpa mengubah apa pun
#
set -uo pipefail

cd "$(dirname "$0")" || { echo "Tidak bisa masuk ke folder aplikasi."; exit 1; }

# PHP CLI. cPanel biasanya /usr/local/bin/php; timpa dengan PHP_BIN bila beda.
PHP="${PHP_BIN:-/usr/local/bin/php}"
command -v "$PHP" >/dev/null 2>&1 || PHP="php"

SEED_AKSES=1
DRY=""
for arg in "$@"; do
    case "$arg" in
        --tanpa-hak-akses) SEED_AKSES=0 ;;
        --uji-coba)        DRY="--dry-run" ;;
        *) echo "Opsi tak dikenal: $arg"; exit 2 ;;
    esac
done

mkdir -p storage/logs
LOG="storage/logs/deploy.log"

catat() { printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$LOG"; }

# Jalankan satu langkah; hentikan seluruh proses bila gagal. Keluaran perintah
# ikut masuk log supaya pesan error aslinya (mis. SQL) tidak hilang.
langkah() {
    judul="$1"; shift
    catat "--> $judul"
    if "$@" >>"$LOG" 2>&1; then
        catat "    BERHASIL: $judul"
    else
        rc=$?
        catat "    GAGAL (kode keluar $rc): $judul"
        catat "!!! DEPLOY DIHENTIKAN. Pesan error lengkap ada di $(pwd)/$LOG"
        catat "=== DEPLOY GAGAL ==="
        exit "$rc"
    fi
}

catat "================================================================"
catat "=== MULAI DEPLOY ==="
catat "PHP: $($PHP -v 2>/dev/null | head -n1)"

langkah "Migrasi database" \
    "$PHP" artisan migrate --force

# PERINGATAN: seeder ini memakai syncPermissions per role, jadi ia MENGEMBALIKAN
# hak akses tiap role ke matriks bawaan. Kalau Anda sudah menyesuaikan hak akses
# lewat halaman /hak-akses, jalankan dengan --tanpa-hak-akses agar penyesuaian
# itu tidak tertimpa. Halaman Hak Akses sendiri kini membuat baris permission
# yang belum ada, jadi seeder ini hanya wajib pada rilis yang menambah modul baru.
if [ "$SEED_AKSES" = 1 ]; then
    langkah "Seed hak akses (matriks bawaan per role)" \
        "$PHP" artisan db:seed --class=AccessMatrixSeeder --force
else
    catat "--> Seed hak akses DILEWATI (--tanpa-hak-akses)"
fi

langkah "Seed katalog layanan" \
    "$PHP" artisan db:seed --class=ServiceCatalogSeeder --force

# Idempoten: sekali data sudah selaras, jalan berikutnya menghasilkan nol perubahan.
langkah "Penyelarasan data naskah v2" \
    "$PHP" artisan naskah:migrate-v2 $DRY

langkah "Bersihkan cache config/route/view" \
    "$PHP" artisan optimize:clear

catat "=== DEPLOY BERHASIL: semua langkah selesai ==="
catat "Log tersimpan di $(pwd)/$LOG"
