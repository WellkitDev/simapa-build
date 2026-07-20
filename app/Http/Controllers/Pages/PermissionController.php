<?php
// app/Http/Controllers/Pages/PermissionController.php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Support\PermissionMap;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionController extends Controller
{
    /** Role yang tak boleh disunting: superadmin selalu penuh lewat Gate::before. */
    private const LOCKED = ['superadmin'];

    public function index(Request $request)
    {
        $roles    = Role::orderBy('name')->pluck('name')->all();
        $selected = in_array($request->query('role'), $roles, true)
            ? $request->query('role')
            : (collect($roles)->first(fn ($r) => ! in_array($r, self::LOCKED, true)) ?? $roles[0]);

        $role    = Role::findByName($selected);
        $granted = $role->permissions->pluck('name')->all();

        return view('permissions.index', [
            'roles'    => $roles,
            'selected' => $selected,
            'locked'   => in_array($selected, self::LOCKED, true),
            'matrix'   => PermissionMap::matrix(),
            'granted'  => $granted,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'role'          => ['required', 'string', Rule::exists('roles', 'name')],
            'permissions'   => ['array'],
            'permissions.*' => ['string', Rule::in(PermissionMap::allPermissions())],
        ]);

        abort_if(in_array($data['role'], self::LOCKED, true), 403,
            'Hak akses superadmin tidak dapat diubah.');

        Role::findByName($data['role'])->syncPermissions($data['permissions'] ?? []);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return redirect()
            ->route('permission.index', ['role' => $data['role']])
            ->with('success', 'Hak akses diperbarui.');
    }
}
