<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\DataAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class DataAssetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['manager', 'marketing', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(?string $role = null): User
    {
        $u = User::factory()->create();
        if ($role) { $u->assignRole($role); }
        return $u;
    }

    /** @test */
    public function creates_link_asset(): void
    {
        $u = $this->user('marketing');
        $this->actingAs($u)->post(route('data.store'), [
            'name' => 'Rekap Marketing', 'type' => 'link', 'url' => 'https://docs.google.com/spreadsheets/d/abc', 'visibility' => 'private',
        ])->assertRedirect();
        $a = DataAsset::where('name', 'Rekap Marketing')->first();
        $this->assertNotNull($a);
        $this->assertSame('link', $a->type);
        $this->assertSame($u->id, $a->owner_id);
        $this->actingAs($u)->get(route('data.index'))->assertOk()->assertSee('Rekap Marketing');
    }

    /** @test */
    public function uploads_excel_file(): void
    {
        Storage::fake();
        $u = $this->user('marketing');
        $file = UploadedFile::fake()->create('stok.xlsx', 120, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->actingAs($u)->post(route('data.store'), [
            'name' => 'Stok', 'type' => 'file', 'file' => $file, 'visibility' => 'private',
        ])->assertRedirect();
        $a = DataAsset::where('name', 'Stok')->first();
        $this->assertSame('file', $a->type);
        $this->assertNotNull($a->file_path);
        $this->assertSame('stok.xlsx', $a->file_name);
        Storage::assertExists($a->file_path);

        $this->actingAs($u)->get(route('data.download', $a->id))->assertOk();
    }

    /** @test */
    public function validation_requires_url_or_file_per_type(): void
    {
        $u = $this->user('marketing');
        $this->actingAs($u)->post(route('data.store'), ['name' => 'X', 'type' => 'link', 'visibility' => 'private'])->assertSessionHasErrors('url');
        $this->actingAs($u)->post(route('data.store'), ['name' => 'Y', 'type' => 'file', 'visibility' => 'private'])->assertSessionHasErrors('file');
    }

    /** @test */
    public function private_hidden_shared_visible(): void
    {
        $a = $this->user('marketing');
        $b = $this->user('manager');
        $priv = DataAsset::create(['name' => 'Rahasia', 'type' => 'link', 'url' => 'https://x', 'owner_id' => $a->id, 'visibility' => 'private']);
        $shar = DataAsset::create(['name' => 'Bareng', 'type' => 'link', 'url' => 'https://x', 'owner_id' => $a->id, 'visibility' => 'shared', 'shared_roles' => []]);

        $this->actingAs($b)->get(route('data.index'))->assertOk()->assertDontSee('Rahasia')->assertSee('Bareng');
        $this->actingAs($b)->get(route('data.download', $priv->id))
            ->assertRedirect(route('data.index'))
            ->assertSessionHas('error', 'Kamu tidak punya akses ke data ini.');
    }

    /** @test */
    public function only_owner_edits_and_deletes(): void
    {
        Storage::fake();
        $a = $this->user('marketing');
        $b = $this->user('manager');
        $file = UploadedFile::fake()->create('d.csv', 10, 'text/csv');
        $this->actingAs($a)->post(route('data.store'), ['name' => 'Milik A', 'type' => 'file', 'file' => $file, 'visibility' => 'shared', 'shared_roles' => []])->assertRedirect();
        $asset = DataAsset::where('name', 'Milik A')->first();

        $pesanPemilik = 'Hanya pemilik yang bisa mengubah atau menghapus data ini.';
        $this->actingAs($b)->get(route('data.edit', $asset->id))
            ->assertRedirect(route('data.index'))->assertSessionHas('error', $pesanPemilik);
        $this->actingAs($b)->put(route('data.update', $asset->id), ['name' => 'Ubah', 'type' => 'link', 'url' => 'https://x', 'visibility' => 'private'])
            ->assertRedirect(route('data.index'))->assertSessionHas('error', $pesanPemilik);
        $this->actingAs($b)->delete(route('data.destroy', $asset->id))
            ->assertRedirect(route('data.index'))->assertSessionHas('error', $pesanPemilik);

        $path = $asset->file_path;
        $this->actingAs($a)->delete(route('data.destroy', $asset->id))->assertRedirect();
        $this->assertNull(DataAsset::find($asset->id));
        Storage::assertMissing($path);
    }

    /** @test */
    public function download_of_deleted_asset_shows_alert(): void
    {
        Storage::fake();
        $a = $this->user('marketing');
        $file = UploadedFile::fake()->create('hapus.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->actingAs($a)->post(route('data.store'), ['name' => 'Akan Dihapus', 'type' => 'file', 'file' => $file, 'visibility' => 'private'])->assertRedirect();
        $asset = DataAsset::where('name', 'Akan Dihapus')->first();
        $id = $asset->id;
        $asset->delete();

        $this->actingAs($a)->get(route('data.download', $id))
            ->assertRedirect(route('data.index'))
            ->assertSessionHas('error', 'Data tidak ditemukan atau sudah dihapus.');
    }

    /** @test */
    public function alert_message_distinguishes_reason(): void
    {
        $a = $this->user('marketing');
        $b = $this->user('manager');
        $shared = DataAsset::create(['name' => 'Dibagikan', 'type' => 'link', 'url' => 'https://x', 'owner_id' => $a->id, 'visibility' => 'shared', 'shared_roles' => []]);
        $private = DataAsset::create(['name' => 'Pribadi', 'type' => 'link', 'url' => 'https://x', 'owner_id' => $a->id, 'visibility' => 'private']);

        // Boleh lihat (shared) tapi bukan pemilik → pesan kepemilikan.
        $this->actingAs($b)->get(route('data.edit', $shared->id))
            ->assertSessionHas('error', 'Hanya pemilik yang bisa mengubah atau menghapus data ini.');

        // Tak boleh lihat sama sekali → pesan akses.
        $this->actingAs($b)->get(route('data.download', $private->id))
            ->assertSessionHas('error', 'Kamu tidak punya akses ke data ini.');
    }
}
