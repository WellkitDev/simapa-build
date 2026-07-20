<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Author;
use Illuminate\Support\Facades\Auth;

class AuthorController extends Controller
{
    /** Direktori author: daftar author + jumlah order (marketing: hanya order miliknya). */
    public function index()
    {
        $uid = $this->marketingUid();
        $ownOrders = fn ($o) => $o->where('user_id', $uid);

        $authors = Author::query()
            ->when($uid !== null, fn ($q) => $q->whereHas('orderDetails.order', $ownOrders))
            ->withCount(['orderDetails' => function ($q) use ($uid, $ownOrders) {
                if ($uid !== null) {
                    $q->whereHas('order', $ownOrders);
                }
            }])
            ->orderBy('name')
            ->get();

        return view('authors.index', compact('authors'));
    }

    /** Detail author + riwayat order (marketing: hanya order miliknya; 404 bila bukan). */
    public function show(int $id)
    {
        $uid = $this->marketingUid();
        $ownOrders = fn ($o) => $o->where('user_id', $uid);

        $author = Author::query()
            ->when($uid !== null, fn ($q) => $q->whereHas('orderDetails.order', $ownOrders))
            ->withCount(['orderDetails' => function ($q) use ($uid, $ownOrders) {
                if ($uid !== null) {
                    $q->whereHas('order', $ownOrders);
                }
            }])
            ->with(['orderDetails' => function ($q) use ($uid, $ownOrders) {
                $q->with('order');
                if ($uid !== null) {
                    $q->whereHas('order', $ownOrders);
                }
            }])
            ->findOrFail($id);

        return view('authors.show', compact('author'));
    }

    /**
     * ID user login bila ber-role marketing (dibatasi hanya order miliknya),
     * atau null untuk role penuh (superadmin/manager/admin) yang melihat semua author.
     */
    private function marketingUid(): ?int
    {
        return Auth::user()->hasRole('marketing') ? Auth::id() : null;
    }
}
