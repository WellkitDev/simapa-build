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
        $token = $request->input('_idempotency_key') ?: $request->header('Idempotency-Key');
        if (! $token) {
            return $next($request);
        }
        $token = Str::limit((string) $token, 191, '');

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
        $session = $request->session();
        if ($session->has('errors') && optional($session->get('errors'))->any()) {
            return true;
        }
        if ($session->has('error')) {
            return true;
        }
        return false;
    }
}
