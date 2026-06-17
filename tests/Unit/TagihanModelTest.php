<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tagihan;
use App\Models\TagihanLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TagihanModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_creates_tagihan_with_creator_and_log(): void
    {
        $user = User::factory()->create();

        $tagihan = Tagihan::create([
            'tagihan_no'  => 'TAG-202606-0001',
            'created_by'  => $user->id,
            'client_name' => 'PT Contoh',
            'title'       => 'Naskah X',
            'type'        => 'buku',
            'amount'      => 1500000,
            'status'      => 'diajukan',
        ]);

        $log = TagihanLog::create([
            'tagihan_id'  => $tagihan->id,
            'from_status' => '',
            'to_status'   => 'diajukan',
            'changed_by'  => $user->id,
            'note'        => 'Tagihan dibuat.',
        ]);

        $this->assertEquals($user->id, $tagihan->creator->id);
        $this->assertEquals(1, $tagihan->logs()->count());
        $this->assertEquals('diajukan', $log->to_status);
        $this->assertTrue($tagihan->isEditable());           // diajukan → editable

        $tagihan->update(['status' => 'disetujui']);
        $this->assertFalse($tagihan->isEditable());          // disetujui → terkunci
        $this->assertTrue($tagihan->isDownloadable());       // disetujui → boleh download
    }

    /** @test */
    public function factory_makes_valid_tagihan(): void
    {
        $t = Tagihan::factory()->create();
        $this->assertNotNull($t->tagihan_no);
        $this->assertContains($t->status, Tagihan::STATUSES);
    }
}
