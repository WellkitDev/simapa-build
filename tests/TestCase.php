<?php

namespace Tests;

use Database\Seeders\AccessMatrixSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Role;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Middleware `EnforcePermission` (2026-07-21) menjaga tiap route bernama
        // lewat permission, fail-closed. Banyak test lama membuat role ad hoc
        // (Role::create/firstOrCreate di setUp() masing-masing) tanpa pernah
        // memanggil AccessMatrixSeeder — role itu berakhir dengan NOL permission,
        // beda dari produksi (yang sudah dihibahkan lewat DatabaseSeeder). Listener
        // ini menyamakan test dengan produksi: begitu sebuah role dibuat di test
        // manapun, langsung dihibahkan paritas yang sama seperti AccessMatrixSeeder
        // produksi. Tidak melonggarkan assertion test manapun — seeder ini memang
        // dirancang mereplikasi persis matriks role: yang berlaku sebelum migrasi.
        Role::created(function () {
            (new AccessMatrixSeeder())->run();
        });
    }
}
