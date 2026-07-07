<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Author;

class AuthorController extends Controller
{
    /** Direktori author: daftar semua author + jumlah order. */
    public function index()
    {
        $authors = Author::withCount('orderDetails')->orderBy('name')->get();

        return view('authors.index', compact('authors'));
    }

    /** Detail author + riwayat order. */
    public function show(int $id)
    {
        $author = Author::withCount('orderDetails')
            ->with(['orderDetails.order'])
            ->findOrFail($id);

        return view('authors.show', compact('author'));
    }
}
