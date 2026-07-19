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
        $sqlPath = base_path('avidpedi_simapa.sql');
        if (! is_file($sqlPath)) {
            $this->error("File dump tidak ditemukan: {$sqlPath}");
            $this->line('Letakkan avidpedi_simapa.sql di root project lalu ulangi.');
            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            'Ini akan MENGHAPUS SELURUH database ' . config('database.connections.'.config('database.default').'.database') .
            ' lalu impor ulang dari dump v1. Lanjut?'
        )) {
            $this->warn('Dibatalkan.');
            return self::FAILURE;
        }

        $sql = file_get_contents($sqlPath);

        $this->resetAndSeed();

        $this->importBusinessData($sql);
        $this->reconcileUsers($sql);
        $this->fixInvoices();

        $this->info('Reset + seed dasar selesai.');
        return self::SUCCESS;
    }

    private function resetAndSeed(): void
    {
        $this->line('→ migrate:fresh ...');
        $this->call('migrate:fresh', ['--force' => true]);

        $this->line('→ seed PermissionSeed ...');
        $this->call('db:seed', ['--class' => 'PermissionSeed', '--force' => true]);
    }

    /** Tabel bisnis yang diimpor apa adanya, urut aman-FK. */
    private const BUSINESS_TABLES = [
        'tb_scopes',
        'tb_authors',
        'tb_orders',
        'tb_order_contacts',
        'tb_order_details',
        'tb_scope_orders',
        'tb_author_orders',
        'tb_payments',
        'tb_payment_approvals',
        'tb_invoices',
    ];

    private function importBusinessData(string $sql): void
    {
        \DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (self::BUSINESS_TABLES as $table) {
                $stmts = self::extractInserts($sql, $table);
                if ($stmts === []) {
                    $this->warn("  ! Tidak ada INSERT untuk {$table} di dump.");
                    continue;
                }
                foreach ($stmts as $stmt) {
                    \DB::unprepared($stmt);
                }
                $this->line("  ✓ {$table}: " . \DB::table($table)->count() . ' baris');
            }
        } finally {
            \DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function reconcileUsers(string $sql): void
    {
        // Buat tabel sementara berstruktur sama, isi dari dump, lalu salin kolom kredensial.
        \DB::statement('CREATE TEMPORARY TABLE _v1_users LIKE users');
        try {
            foreach (self::extractInserts($sql, 'users') as $stmt) {
                \DB::unprepared(str_replace('INSERT INTO `users`', 'INSERT INTO `_v1_users`', $stmt));
            }

            $rows = \DB::table('_v1_users')->get(['id', 'password', 'remember_token', 'email_verified_at', 'created_at', 'updated_at']);
            foreach ($rows as $r) {
                \DB::table('users')->where('id', $r->id)->update([
                    'password'          => $r->password,
                    'remember_token'    => $r->remember_token,
                    'email_verified_at' => $r->email_verified_at,
                    'created_at'        => $r->created_at,
                    'updated_at'        => $r->updated_at,
                ]);
            }
            $this->line('  ✓ kredensial ' . count($rows) . ' user disinkron dari v1');
        } finally {
            \DB::statement('DROP TEMPORARY TABLE IF EXISTS _v1_users');
        }
    }

    private function fixInvoices(): void
    {
        \DB::table('tb_invoices')->update(['type' => 'regular']);
        $n = \DB::table('tb_invoices')->where('status', 'pending')->update(['status' => 'diterbitkan']);
        $this->line("  ✓ invoices: type=regular, {$n} status pending→diterbitkan");
    }
}
