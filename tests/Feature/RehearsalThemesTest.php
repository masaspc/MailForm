<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\User;
use App\Services\RehearsalThemes;
use App\Services\ThemeAssets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class RehearsalThemesTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_imported_themes_and_current_files_survive_a_retry_and_incomplete_snapshot(): void
    {
        Storage::fake('local');
        $warnings = [];
        $style = ThemeAssets::store(ThemeAssets::prepare('theme.css', '.banner { color: blue; }', [], $warnings));
        $stale = ThemeAssets::store(ThemeAssets::prepare('unused.css', 'p { color: red; }', [], $warnings));
        $name = 'office';
        $theme = new Theme(['slug' => 'office', 'name' => '修正版', 'layout' => 'gray', 'assets' => ['theme.css' => $style], 'header' => '<p>現行社名</p>']);
        $theme->forceFill(['source_key' => hash('sha256', $name), 'import_report' => ['source_name' => $name, 'fingerprint' => hash('sha256', 'backup')],
            'reviewed_at' => '2026-11-01 12:34:56'])->save();
        Theme::create(['slug' => 'practice', 'name' => '個人用', 'layout' => 'gray', 'assets' => ['unused.css' => $stale]]);
        User::factory()->create(['email' => 'rehearsal@example.test']);
        $bundle = fopen('php://temp', 'w+b');
        $this->assertSame(1, RehearsalThemes::export($bundle));
        rewind($bundle);
        $raw = stream_get_contents($bundle);
        $this->assertStringNotContainsString('rehearsal@example.test', $raw);
        $this->assertStringNotContainsString('practice', $raw);
        $this->assertStringNotContainsString($stale['hash'], $raw);
        DB::table('themes')->delete();
        DB::table('users')->delete();
        $disk = Storage::fake('local');
        $broken = fopen('php://temp', 'w+b');
        fwrite($broken, substr($raw, 0, -8));
        rewind($broken);
        try {
            RehearsalThemes::restore($broken);
            $this->fail('An incomplete snapshot must roll back all theme rows.');
        } catch (RuntimeException) {
            $this->assertDatabaseEmpty('themes');
        }
        foreach ([1, 2] as $attempt) {
            rewind($bundle);
            $this->assertSame(1, RehearsalThemes::restore($bundle));
            $copy = Theme::sole();
            $this->assertSame('<p>現行社名</p>', $copy->header);
            $this->assertSame('2026-11-01 12:34:56', $copy->reviewed_at->format('Y-m-d H:i:s'));
            $this->assertSame($style, $copy->assets['theme.css']);
            $this->assertTrue(ThemeAssets::intact($style));
            $this->assertCount(1, $disk->allFiles());
            $this->assertDatabaseEmpty('users');
            $this->assertDatabaseEmpty('forms');
            $this->assertDatabaseEmpty('submissions');
        }
    }
}
