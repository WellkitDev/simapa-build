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

    public function orderDetails()
    {
        return $this->belongsToMany(
            OrderDetail::class,
            'tb_author_orders',
            'author_id',
            'order_detail_id'
        )->withPivot('position');
    }

    public function chapters()
    {
        return $this->belongsToMany(TitleChapter::class, 'tb_title_chapter_authors', 'author_id', 'title_chapter_id');
    }

}
