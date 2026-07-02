<?php

namespace App\Services;

use App\Models\OrderDetail;
use App\Models\TitleProgress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TitleArchiveService
{
    public function normalizeTitle(string $title): string
    {
        return Str::of($title)
            ->lower()
            ->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ') // strip punctuation
            ->replaceMatches('/\s+/u', ' ')              // collapse whitespace
            ->trim()
            ->value();
    }

    public function pipelineClass(string $type): string
    {
        return in_array($type, ['bk_mandiri', 'bk_kolab'], true) ? 'buku' : 'artikel';
    }

    public function groupKey(OrderDetail $detail): string
    {
        return $detail->title_id !== null
            ? 'title:' . $detail->title_id
            : $this->groupKeyFor($detail->type, $detail->title);
    }

    public function groupKeyFor(string $type, string $title): string
    {
        return $this->pipelineClass($type) . '|' . $this->normalizeTitle($title);
    }

    public function stagesFor(string $pipelineClass): array
    {
        return $pipelineClass === 'buku'
            ? TitleProgress::BOOK_STAGES
            : TitleProgress::ARTICLE_STAGES;
    }

    public function groupDetails(Collection $details): Collection
    {
        return $details
            ->groupBy(fn (OrderDetail $d) => $this->groupKey($d))
            ->map(fn (Collection $group) => $this->summarize($group))
            ->sortByDesc('last_update')
            ->values();
    }

    public function summarize(Collection $details): object
    {
        // Representative = most recently updated variant; tie-break by largest id.
        $repr = $details
            ->sort(fn (OrderDetail $a, OrderDetail $b) =>
                ($this->lastUpdateOf($b)->timestamp <=> $this->lastUpdateOf($a)->timestamp)
                    ?: ($b->id <=> $a->id))
            ->first();

        $pipeline = $this->pipelineClass($repr->type);
        $stages   = $this->stagesFor($pipeline);

        $statuses = $details->map(fn (OrderDetail $d) =>
            optional($d->titleProgress)->status ?? 'menunggu_proses');

        // Bottleneck = status with the smallest stage index.
        $bottleneck = $statuses
            ->sortBy(fn (string $s) =>
                ($i = array_search($s, $stages, true)) === false ? PHP_INT_MAX : $i)
            ->first();

        return (object) [
            'detail_id_repr'    => $repr->id,
            'title'             => $repr->title,
            'type'              => $repr->type,
            'type_label'        => $pipeline === 'buku' ? 'Buku' : 'Artikel',
            'total_author'      => $details
                                    ->flatMap(fn (OrderDetail $d) => $d->authors->pluck('id'))
                                    ->unique()
                                    ->count(),
            'bottleneck_status' => $bottleneck,
            'handler'           => TitleProgress::getHandlerForStatus($bottleneck),
            'last_update'       => $details->map(fn (OrderDetail $d) => $this->lastUpdateOf($d))->max(),
            'is_mixed'          => $statuses->unique()->count() > 1,
            'target_date'       => optional($repr->titleProgress)->target_date,
            'is_overdue'        => optional($repr->titleProgress)->target_date
                                    && $repr->titleProgress->target_date->lt(today())
                                    && ! TitleProgress::isFinal($bottleneck),
        ];
    }

    private function lastUpdateOf(OrderDetail $detail): Carbon
    {
        return optional($detail->titleProgress)->updated_at ?? $detail->updated_at;
    }
}
