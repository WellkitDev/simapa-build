<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnforceIdempotency
{
    /** Method yang aman/idempoten secara HTTP — tak perlu dedupe. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        // Fail-open: hanya bekerja bila token hadir (form field atau header AJAX).
        // Wajib skalar — abaikan bila _idempotency_key dikirim sebagai array
        // (mis. _idempotency_key[]=...) agar tak menyatu jadi satu key "Array".
        $token = $request->input('_idempotency_key');
        if (! is_string($token) || $token === '') {
            $token = $request->header('Idempotency-Key');
        }
        if (! is_string($token) || $token === '') {
            return $next($request);
        }
        $token = Str::limit($token, 191, '');

        // Klaim atomik: unique index pada `key`. Dua request paralel -> hanya satu
        // yang berhasil INSERT; yang kalah menabrak duplicate-key -> short-circuit.
        try {
            IdempotencyKey::create([
                'key'     => $token,
                'user_id' => optional($request->user())->id,
                'method'  => $request->method(),
                'path'    => Str::limit($request->path(), 255, ''),
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicate($e)) {
                return redirect()->back()
                    ->with('info', 'Permintaan sudah diproses, data tidak digandakan.');
            }
            throw $e;
        }

        // Klaim tuntas hanya bila sukses: bila request pertama gagal/lempar
        // exception, lepas klaim agar user bisa submit ulang token yang sama.
        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            IdempotencyKey::where('key', $token)->delete();
            throw $e;
        }

        if ($this->isFailure($request, $response)) {
            IdempotencyKey::where('key', $token)->delete();
        }

        return $response;
    }

    private function isDuplicate(QueryException $e): bool
    {
        // SQLSTATE 23000 = integrity constraint violation (unique).
        return (string) ($e->errorInfo[0] ?? $e->getCode()) === '23000';
    }

    private function isFailure(Request $request, Response $response): bool
    {
        if ($response->getStatusCode() >= 400) {
            return true;
        }

        // Anggap gagal HANYA bila flash 'errors'/'error' dibuat oleh request INI —
        // yaitu ada di _flash.new. Jangan pakai session()->has(): flash sisa dari
        // request sebelumnya masih terbaca di sini (flash-aging Laravel terjadi di
        // StartSession yang membungkus middleware ini), sehingga has() bisa positif
        // palsu dan salah melepas klaim dari penulisan yang sebenarnya sukses.
        $freshFlash = (array) $request->session()->get('_flash.new', []);

        return in_array('errors', $freshFlash, true)
            || in_array('error', $freshFlash, true);
    }
}
