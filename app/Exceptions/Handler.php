<?php

namespace App\Exceptions;

use App\Models\ManuscriptFile;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        /*
         | Unggahan yang melebihi post_max_size.
         |
         | PHP membuang seluruh body — field DAN berkas — begitu ambang itu terlampaui,
         | lalu ValidatePostSize melempar PostTooLargeException. Bawaannya berakhir
         | sebagai halaman galat 413 telanjang: tak menyebut ukuran, tak menyebut batas,
         | tak memberi jalan keluar. Pengguna hanya tahu "tidak bisa upload".
         |
         | Formulir ISBN mengirim tiga slot berkas sekaligus, jadi yang menentukan bukan
         | ukuran satu berkas melainkan jumlah ketiganya — sebabnya makin tak kelihatan.
         */
        $this->renderable(function (PostTooLargeException $e, Request $request) {
            $pesan = 'Unggahan terlalu besar, sehingga seluruh isi formulir ditolak server '
                . 'sebelum sempat diproses. Batas per berkas di server ini '
                . ManuscriptFile::batasManusia() . ', dan gabungan seluruh berkas dalam satu '
                . 'pengiriman juga dibatasi. Kecilkan berkasnya, unggah satu per satu, atau '
                . 'minta upload_max_filesize dan post_max_size dinaikkan di server.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $pesan], 413);
            }

            return redirect()->back()->with('error', $pesan);
        });
    }
}
