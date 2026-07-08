<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\DataAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class DataAssetModelTest extends TestCase
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

        $private = DataAsset::create(['name' => 'P', 'type' => 'link', 'url' => 'https://x', 'owner_id' => $owner->id, 'visibility' => 'private']);
        $this->assertTrue($private->canView($owner));
        $this->assertFalse($private->canView($other));
        $this->assertTrue($private->isOwner($owner));

        $sharedAll = DataAsset::create(['name' => 'S', 'type' => 'link', 'url' => 'https://x', 'owner_id' => $owner->id, 'visibility' => 'shared', 'shared_roles' => []]);
        $this->assertTrue($sharedAll->canView($other));

        $sharedMgr = DataAsset::create(['name' => 'SM', 'type' => 'link', 'url' => 'https://x', 'owner_id' => $owner->id, 'visibility' => 'shared', 'shared_roles' => ['manager']]);
        $this->assertFalse($sharedMgr->canView($other));
        $this->assertTrue($sharedMgr->canView($manager));
    }
}
