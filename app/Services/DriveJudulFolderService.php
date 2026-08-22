<?php

namespace App\Services;

use App\Models\Title;
use Illuminate\Support\Facades\Log;

/**
 * Menerjemahkan judul + slot berkas jadi id folder Google Drive.
 *
 * Sebelum ini seluruh berkas naskah, berkas ISBN, LoA jurnal, kelengkapan dokumen, dan
 * artefak arsip mendarat rata di satu folder aplikasi — empat titik unggah yang semuanya
 * memanggil uploadFile() dengan folder null. Padahal keempatnya sudah memegang judulnya.
 *
 * Susunannya:
 *
 *     <GOOGLE_DRIVE_FOLDER_ID>/
 *         MAMT-1/                    ← {KODE}-{id}
 *             Naskah/{Masuk,Hasil Editing,Revisi,Final,Layout,Proofread,Cover}
 *             Berkas ISBN/
 *             Jurnal/
 *             Kelengkapan Dokumen/
 *             Arsip/
 *
 * Folder dibuat MALAS — hanya saat ada berkas yang benar-benar masuk ke situ.
 */
class DriveJudulFolderService
{
    /** Kategori tetap di bawah folder judul. */
    public const ARSIP       = 'Arsip';
    public const BERKAS_ISBN = 'Berkas ISBN';
    public const JURNAL      = 'Jurnal';
    public const KELENGKAPAN = 'Kelengkapan Dokumen';

    /**
     * Slot berkas → jalur di dalam folder judul.
     *
     * Nama berkas aslinya sering tak menerangkan apa pun ("final.docx", "revisi2.docx"),
     * jadi foldernyalah yang menerangkan.
     */
    private const PETA_SLOT = [
        'masuk'           => 'Naskah/Masuk',
        'hasil_editing'   => 'Naskah/Hasil Editing',
        'revisi_minta'    => 'Naskah/Revisi',
        'revisi_hasil'    => 'Naskah/Revisi',
        'final'           => 'Naskah/Final',
        'hasil_layout'    => 'Naskah/Layout',
        'hasil_proofread' => 'Naskah/Proofread',
        'cover'           => 'Naskah/Cover',
        'loa'             => self::JURNAL,
        'ebook'           => self::BERKAS_ISBN,
        'barcode_isbn'    => self::BERKAS_ISBN,
        'sertifikat_hki'  => self::BERKAS_ISBN,
    ];

    public function __construct(private GoogleDriveService $drive) {}

    /**
     * Nama folder judul: kode di depan supaya terbaca dan bisa dicari, id di belakang
     * supaya tak pernah kembar.
     *
     * Kode judul BUKAN penanda unik — isinya akronim empat huruf turunan judul (MAMT,
     * PEDT, SCAN), dan dua judul berbeda bisa menghasilkan kode sama. Tanpa id, berkas
     * keduanya akan bercampur dalam satu folder tanpa ada yang menyadari.
     */
    public static function namaFolder(Title $title): string
    {
        $kode = trim((string) $title->code);

        return ($kode === '' ? 'TANPA-KODE' : $kode) . '-' . $title->id;
    }

    /** Jalur di dalam folder judul untuk sebuah slot berkas. */
    public static function jalurSlot(string $slot): ?string
    {
        return self::PETA_SLOT[$slot] ?? null;
    }

    /**
     * Id folder judul, dibuat bila belum ada.
     *
     * Disimpan di `tb_titles.drive_folder_id` supaya penelusuran lapis folder tak
     * diulang tiap unggahan. Dicari lewat id, bukan nama — jadi kode judul boleh
     * disunting kapan saja tanpa membuat folder lamanya hilang.
     */
    public function folderJudul(Title $title): ?string
    {
        if ($title->drive_folder_id) {
            return $title->drive_folder_id;
        }

        $id = $this->drive->getOrCreateFolderByPath(self::namaFolder($title), $this->akar());
        if ($id) {
            $title->forceFill(['drive_folder_id' => $id])->save();
        }

        return $id;
    }

    /**
     * Id folder tujuan untuk sebuah slot berkas.
     *
     * Mengembalikan null bila apa pun gagal — dan itu SENGAJA tidak melempar. Pemanggil
     * yang menerima null mengunggah ke folder aplikasi seperti perilaku lama: berkas
     * yang salah tempat masih jauh lebih baik daripada berkas yang hilang, dan orang
     * yang mengunggah naskah 20 MB tak boleh ditolak karena persoalan tata folder.
     */
    public function folderSlot(Title $title, string $slot): ?string
    {
        return $this->folderUntuk($title, self::jalurSlot($slot));
    }

    /** Id folder untuk salah satu kategori tetap (Jurnal, Arsip, Kelengkapan Dokumen). */
    public function folderKategori(Title $title, string $kategori): ?string
    {
        return $this->folderUntuk($title, $kategori);
    }

    /**
     * @param  string|null  $jalur  Null / slot tak dikenal → folder judul itu sendiri.
     *                              Berkas di folder judul masih tertata; berkas yang
     *                              gagal terunggah tidak.
     */
    private function folderUntuk(Title $title, ?string $jalur): ?string
    {
        try {
            $induk = $this->folderJudul($title);
            if (! $induk) {
                return null;
            }

            if ($jalur === null || $jalur === '') {
                return $induk;
            }

            return $this->drive->getOrCreateFolderByPath($jalur, $induk);
        } catch (\Throwable $e) {
            Log::warning('Gagal menyiapkan folder Drive untuk judul ' . $title->id . ': ' . $e->getMessage());

            return null;
        }
    }

    /** Folder aplikasi tempat seluruh pohon judul bercabang. */
    private function akar(): ?string
    {
        return config('filesystems.disks.google.folderId') ?: null;
    }
}
