<?php
// app/Services/ManuscriptStageStatsService.php

namespace App\Services;

use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Sumber tunggal "judul unik per tahap" untuk donut dashboard.
 * Reuse TitleArchiveService::groupDetails() agar penghitungan (dedupe group_key +
 * bottleneck + tipe buku/artikel) SELALU sama dengan Pelacak Naskah / Arsip Judul.
 */
class ManuscriptStageStatsService
{
    public function __construct(private TitleArchiveService $archive) {}

    /** Seluruh judul dalam produksi. */
    public function global(): array
    {
        return $this->tally($this->loadDetails(null));
    }

    /** Scope "mine": judul yang punya minimal satu varian assigned ke $user. */
    public function forEditor(User $user): array
    {
        return $this->tally($this->loadDetails($user->id));
    }

    private function loadDetails(?int $assignedUserId): Collection
    {
        $query = OrderDetail::query()
            ->with(['titleProgress', 'authors:id'])
            ->whereHas('titleProgress');

        if ($assignedUserId !== null) {
            $groupKeys = OrderDetail::query()
                ->whereHas('titleProgress', fn ($t) => $t->where('pelaksana_user_id', $assignedUserId))
                ->pluck('group_key')->unique()->all();
            $query->whereIn('group_key', $groupKeys); // [] → tak ada baris
        }

        return $query->get();
    }

    private function tally(Collection $details): array
    {
        $buku = [];
        $artikel = [];

        foreach ($this->archive->groupDetails($details) as $summary) {
            $stage = $summary->bottleneck_status;
            if ($stage === 'menunggu_proses' || in_array($stage, TitleProgress::FINAL_STAGES, true)) {
                continue;
            }
            if ($summary->type_label === 'Buku') {
                $buku[$stage] = ($buku[$stage] ?? 0) + 1;
            } else {
                $artikel[$stage] = ($artikel[$stage] ?? 0) + 1;
            }
        }

        return [
            'buku'    => $this->toChart($buku, TitleProgress::BOOK_STAGES),
            'artikel' => $this->toChart($artikel, TitleProgress::ARTICLE_STAGES),
        ];
    }

    /** {labels, series} terurut sesuai urutan tahap; hanya tahap yang punya judul. */
    private function toChart(array $counts, array $stageOrder): array
    {
        $labels = [];
        $series = [];
        foreach ($stageOrder as $stage) {
            if (! empty($counts[$stage])) {
                $labels[] = Str::title(str_replace('_', ' ', $stage));
                $series[] = $counts[$stage];
            }
        }
        return ['labels' => $labels, 'series' => $series];
    }
}
