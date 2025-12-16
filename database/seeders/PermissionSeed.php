<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PermissionSeed extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // create permissions
        Permission::create(['name' => 'view.order']);
        Permission::create(['name' => 'create.order']);
        Permission::create(['name' => 'edit.order']);
        Permission::create(['name' => 'delete.order']);
        Permission::create(['name' => 'send.order']);
        Permission::create(['name' => 'reminder.order']);

        Permission::create(['name' => 'aproval.order']);
        Permission::create(['name' => 'trash.order']);
        Permission::create(['name' => 'restore.order']);
        Permission::create(['name' => 'refund.order']);
        Permission::create(['name' => 'action.order']);

        //create roles and assign existing permissions
        $marketing = Role::create(['name'=> 'marketing']);
        $marketing->givePermissionTo('view.order');
        $marketing->givePermissionTo('create.order');
        $marketing->givePermissionTo('edit.order');
        $marketing->givePermissionTo('delete.order');
        $marketing->givePermissionTo('send.order');
        $marketing->givePermissionTo('reminder.order');
        $marketing->givePermissionTo('trash.order');
        $marketing->givePermissionTo('restore.order');

        $superadmin = Role::create(['name'=>'superadmin']);
        $manager = Role::create(['name'=>'manager']);
        $production = Role::create(['name'=>'production']);
        $admin = Role::create(['name'=>'admin']);

        // create demo users
        $user = User::factory()->create([
            'name' => 'Super Admin',
            'username' => 'super',
            'email' => 'superadmin@avidpedia.com',
            'password' => Hash::make('password')
        ]);
        $user->assignRole($superadmin);
        $user->profile()->create([
            'user_id' => $user->id,
            'phone_number' => '08123456789',
            'job_description' => 'System Administrator',
            'job_name' => 'IT',
        ]);

        $user = User::factory()->create([
            'name' => 'Fitri Arianti Saputri',
            'username' => 'fitri',
            'email' => 'admin@avidpedia.com',
            'password' => Hash::make('password')
        ]);
        $user->assignRole($manager);
        $user->profile()->create([
            'user_id' => $user->id,
            'phone_number' => '08123456789',
            'job_description' => 'Manager Administrator',
            'job_name' => 'Manager',
        ]);

        $user = User::factory()->create([
            'name' => 'nurul',
            'username' => 'nurul',
            'email' => 'nurull@avidpedia.com',
            'password' => Hash::make('password')
        ]);
        $user->assignRole($admin);
        $user->profile()->create([
            'user_id' => $user->id,
            'phone_number' => '08123456789',
            'job_description' => 'Administrator',
            'job_name' => 'Admin',
        ]);

        $user = User::factory()->create([
            'name' => 'ika',
            'username' => 'ika',
            'email' => 'ikal@avidpedia.com',
            'password' => Hash::make('password')
        ]);
        $user->assignRole($marketing);
        $user->profile()->create([
            'user_id' => $user->id,
            'phone_number' => '08123456789',
            'job_description' => 'Marketing Jurnal',
            'job_name' => 'Marketing',
        ]);

        $user = User::factory()->create([
            'name' => 'pia',
            'username' => 'pia',
            'email' => 'pial@avidpedia.com',
            'password' => Hash::make('password')
        ]);
        $user->assignRole($production);
        $user->profile()->create([
            'user_id' => $user->id,
            'phone_number' => '08123456789',
            'job_description' => 'Production Jurnal',
            'job_name' => 'Production',
        ]);

    }
}
