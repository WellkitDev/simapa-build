<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\User;
use Illuminate\Support\Collection;

class AnnouncementService
{
    /** Pengumuman published untuk dashboard: pinned dulu lalu terbaru, + flag is_new per user. */
    public function forDashboard(User $user): Collection
    {
        $announcements = Announcement::with('creator')
            ->where('status', 'published')
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->get();

        $readIds = AnnouncementRead::where('user_id', $user->id)
            ->whereIn('announcement_id', $announcements->pluck('id'))
            ->pluck('announcement_id')->all();

        $cutoff = now()->subDays(Announcement::NEW_DAYS);

        return $announcements->map(function (Announcement $a) use ($readIds, $cutoff) {
            $isNew = $a->published_at && $a->published_at->gte($cutoff) && ! in_array($a->id, $readIds, true);

            return [
                'id'           => $a->id,
                'title'        => $a->title,
                'body'         => $a->body,
                'published_at' => optional($a->published_at)->format('d M Y'),
                'creator_name' => $a->creator?->name ?? '—',
                'is_new'       => $isNew,
            ];
        })->values();
    }

    /** Tandai pengumuman (published) sebagai dibaca oleh user; idempotent. */
    public function markSeen(User $user, array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $valid = Announcement::where('status', 'published')->whereIn('id', $ids)->pluck('id');

        foreach ($valid as $id) {
            AnnouncementRead::firstOrCreate(
                ['announcement_id' => $id, 'user_id' => $user->id],
                ['read_at' => now()],
            );
        }
    }

    /** Set status published; published_at di-set SEKALI (tidak reset bila sudah pernah). */
    public function publish(Announcement $a): void
    {
        $a->update([
            'status'       => 'published',
            'published_at' => $a->published_at ?? now(),
        ]);
    }

    public function archive(Announcement $a): void
    {
        $a->update(['status' => 'archived']);
    }
}
