<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;

class FinancialReportService
{
    /** marketing-only → scope ke order sendiri; selain itu null (lihat semua). */
    public function resolveScope(User $user): ?User
    {
        return $user->hasRole('marketing') && ! $user->hasAnyRole(['manager', 'superadmin'])
            ? $user
            : null;
    }

    /** Pemasukan (kas masuk) — semua payment approved per paid_at, termasuk DP order belum lunas. */
    public function pemasukan(?User $scopeUser): array
    {
        $year = now()->year;
        $q = fn () => Payment::income()->forOrdersOf($scopeUser);

        $kpi = [
            'total'      => (int) $q()->whereYear('paid_at', $year)->sum('amount'),
            'pembayaran' => $q()->whereYear('paid_at', $year)->count(),
            'order'      => $q()->whereYear('paid_at', $year)->distinct()->count('order_id'),
        ];

        $yearly = $q()
            ->selectRaw('YEAR(paid_at) as year, SUM(amount) as total, COUNT(DISTINCT order_id) as order_count')
            ->groupBy('year')->orderByDesc('year')->get();

        $monthly = $q()
            ->selectRaw('YEAR(paid_at) as year, MONTH(paid_at) as month, SUM(amount) as total, COUNT(DISTINCT order_id) as order_count')
            ->groupBy('year', 'month')->orderByDesc('year')->orderByDesc('month')->get();

        $detail = $q()
            ->with(['order.details', 'order.contact', 'invoice'])
            ->orderByDesc('paid_at')->get();

        return compact('kpi', 'yearly', 'monthly', 'detail');
    }

    /** Piutang — order belum lunas: nilai, sudah bayar, sisa. KPI & daftar sama-sama ter-scope. */
    public function piutang(?User $scopeUser): array
    {
        $orders = Order::where('status', '!=', 'lunas')
            ->when($scopeUser, fn ($q) => $q->where('user_id', $scopeUser->id))
            ->with(['details', 'contact'])
            ->withSum(['payments as total_paid' => fn ($q) => $q->income()], 'amount')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function ($o) {
                $o->nilai       = (int) (optional($o->details)->cost_amount ?? 0);
                $o->paid_amount = (int) ($o->total_paid ?? 0);
                $o->sisa        = $o->nilai - $o->paid_amount;
                return $o;
            });

        $kpi = [
            'nilai'   => (int) $orders->sum('nilai'),
            'dibayar' => (int) $orders->sum('paid_amount'),
            'sisa'    => (int) $orders->sum('sisa'),
        ];

        return ['kpi' => $kpi, 'detail' => $orders];
    }

    /** Order selesai (lunas) — nilai penjualan tuntas. */
    public function orderSelesai(?User $scopeUser): array
    {
        $orders = Order::where('status', 'lunas')
            ->when($scopeUser, fn ($q) => $q->where('user_id', $scopeUser->id))
            ->with(['details', 'contact'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(function ($o) {
                $o->nilai         = (int) (optional($o->details)->cost_amount ?? 0);
                // `completed_at` SENGAJA tidak dibaca di sini: sejak order punya
                // fulfillment_status, kolom itu berarti "naskahnya terbit" — tanggal
                // PEKERJAAN, bukan tanggal uang. Sebelum kolom itu pernah terisi, cabang
                // `completed_at ?? updated_at` toh selalu jatuh ke updated_at, jadi ini
                // mempertahankan perilaku yang selama ini benar-benar tampil.
                //
                // JANGAN dibaca sebagai "sudah lepas dari tanggal terbit". Yang hilang
                // hanya ketergantungan EKSPLISIT. Sisanya masih bocor lewat updated_at:
                // OrderFulfillmentService menyimpan ordernya tepat saat naskah mencapai
                // publish/terbit, sehingga updated_at ikut tercap momen terbit — dan
                // orderByDesc('updated_at') di atas menaikkan order yang baru terbit ke
                // puncak laporan uang. Diketahui dan diterima untuk sementara.
                // Perbaikan sebenarnya: tanggal lunas diturunkan dari `paid_at` payment
                // yang disetujui — Kelompok Uang, bukan pekerjaan ini.
                $o->tanggal_lunas = $o->updated_at;
                return $o;
            });

        $kpi = ['jumlah' => $orders->count(), 'nilai' => (int) $orders->sum('nilai')];

        return ['kpi' => $kpi, 'detail' => $orders];
    }
}
