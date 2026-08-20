<?php

namespace App\Console\Commands;

use App\Services\PaymentCashBackfillService;
use App\Services\TitleBackfillService;
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
        $this->regenerateDerived();
        $this->seedProgressAndLogs();
        $this->reconcileFulfillment();
        $this->resetAutoIncrements();
        $this->printSummary();

        $this->info('Impor v1 → v2 selesai.');
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

    private function regenerateDerived(): void
    {
        $titles = (new TitleBackfillService())->run();
        $this->line("  ✓ Title Directory: {$titles} order detail ter-backfill ke Title");

        $cash = (new PaymentCashBackfillService())->run();
        $this->line("  ✓ Kas: {$cash['synced']} payment 'paid' → entri kas");
    }

    private function seedProgressAndLogs(): void
    {
        // Title progress: 1 per order detail, tahap awal.
        $progress = [];
        foreach (\DB::table('tb_order_details')->orderBy('id')->get(['id', 'created_at']) as $d) {
            $progress[] = [
                'order_detail_id' => $d->id,
                'status'          => 'menunggu_proses',
                'assigned_role'   => 'marketing',
                'updated_by'      => 1,
                'started_at'      => $d->created_at,
                'created_at'      => $d->created_at,
                'updated_at'      => $d->created_at,
            ];
        }
        foreach (array_chunk($progress, 100) as $chunk) {
            \DB::table('tb_title_progress')->insert($chunk);
        }
        $this->line('  ✓ title_progress: ' . count($progress) . ' baris');

        // Invoice logs: 1 entri awal per invoice.
        $logs = [];
        foreach (\DB::table('tb_invoices')->orderBy('id')->get(['id', 'created_at']) as $inv) {
            $logs[] = [
                'invoice_id'  => $inv->id,
                'from_status' => '',
                'to_status'   => 'diterbitkan',
                'changed_by'  => 1,
                'note'        => 'Import data produksi v1.',
                'created_at'  => $inv->created_at,
            ];
        }
        foreach (array_chunk($logs, 100) as $chunk) {
            \DB::table('tb_invoice_logs')->insert($chunk);
        }
        $this->line('  ✓ invoice_logs: ' . count($logs) . ' baris');
    }

    private function resetAutoIncrements(): void
    {
        $tables = array_merge(self::BUSINESS_TABLES, [
            'tb_title_progress', 'tb_invoice_logs', 'tb_titles',
        ]);
        foreach ($tables as $t) {
            if (! \DB::getSchemaBuilder()->hasColumn($t, 'id')) {
                continue; // tabel pivot murni (mis. tb_scope_orders) tak punya kolom id/AUTO_INCREMENT.
            }
            $next = ((int) \DB::table($t)->max('id')) + 1;
            \DB::statement("ALTER TABLE `{$t}` AUTO_INCREMENT = {$next}");
        }
        $this->line('  ✓ AUTO_INCREMENT direset dinamis (MAX(id)+1)');
    }

    /**
     * Selaraskan tb_orders.fulfillment_status dengan tahap naskah hasil impor.
     *
     * seedProgressAndLogs() menulis tb_title_progress lewat insert massal, melewati
     * Eloquent sepenuhnya, jadi hook OrderFulfillmentService::syncFromProgress() tak
     * pernah jalan untuk baris impor. Migrasi backfill juga tak menolong: ia sudah
     * selesai jauh sebelum command ini dijalankan. Tanpa langkah ini, naskah v1 yang
     * sudah terbit duduk di atas order yang masih 'berjalan'.
     *
     * Logikanya dipinjam dari OrderFulfillmentService::reconcileAll(), bukan disalin.
     * Migrasi 2026_08_20_000004 sengaja memegang salinan SQL-nya SENDIRI — migrasi
     * adalah catatan sejarah dan tak boleh ikut berubah saat kode aplikasi berubah.
     * Command ini sebaliknya: ia dijalankan hari ini, jadi ia harus memakai aturan
     * yang berlaku hari ini.
     */
    private function reconcileFulfillment(): void
    {
        $n = \App\Services\OrderFulfillmentService::reconcileAll();

        $this->line('  ✓ Status pekerjaan order diselaraskan ('
            . $n['selesai'] . ' selesai, '
            . $n['ditarik'] . ' ditarik, '
            . $n['dibatalkan'] . ' dibatalkan)');
    }

    private function printSummary(): void
    {
        $rows = [];
        foreach (array_merge(self::BUSINESS_TABLES, [
            'tb_titles', 'tb_cash_entries', 'tb_title_progress', 'tb_invoice_logs', 'users',
        ]) as $t) {
            $rows[] = [$t, \DB::table($t)->count()];
        }
        $this->table(['Tabel', 'Baris'], $rows);
    }
}
