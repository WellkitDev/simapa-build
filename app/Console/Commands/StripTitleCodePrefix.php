<?php

namespace App\Console\Commands;

use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Membersihkan judul yang terlanjur tersimpan sebagai "KODE — Judul".
 *
 * Sengaja command, BUKAN migrasi: isinya harus bisa dilihat dulu (dry run) sebelum
 * mengubah data produksi. Syarat kecocokan kode diperketat — prefix hanya dipangkas
 * bila kodenya benar-benar cocok dengan sebuah tb_titles.code — supaya judul yang
 * memang sah mengandung tanda hubung ("Pendidikan Anak Usia Dini — Sebuah Tinjauan")
 * tidak ikut terpangkas.
 */
class StripTitleCodePrefix extends Command
{
    protected $signature = 'titles:strip-code-prefix {--apply : Jalankan perubahan (tanpa flag = dry run)}';

    protected $description = 'Pangkas prefix "KODE — " dari tb_titles.title dan tb_order_details.title';

    private const PATTERN = '/^(?<code>[A-Za-z0-9\-\/]+)\s*[—\-]\s*(?<rest>.+)$/u';

    public function handle(): int
    {
        $codes = Title::withTrashed()
            ->whereNotNull('code')
            ->pluck('code')
            ->map(fn ($c) => mb_strtoupper(trim((string) $c)))
            ->filter()
            ->flip();

        $candidates = [];

        foreach (Title::withTrashed()->get(['id', 'title']) as $t) {
            $rest = $this->strippedName((string) $t->title, $codes);
            if ($rest !== null) {
                $candidates[] = ['tb_titles', $t->id, (string) $t->title, $rest, $t->id];
            }
        }

        foreach (OrderDetail::withTrashed()->get(['id', 'title', 'title_id']) as $d) {
            $rest = $this->strippedName((string) $d->title, $codes);
            if ($rest !== null) {
                $candidates[] = ['tb_order_details', $d->id, (string) $d->title, $rest, $d->title_id];
            }
        }

        if (empty($candidates)) {
            $this->info('Tidak ada judul berprefix kode. Tidak ada yang perlu diubah.');
            return self::SUCCESS;
        }

        $this->table(
            ['Tabel', 'ID', 'Nilai sekarang', 'Nilai sesudah'],
            array_map(fn ($row) => [$row[0], $row[1], $row[2], $row[3]], $candidates)
        );

        if (! $this->option('apply')) {
            $this->warn('DRY RUN — tidak ada yang diubah. Jalankan ulang dengan --apply untuk menerapkan.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($candidates) {
            foreach ($candidates as [$table, $id, $before, $after, $titleId]) {
                if ($table === 'tb_titles') {
                    Title::withTrashed()->where('id', $id)->update(['title' => $after]);
                } else {
                    OrderDetail::withTrashed()->where('id', $id)->update(['title' => $after]);
                }

                if ($titleId) {
                    TitleLog::create([
                        'title_id'   => $titleId,
                        'event'      => 'code_prefix_stripped',
                        'note'       => $table . '#' . $id . ': "' . $before . '" → "' . $after . '"',
                        'changed_by' => null,
                        'created_at' => now(),
                    ]);
                }
            }
        });

        $this->info(count($candidates) . ' baris dibersihkan.');

        return self::SUCCESS;
    }

    /**
     * Nama judul tanpa prefix kode — atau null bila baris ini tidak boleh disentuh.
     *
     * @param  \Illuminate\Support\Collection<string,int>  $codes  kode terdaftar (uppercase) sebagai kunci
     */
    private function strippedName(string $value, $codes): ?string
    {
        if (preg_match(self::PATTERN, $value, $m) !== 1) {
            return null;
        }

        $code = mb_strtoupper(trim($m['code']));
        $rest = trim($m['rest']);

        if ($rest === '' || ! $codes->has($code)) {
            return null;
        }

        return $rest;
    }
}
