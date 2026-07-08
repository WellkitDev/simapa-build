<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomSheet extends Model
{
    protected $table = 'custom_sheets';

    protected $fillable = ['name', 'owner_id', 'visibility', 'shared_roles', 'columns', 'data', 'updated_by'];

    protected $casts = ['shared_roles' => 'array', 'columns' => 'array', 'data' => 'array'];

    const VISIBILITIES = ['private' => 'Pribadi', 'shared' => 'Dibagikan'];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isOwner(User $user): bool
    {
        return (int) $this->owner_id === (int) $user->id;
    }

    public function canView(User $user): bool
    {
        if ($this->isOwner($user)) {
            return true;
        }
        if ($this->visibility !== 'shared') {
            return false;
        }
        if (empty($this->shared_roles)) {
            return true;
        }
        return $user->getRoleNames()->intersect($this->shared_roles)->isNotEmpty();
    }

    public function canEdit(User $user): bool
    {
        return $this->canView($user);
    }
}
