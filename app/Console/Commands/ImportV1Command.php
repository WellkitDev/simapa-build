<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ImportV1Command extends Command
{
    protected $signature = 'simapa:import-v1 {--force : Lewati konfirmasi destruktif}';
    protected $description = 'Cutover: reset DB v2 dan impor penuh dari dump SiMAPA v1 (avidpedi_simapa.sql)';

    /**
     * Ekstrak semua statement INSERT untuk satu tabel dari teks dump SQL.
     * Backtick pembatas mencegah salah tangkap tabel berprefiks sama.
     * Terminator ';' diikuti newline (CR/LF) atau akhir file.
     *
     * @return string[]
     */
    public static function extractInserts(string $sql, string $table): array
    {
        $pattern = '/INSERT INTO `' . preg_quote($table, '/') . '` \(.*?\) VALUES.*?;(?=[\r\n]|$)/s';

        if (preg_match_all($pattern, $sql, $m)) {
            return array_map('trim', $m[0]);
        }

        return [];
    }

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
