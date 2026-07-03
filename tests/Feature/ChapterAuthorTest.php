<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Author;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class ChapterAuthorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function book(): Title
    {
        $book = Title::create(['title' => 'Buku Bab Author', 'jenis' => 'buku', 'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui']);
        $book->chapters()->create(['judul' => 'Bab 1', 'urutan' => 1]);
        return $book;
    }

    /** @test */
    public function manager_saves_chapter_authors(): void
    {
        $book = $this->book();
        $ch = $book->chapters()->first();
        $author = Author::create(['name' => 'Dr. Contoh']);

        $this->actingAs($this->user('manager'))->put(route('title.chapters.authors', $book->id), [
            'chapter_authors' => [$ch->id => [(string) $author->id, 'Penulis Baru']],
        ])->assertRedirect();

        $this->assertSame(2, $ch->authors()->count());
        $this->assertTrue($ch->authors()->where('tb_authors.id', $author->id)->exists());
    }

    /** @test */
    public function marketing_cannot_save_chapter_authors(): void
    {
        $book = $this->book();
        $this->actingAs($this->user('marketing'))
            ->put(route('title.chapters.authors', $book->id), ['chapter_authors' => []])
            ->assertForbidden();
    }

    /** @test */
    public function show_displays_chapter_authors(): void
    {
        $book = $this->book();
        $ch = $book->chapters()->first();
        $author = Author::create(['name' => 'Nama Tercantum']);
        $ch->authors()->attach($author->id, ['position' => 1]);

        $this->actingAs($this->user('manager'))->get(route('title.show', $book->id))
            ->assertOk()->assertSee('Nama Tercantum');
    }
}
