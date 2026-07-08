<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataAsset extends Model
{
    protected $table = 'data_assets';

    protected $fillable = ['name', 'description', 'type', 'url', 'file_path', 'file_name', 'file_size', 'owner_id', 'visibility', 'shared_roles', 'updated_by'];

    protected $casts = ['shared_roles' => 'array', 'file_size' => 'integer'];

    const VISIBILITIES = ['private' => 'Pribadi', 'shared' => 'Dibagikan'];
    const TYPES = ['link' => 'Link', 'file' => 'File'];

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
}
