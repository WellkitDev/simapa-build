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
        return $this->pipelineClass($detail->type) . '|' . $this->normalizeTitle($detail->title);
    }

    public function stagesFor(string $pipelineClass): array
    {
        return $pipelineClass === 'buku'
            ? TitleProgress::BOOK_STAGES
            : TitleProgress::ARTICLE_STAGES;
    }
}
