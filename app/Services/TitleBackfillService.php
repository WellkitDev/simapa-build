<?php

namespace App\Services;

use App\Models\OrderDetail;
use App\Models\Title;

class TitleBackfillService
{
    /** Tautkan order lama (title_id=null) ke Title (dibuat/ditemukan). Kembalikan jumlah detail ter-backfill. */
    public function run(): int
    {
        $archive = new TitleArchiveService();
        $codeSvc = new TitleCodeService();
        $count = 0;

        OrderDetail::whereNull('title_id')->with('scopes')->get()
            ->groupBy(fn (OrderDetail $d) => $archive->groupKeyFor($d->type, $d->title))
            ->each(function ($group) use ($archive, $codeSvc, &$count) {
                $repr  = $group->first();
                $jenis = $archive->pipelineClass($repr->type); // buku | artikel
                $tipe  = str_contains($repr->type, 'kolab') ? 'kolaborasi' : 'mandiri';

                $title = Title::where('title', $repr->title)->where('jenis', $jenis)->first()
                    ?? Title::create([
                        'title'       => $repr->title,
                        'code'        => $codeSvc->generate($repr->title),
                        'jenis'       => $jenis,
                        'tipe_naskah' => $tipe,
                        'indeksasi'   => $repr->indexation ?: null,
                        'scope_id'    => optional($repr->scopes->first())->id,
                        'status'      => 'disetujui',
                        'asal'        => 'order',
                        'created_by'  => null,
                    ]);

                foreach ($group as $detail) {
                    $detail->update(['title_id' => $title->id]); // saving hook → group_key = 'title:{id}'
                    $count++;
                }
            });

        return $count;
    }
}
