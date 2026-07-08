<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\CustomSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class CustomSheetModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['manager', 'marketing', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    /** @test */
    public function view_permission_respects_owner_visibility_and_roles(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create(); $other->assignRole('marketing');
        $manager = User::factory()->create(); $manager->assignRole('manager');

        $private = CustomSheet::create(['name' => 'P', 'owner_id' => $owner->id, 'visibility' => 'private']);
        $this->assertTrue($private->canView($owner));
        $this->assertFalse($private->canView($other));

        $sharedAll = CustomSheet::create(['name' => 'S', 'owner_id' => $owner->id, 'visibility' => 'shared', 'shared_roles' => []]);
        $this->assertTrue($sharedAll->canView($other));
        $this->assertTrue($sharedAll->canEdit($other));

        $sharedMgr = CustomSheet::create(['name' => 'SM', 'owner_id' => $owner->id, 'visibility' => 'shared', 'shared_roles' => ['manager']]);
        $this->assertFalse($sharedMgr->canView($other));   // marketing
        $this->assertTrue($sharedMgr->canView($manager));
        $this->assertTrue($sharedMgr->isOwner($owner));
    }
}
