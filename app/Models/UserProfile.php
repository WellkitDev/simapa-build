<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UserProfile extends Model
{
    use HasFactory;
    use SoftDeletes;
    protected $table = 'user_profiles';
    protected $fillable = [
        'user_id', 'phone_number', 'address', 'birth_date',
        'job_description', 'job_name', 'profile_picture_url','profile_picture_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
