<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\CustomSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class CustomSheetTest extends TestCase
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
    public function user_creates_and_opens_sheet(): void
    {
        $u = $this->user('marketing');
        $this->actingAs($u)->post(route('sheet.store'), ['name' => 'Stok Buku'])->assertRedirect();
        $sheet = CustomSheet::where('name', 'Stok Buku')->first();
        $this->assertNotNull($sheet);
        $this->assertSame($u->id, $sheet->owner_id);
        $this->actingAs($u)->get(route('sheet.index'))->assertOk()->assertSee('Stok Buku');
        $this->actingAs($u)->get(route('sheet.show', $sheet->id))->assertOk();
    }

    /** @test */
    public function private_sheet_hidden_from_others(): void
    {
        $a = $this->user('marketing');
        $b = $this->user('manager');
        $sheet = CustomSheet::create(['name' => 'Rahasia', 'owner_id' => $a->id, 'visibility' => 'private']);

        $this->actingAs($b)->get(route('sheet.index'))->assertOk()->assertDontSee('Rahasia');
        $this->actingAs($b)->get(route('sheet.show', $sheet->id))->assertForbidden();
    }

    /** @test */
    public function shared_sheet_visible_and_editable(): void
    {
        $a = $this->user('marketing');
        $b = $this->user('manager');
        $sheet = CustomSheet::create(['name' => 'Bareng', 'owner_id' => $a->id, 'visibility' => 'shared', 'shared_roles' => []]);

        $this->actingAs($b)->get(route('sheet.index'))->assertOk()->assertSee('Bareng');
        $this->actingAs($b)->get(route('sheet.show', $sheet->id))->assertOk();
        $this->actingAs($b)->postJson(route('sheet.save', $sheet->id), ['data' => [['x', 'y']], 'columns' => []])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame([['x', 'y']], $sheet->fresh()->data);
    }

    /** @test */
    public function shared_roles_restrict_access(): void
    {
        $a = $this->user('marketing');
        $mkt = $this->user('marketing');
        $mgr = $this->user('manager');
        $sheet = CustomSheet::create(['name' => 'KhususManajer', 'owner_id' => $a->id, 'visibility' => 'shared', 'shared_roles' => ['manager']]);

        $this->actingAs($mkt)->get(route('sheet.show', $sheet->id))->assertForbidden();
        $this->actingAs($mgr)->get(route('sheet.show', $sheet->id))->assertOk();
    }

    /** @test */
    public function only_owner_updates_or_deletes(): void
    {
        $a = $this->user('marketing');
        $b = $this->user('manager');
        $sheet = CustomSheet::create(['name' => 'Milik A', 'owner_id' => $a->id, 'visibility' => 'shared', 'shared_roles' => []]);

        $this->actingAs($b)->put(route('sheet.update', $sheet->id), ['name' => 'Ubah', 'visibility' => 'private'])->assertForbidden();
        $this->actingAs($b)->delete(route('sheet.destroy', $sheet->id))->assertForbidden();

        $this->actingAs($a)->put(route('sheet.update', $sheet->id), ['name' => 'Baru', 'visibility' => 'private'])->assertRedirect();
        $this->assertSame('Baru', $sheet->fresh()->name);
        $this->actingAs($a)->delete(route('sheet.destroy', $sheet->id))->assertRedirect();
        $this->assertNull(CustomSheet::find($sheet->id));
    }
}
