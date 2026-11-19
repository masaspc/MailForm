<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CustomThemeImportTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;
    private string $custom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = realpath(sys_get_temp_dir()).'/mf-theme-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->dir.'/config', 0700);
        File::ensureDirectoryExists($this->dir.'/application/resources/easymail', 0700);
        copy(resource_path('easymail/presets.json'), $this->dir.'/application/resources/easymail/presets.json');
        $this->custom = $this->dir.'/resources/views/theme/garden';
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $legacy = DB::connection('legacy');
        $legacy->getPdo()->exec(file_get_contents(base_path('tests/Fixtures/easymail-2.01.004.sql')));
        $legacy->table('forms')->update(['theme_name' => 'garden', 'url' => 'contact']);
        $legacy->table('forms')->insert([...(array) $legacy->table('forms')->first(), 'id' => 6, 'url' => 'private', 'view_flag' => 0]);
        foreach ($legacy->table('form_items')->where('form_id', 5)->get() as $item) {
            $legacy->table('form_items')->insert([...(array) $item, 'form_id' => '6', 'id' => $item->id + 200]);
        }
        Storage::fake('local');
        Artisan::all();
        $this->app->setBasePath($this->dir.'/application');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function backup(string $version): void
    {
        $config = "<?php return ['version' => env('APP_VERSION', '$version')];";
        file_put_contents($this->dir.'/config/app.php', $config);
        $manifest = ['config/app.php' => hash('sha256', $config)];
        foreach (['gray', 'gradation', 'one_column', 'two_column01', 'two_column02'] as $slug) {
            $dir = 'resources/views/theme/'.$slug;
            foreach (['css/style.css' => '.note{color:navy}', 'form.blade.php' => 'input '.$slug, 'index.blade.php' => 'start '.$slug,
                'header.blade.php' => '<p>Header</p>', 'js/scripts.js' => '// stock'] as $file => $content) {
                File::ensureDirectoryExists(dirname($this->dir.'/'.$dir.'/'.$file));
                file_put_contents($this->dir.'/'.$dir.'/'.$file, $content);
                $manifest[$dir.'/'.$file] = hash('sha256', $content);
            }
        }
        File::copyDirectory($this->dir.'/resources/views/theme/gradation', $this->custom);
        file_put_contents($this->custom.'/css/style.css', '.note{color:green}');
        file_put_contents($this->custom.'/header.blade.php', '<p>Garden desk</p>');
        file_put_contents($this->custom.'/js/scripts.js', '// synthetic customization');
        file_put_contents($this->dir.'/application/resources/easymail/'.$version.'-sha256.json', json_encode($manifest));
    }

    private function runImport(array $options = [], int $expected = 0): array
    {
        $code = Artisan::call('easymail:import', ['--source-root' => $this->dir, '--source-base' => config('app.url'), '--json' => true, '--strict' => true, ...$options]);
        $output = Artisan::output();
        $this->assertSame($expected, $code, $output);
        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    #[TestWith(['2.00.124'])]
    #[TestWith(['2.00.125'])]
    #[TestWith(['2.00.133'])]
    #[TestWith(['2.01.004'])]
    public function test_strict_import_leaves_only_reviewed_source_public_forms_at_their_original_urls(string $version): void
    {
        $this->backup($version);
        $report = $this->runImport(['--dry-run' => true]);
        $this->assertSame('gradation', $report['themes']['garden']['layout']);
        $this->assertSame('pending', $report['forms'][0]['theme_review']);
        $this->assertDatabaseEmpty('themes');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->runImport();
        $this->assertFalse(Form::where('slug', 'contact')->firstOrFail()->published);
        $this->assertDatabaseCount('submissions', 1);
        $theme = Theme::sole();
        $this->assertContains('js/scripts.js', $theme->import_report['changes']['changed']);
        $this->assertTrue($theme->pendingReview());
        $this->assertSame(1, $theme->approveForPublication());
        $this->runImport();
        $verification = $this->runImport(['--verify-only' => true]);
        $this->assertTrue($verification['forms'][0]['same_public_url']);
        $this->assertFalse(Form::where('slug', 'private')->firstOrFail()->published);
        $this->assertTrue($verification['reconciliation']['ok']);
        $this->assertDatabaseEmpty('deliveries');
    }

    public function test_reimport_keeps_manual_edits_and_refreshes_untouched_assets_unless_overwrite_is_requested(): void
    {
        $this->backup('2.00.125');
        $this->runImport();
        $theme = Theme::sole();
        $theme->update(['header' => '<p>Local edit</p>']);
        $theme->approveForPublication();
        $original = $theme->fresh()->assets['css/style.css']['hash'];
        file_put_contents($this->custom.'/header.blade.php', '<p>Later copy</p>');
        file_put_contents($this->custom.'/css/style.css', '.note{color:blue}');
        $this->runImport();
        $theme->refresh();
        $this->assertSame('<p>Local edit</p>', $theme->header);
        $this->assertNotSame($original, $theme->assets['css/style.css']['hash']);
        $this->assertTrue($theme->pendingReview());
        $this->assertFalse(Form::where('slug', 'contact')->firstOrFail()->published);
        $this->runImport(['--overwrite-themes' => true]);
        $this->assertStringContainsString('Later copy', $theme->fresh()->header);
    }

    public function test_removing_a_stock_image_in_the_next_backup_preserves_an_edited_stylesheets_image(): void
    {
        $this->backup('2.00.125');
        File::ensureDirectoryExists($this->custom.'/images');
        file_put_contents($this->custom.'/images/leaves.svg', '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="4" cy="4" r="4"/></svg>');
        $this->runImport();
        $theme = Theme::sole();
        $files = $theme->assets;
        $warnings = [];
        $css = \App\Services\ThemeAssets::prepare('css/style.css', '.site-banner{background-image:url(../images/leaves.svg)}', $files, $warnings);
        $files['css/style.css'] = \App\Services\ThemeAssets::store($css);
        $theme->update(['assets' => $files, 'name' => 'Garden 受付']);
        unlink($this->custom.'/images/leaves.svg');
        $this->runImport();
        $this->assertArrayHasKey('images/leaves.svg', $theme->fresh()->assets);
        $this->assertSame('Garden 受付', $theme->fresh()->name);
        $this->runImport(['--overwrite-themes' => true]);
        $this->assertArrayNotHasKey('images/leaves.svg', $theme->fresh()->assets);
        $this->assertSame('garden', $theme->fresh()->name);
    }

    public function test_import_preserves_a_locally_created_theme_with_the_same_name(): void
    {
        $this->backup('2.00.133');
        $manual = Theme::create(['slug' => 'garden', 'name' => '既存のテーマ', 'layout' => 'gray', 'header' => '<p>Original</p>']);
        $expected = $this->runImport(['--dry-run' => true])['themes']['garden']['slug'];
        $this->runImport(['--overwrite-themes' => true]);
        $this->assertNotSame($manual->slug, $expected);
        $this->assertSame($expected, Form::where('slug', 'contact')->sole()->definition['settings']['theme_name']);
        $this->assertSame('<p>Original</p>', $manual->fresh()->header);
        $this->assertSame($expected, $this->runImport()['themes']['garden']['slug']);
        $this->assertDatabaseCount('themes', 2);
    }

    public function test_review_cannot_release_a_missing_blob_or_a_form_withdrawn_from_the_final_backup(): void
    {
        $this->backup('2.01.004');
        $this->runImport();
        $theme = Theme::sole();
        $asset = $theme->assets['css/style.css'];
        Storage::disk('local')->delete(\App\Services\ThemeAssets::storagePath($asset['hash']));
        $report = $this->runImport(['--verify-only' => true], 2);
        $this->assertFalse($report['reconciliation']['ok']);
        $this->assertStringContainsString('css/style.css', implode(' ', $report['themes']['garden']['integrity']));
        try {
            $theme->approveForPublication();
            $this->fail('Missing theme files should prevent approval.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('assets', $error->errors());
        }
        $this->assertFalse(Form::where('slug', 'contact')->sole()->published);
        DB::connection('legacy')->table('histories')->delete();
        DB::connection('legacy')->table('forms')->where('id', 5)->delete();
        $this->runImport([], 2); // Removed legacy forms have their own strict warning.
        $this->assertSame(0, $theme->approveForPublication());
        $this->assertFalse(Form::where('slug', 'contact')->sole()->published);
    }

    public function test_missing_evidence_blocks_only_affected_form_and_real_warnings_are_still_strict(): void
    {
        $this->backup('2.00.125');
        File::deleteDirectory($this->custom);
        File::ensureDirectoryExists($this->custom);
        file_put_contents($this->custom.'/alone.js', '// no stock match');
        $report = $this->runImport(['--dry-run' => true], 2);
        $this->assertNotEmpty($report['forms'][0]['issues']);
        $this->assertDatabaseEmpty('themes');
        $this->assertFalse($report['forms'][0]['published']);
    }
}
