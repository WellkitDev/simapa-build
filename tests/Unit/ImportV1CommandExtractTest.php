<?php

namespace Tests\Unit;

use App\Console\Commands\ImportV1Command;
use PHPUnit\Framework\TestCase;

class ImportV1CommandExtractTest extends TestCase
{
    private string $sql = <<<'SQL'
INSERT INTO `tb_orders` (`id`, `code_order`) VALUES
(4, 'ORD-4'),
(5, 'ORD-5');

INSERT INTO `tb_order_details` (`id`, `title`) VALUES
(4, 'Judul; dengan titik koma');

SQL;

    public function test_extracts_single_multirow_insert_for_table(): void
    {
        $stmts = ImportV1Command::extractInserts($this->sql, 'tb_orders');

        $this->assertCount(1, $stmts);
        $this->assertStringContainsString("(4, 'ORD-4')", $stmts[0]);
        $this->assertStringContainsString("(5, 'ORD-5')", $stmts[0]);
        // Tidak bocor ke tabel lain
        $this->assertStringNotContainsString('tb_order_details', $stmts[0]);
    }

    public function test_prefix_table_name_not_matched(): void
    {
        // Minta 'tb_order' (tidak ada) tidak boleh menangkap tb_orders / tb_order_details
        $stmts = ImportV1Command::extractInserts($this->sql, 'tb_order');
        $this->assertCount(0, $stmts);
    }

    public function test_returns_empty_when_table_absent(): void
    {
        $stmts = ImportV1Command::extractInserts($this->sql, 'tb_authors');
        $this->assertSame([], $stmts);
    }
}
