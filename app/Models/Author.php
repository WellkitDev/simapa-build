<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Author extends Model
{
    use HasFactory;

    protected $table = 'tb_authors';
    protected $fillable = ['name','email','phone','affiliation'];

    public function orders()
    {
        return $this->belongsToMany(
            Order::class,
            'tb_author_order'
        )->withPivot('possition')->withTimestamps();
    }

}
