<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Theme;
use App\Models\User;
use App\Services\EasyMail\ImportedThemes;
use App\Services\RehearsalThemes;
use App\Services\ThemeStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ThemeStructureEditingTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'paper-kite';

    private function sync(array $structure, string $snapshot = 'first', bool $dry = false, bool $verify = false, bool $overwrite = false): array
    {
        $scan = [
            'layout' => 'gray', 'score' => 2, 'changes' => ['added' => [], 'changed' => [], 'removed' => []],
            'warnings' => [], 'behaviors' => [], 'errors' => [], 'integrity' => [], 'assets' => [], 'stylesheets' => [],
            'fingerprint' => hash('sha256', $snapshot), 'structure' => $structure,
            'structure_sources' => array_fill_keys(array_keys($structure), 'panels.blade.php:12'),
        ];
        return app(ImportedThemes::class)->sync(self::SLUG, '2.00.125', $scan, $dry || $verify, $verify, $overwrite, false);
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function saveComponent(string $slot, string $mode, array $data = [])
    {
        return $this->post(route('admin.themes.component', self::SLUG), $data + [
            'version' => Theme::sole()->editorVersion(), 'slot' => $slot, 'mode' => $mode,
        ]);
    }

    public function test_an_admin_can_edit_each_layer_and_address_order_with_review_and_version_checks(): void
    {
        $source = ['title' => [['tag' => 'h2', 'class' => 'kite-heading']], 'text' => [['tag' => 'input']]];
        $report = $this->sync($source, dry: true);
        $this->assertDatabaseEmpty('themes');
        $this->assertSame($source, $report['structure']);
        $this->assertContains('address_order', $report['structure_fallbacks']);
        $this->assertArrayHasKey('title', $report['structure_sources']);
        $this->sync($source);
        Theme::sole()->approveForPublication();
        $form = Form::create(['legacy_id' => 9, 'slug' => 'kite-office', 'name' => '凧の見学', 'published' => true,
            'definition' => ['settings' => ['theme_name' => self::SLUG, 'view_flag' => 1]]]);
        $url = route('admin.themes.edit', ['slug' => self::SLUG, 'component' => 'title']);
        $initialVersion = Theme::sole()->editorVersion();
        $edit = ['element' => ['tag' => 'h3', 'class' => 'kite-heading quiet', 'id' => 'kite-title'],
            'wrappers' => [['tag' => 'div', 'position' => 2], ['tag' => 'section', 'class' => 'frame', 'position' => 1],
                ['tag' => '', 'class' => '', 'id' => '', 'position' => 3]]];
        $this->actingAs(User::factory()->create(['role' => 'editor']));
        $this->saveComponent('title', 'edit', $edit)->assertForbidden();
        $this->admin();
        $page = $this->get($url)->assertOk()->assertSee('panels.blade.php:12')->assertSee('標準を継承')
            ->assertSee('name="element[tag]"', false)->assertSee('name="wrappers[0][id]"', false)
            ->assertSee(route('admin.preview', ['form' => $form, 'version' => 'current', 'stage' => 'confirm']))
            ->assertSee(route('admin.preview', ['form' => $form, 'version' => 'current', 'stage' => 'thanks']));
        foreach (ThemeStructure::labels() as $label) {
            $page->assertSee($label);
        }
        $this->saveComponent('title', 'edit', $edit)->assertRedirect($url.'#structure')->assertSessionHasNoErrors();
        $theme = Theme::sole();
        $this->assertEquals([['tag' => 'section', 'class' => 'frame'], ['tag' => 'div'], $edit['element']], $theme->structure['title']);
        $this->assertTrue($theme->pendingReview());
        $this->assertFalse($form->refresh()->published);
        $this->assertTrue($form->awaiting_theme_publication);
        $this->get($url)->assertOk()->assertSee('手動設定')->assertSee('kite-title')->assertSee('取込時の推定に戻す');
        $this->saveComponent('title', 'standard', ['version' => $initialVersion])->assertConflict();
        $this->get(route('admin.themes.edit', ['slug' => self::SLUG, 'component' => 'address_order']))->assertOk()
            ->assertSee('value="address" selected', false)->assertDontSee('value="building" selected', false);
        $this->saveComponent('address_order', 'edit', ['order' => ['building', 'pref', '', 'zip']])->assertSessionHasNoErrors();
        $this->assertSame(['building', 'pref', 'zip'], $theme->refresh()->structure['address_order']);
        foreach (['buttons_position' => 'outside_form', 'required_position' => 'before', 'steps_visibility' => 'accessible'] as $slot => $value) {
            $this->get(route('admin.themes.edit', ['slug' => self::SLUG, 'component' => $slot]))->assertOk()->assertSee('name="option"', false);
            $this->saveComponent($slot, 'edit', ['option' => $value])->assertSessionHasNoErrors();
            $this->assertSame($value, $theme->refresh()->structure[$slot]);
        }
    }

    public function test_unusable_nodes_unknown_attributes_and_duplicate_positions_leave_the_last_approved_version_intact(): void
    {
        $this->sync([]);
        Theme::sole()->approveForPublication();
        $this->admin();
        $this->get(route('admin.themes.edit', ['slug' => self::SLUG, 'component' => 'text']))->assertOk();
        $version = Theme::sole()->editorVersion();
        foreach ([
            ['element' => ['tag' => 'text']],
            ['element' => ['tag' => 'input', 'data-extra' => 'sample']],
            ['element' => ['tag' => 'input', 'id' => ['nested']]],
            ['element' => ['tag' => 'input', 'class' => 'needs/a/fix']],
            ['element' => ['tag' => 'input'], 'wrappers' => [['tag' => 'select']]],
            ['element' => ['tag' => 'input'], 'wrappers' => [['tag' => 'p', 'position' => 1], ['tag' => 'div', 'position' => 1]]],
        ] as $invalid) {
            $this->saveComponent('text', 'edit', $invalid)->assertSessionHasErrors();
            $this->get(route('admin.themes.edit', ['slug' => self::SLUG, 'component' => 'text']))->assertOk();
        }
        $this->saveComponent('address_order', 'edit', ['order' => ['zip', 'zip']])->assertSessionHasErrors('component');
        $this->saveComponent('other', 'standard')->assertSessionHasErrors('slot');
        $this->assertSame($version, Theme::sole()->editorVersion());
        $this->assertFalse(Theme::sole()->pendingReview());
    }

    public function test_reimport_keeps_only_edited_slots_including_an_explicit_standard_and_can_restore_the_latest_source(): void
    {
        $this->sync(['title' => [['tag' => 'h2']], 'row' => [['tag' => 'section']]]);
        $this->admin();
        $local = ['tag' => 'h3', 'class' => 'kite-poster'];
        $this->saveComponent('title', 'edit', ['element' => $local])->assertSessionHasNoErrors();
        $this->saveComponent('row', 'standard')->assertSessionHasNoErrors();
        $this->sync(['title' => [$local], 'error' => [['tag' => 'small']]], 'second');
        $next = ['row' => [['tag' => 'div', 'class' => 'entry-strip']], 'title' => [['tag' => 'h4']],
            'error' => [['tag' => 'p']], 'address_order' => ['pref', 'address']];
        $before = Theme::sole()->editorVersion();
        $dry = $this->sync($next, 'third', dry: true);
        $this->assertEqualsCanonicalizing(['structure.title', 'structure.row'], $dry['preserved']);
        $this->assertNotEmpty($this->sync($next, 'third', verify: true)['integrity']);
        $this->assertSame($before, Theme::sole()->editorVersion());
        $this->sync($next, 'third');
        $theme = Theme::sole();
        $this->assertArrayNotHasKey('row', $theme->structure);
        $this->assertEquals([$local], $theme->structure['title']);
        $this->assertSame(['pref', 'address'], $theme->structure['address_order']);
        $this->assertSame('p', $theme->structure['error'][0]['tag']);
        $theme->approveForPublication();
        $approved = Theme::sole()->editorVersion();
        $this->assertSame('reviewed', $this->sync($next, 'third')['status']);
        $this->assertSame([], $this->sync($next, 'third', verify: true)['integrity']);
        $this->assertSame($approved, Theme::sole()->editorVersion());
        $this->saveComponent('row', 'source')->assertSessionHasNoErrors();
        $this->assertEquals($next['row'], $theme->refresh()->structure['row']);
        $this->assertTrue($theme->pendingReview());
        $this->assertSame(['structure.title'], $this->sync($next, 'third')['preserved']);
        $this->sync($next, 'third', overwrite: true);
        $this->assertEquals($next, $theme->refresh()->structure);
        $this->assertSame([], $theme->editedStructureSlots());
    }

    public function test_an_older_approval_survives_standard_only_inference_and_reordered_json_keys(): void
    {
        $this->sync([]);
        $theme = Theme::sole();
        $old = $theme->import_report;
        unset($old['structure'], $old['structure_sources'], $old['structure_fallbacks']);
        $theme->forceFill(['import_report' => $old, 'reviewed_at' => now()])->save();
        $version = $theme->refresh()->editorVersion();
        $this->assertSame([], $this->sync([], verify: true)['integrity']);
        $this->assertSame($version, Theme::sole()->editorVersion());
        $this->assertSame('reviewed', $this->sync([])['status']);
        $new = ['title' => [['tag' => 'h2', 'class' => 'kite-title', 'id' => 'kite-sheet']], 'row' => [['tag' => 'div']]];
        $this->assertSame('pending', $this->sync($new, dry: true)['status']);
        $this->assertNull($theme->refresh()->structure);
        $this->sync($new);
        $theme->refresh()->approveForPublication();
        $reordered = array_reverse($new, true);
        $reordered['title'][0] = array_reverse($new['title'][0], true);
        DB::table('themes')->where('slug', self::SLUG)->update(['structure' => json_encode($reordered, JSON_PRETTY_PRINT)]);
        $version = $theme->refresh()->editorVersion();
        $this->assertSame([], $this->sync($new, verify: true)['integrity']);
        $this->assertSame('reviewed', $this->sync($new)['status']);
        $this->assertSame($version, $theme->refresh()->editorVersion());
    }

    public function test_rehearsal_carries_active_structure_manual_choices_and_approval_across_retries_and_accepts_old_archives(): void
    {
        $this->sync(['title' => [['tag' => 'h2']], 'row' => [['tag' => 'section']]]);
        $this->admin();
        $this->saveComponent('title', 'edit', ['element' => ['tag' => 'h3', 'class' => 'kite-title']])->assertSessionHasNoErrors();
        $this->saveComponent('row', 'standard')->assertSessionHasNoErrors();
        $theme = Theme::sole();
        $theme->approveForPublication();
        $file = fopen('php://temp', 'w+b');
        $this->assertSame(1, RehearsalThemes::export($file));
        rewind($file);
        $header = json_decode(fgets($file), true, flags: JSON_THROW_ON_ERROR);
        $row = json_decode(fgets($file), true, flags: JSON_THROW_ON_ERROR)['theme'];
        $load = function (array $head, array $record) {
            $stream = fopen('php://temp', 'w+b');
            foreach ([$head, ['theme' => $record], ['themes' => 1]] as $line) {
                fwrite($stream, json_encode($line, JSON_THROW_ON_ERROR)."\n");
            }
            rewind($stream);
            try {
                return RehearsalThemes::restore($stream);
            } finally {
                fclose($stream);
            }
        };
        DB::table('themes')->delete();
        $this->assertSame(1, $load($header, $row));
        $copy = Theme::sole();
        $this->assertEquals($theme->structure, $copy->structure);
        $this->assertEquals(['row' => true, 'title' => true], $copy->editedStructureSlots());
        $this->assertEquals($theme->reviewed_at, $copy->reviewed_at);
        $reordered = $copy->structure;
        $reordered['title'][0] = array_reverse($reordered['title'][0], true);
        $this->assertSame(1, $load($header, array_replace($row, ['structure' => json_encode($reordered, JSON_PRETTY_PRINT)])));
        try {
            $load($header, array_replace($row, ['structure' => json_encode(['title' => [['tag' => 'textarea']]])]));
            $this->fail('Unexpected title element should fail handoff verification.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('部品設定', $error->getMessage());
        }
        DB::table('themes')->delete();
        unset($row['structure'], $row['structure_overrides']);
        $row['import_report'] = json_encode(['source_name' => self::SLUG], JSON_THROW_ON_ERROR);
        $this->assertSame(1, $load(['mailform_rehearsal_themes' => 1], $row));
        $this->assertNull(Theme::sole()->structure);
        fclose($file);
    }
}
