<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Theme;
use App\Models\User;
use App\Services\Admin\FormEditor;
use App\Services\SafeMarkup;
use App\Services\ThemeAssets;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ThemeManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => $role.'@example.test', 'role' => $role, 'password' => 'example-password']);
    }

    private function form(string $slug = 'contact', string $theme = 'gray', bool $published = false): Form
    {
        return Form::create([
            'legacy_id' => Form::count() + 1, 'slug' => $slug, 'name' => '資料請求', 'published' => $published, 'revision' => 1,
            'definition' => [
                'issues' => [], 'fields' => [['id' => 1, 'title' => 'お名前', 'name' => 'name', 'form_type' => 'text']],
                'settings' => ['theme_name' => $theme, 'name' => '資料請求', 'url' => $slug, 'view_flag' => 1, 'admin_email' => 'staff@example.test'],
            ],
        ]);
    }

    private function importedTheme(array $attributes = []): Theme
    {
        $theme = new Theme();
        $theme->forceFill(array_replace([
            'name' => 'オフィス', 'slug' => 'office', 'layout' => 'gray', 'assets' => [],
            'import_report' => [
                'layout' => 'gray', 'score' => 82,
                'changes' => ['changed' => ['js/office.js'], 'added' => ['footer.blade.php'], 'removed' => ['css/old.css']],
                'behaviors' => ['js/office.js の画面遷移を確認'], 'warnings' => ['バナーの余白を照合してください。'],
                'stylesheets' => ['css/base.css', 'css/local.css'],
            ],
        ], $attributes))->save();
        return $theme->refresh();
    }

    public function test_preview_renders_the_saved_draft_without_issuing_a_submission_token(): void
    {
        $form = $this->form(published: true);
        $draft = $form->definition;
        $draft['settings']['name'] = '登録前プレビュー';
        $draft['fields'][0]['title'] = '編集後のお名前';
        $form->update(['draft_definition' => $draft]);
        $url = "/admin/forms/{$form->id}/preview";

        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($this->user('viewer'))->get($url)->assertForbidden();
        $this->actingAs($this->user('editor'))->get($url)->assertOk()->assertSee('登録前プレビュー')->assertSee('編集後のお名前')
            ->assertSee('name="name"', false)->assertDontSee('name="receipt_token"', false)->assertDontSee('/contact/confirm')
            ->assertSessionMissing('public_drafts');
        $this->post($url)->assertMethodNotAllowed();
        $this->post('/contact/confirm', ['name' => '見本'])->assertStatus(419);
        $this->assertDatabaseEmpty('submissions');
        $this->get('/contact')->assertOk()->assertDontSee('編集後のお名前')->assertSee('name="receipt_token"', false);
    }

    public function test_theme_images_are_only_rendered_for_confirmed_files_in_the_current_theme(): void
    {
        $theme = new Theme(['slug' => 'office']);
        $theme->assets = [
            'images/logo.png' => ['mime' => 'image/png', 'hash' => str_repeat('a', 64)],
            'css/main.css' => ['mime' => 'text/css', 'hash' => str_repeat('b', 64)],
        ];
        $markup = '<img src="theme:images/logo.png" alt="総務 &amp; 業務">';
        $this->assertStringContainsString('<img src="/_theme/office/file/images/logo.png" alt="総務 &amp; 業務">', SafeMarkup::render($markup, $theme)->toHtml());
        $this->assertFalse(SafeMarkup::hasUnsupported($markup, $theme));
        $this->assertTrue(SafeMarkup::hasUnsupported($markup));
        $this->assertStringNotContainsString('<img', SafeMarkup::render($markup)->toHtml());
        foreach (['theme:images/other.png', 'theme:css/main.css', 'https://example.test/logo.png', 'theme:../logo.png'] as $src) {
            $image = '<img src="'.$src.'" alt="">';
            $this->assertTrue(SafeMarkup::hasUnsupported($image, $theme));
            $this->assertStringNotContainsString('<img', SafeMarkup::render($image, $theme)->toHtml());
        }
        $unknownAttribute = '<img src="theme:images/logo.png" alt="ロゴ" width="20">';
        $this->assertTrue(SafeMarkup::hasUnsupported($unknownAttribute, $theme));
        $this->assertStringNotContainsString('width=', SafeMarkup::render($unknownAttribute, $theme)->toHtml());
    }

    public function test_theme_formatting_preserves_static_classes_and_headings_without_activating_form_behaviour(): void
    {
        $theme = $this->importedTheme();
        $heading = '<h2 class="header__siteTitle office-brand">資料窓口</h2>';
        $this->assertFalse(SafeMarkup::hasUnsupported($heading, $theme));
        $this->assertSame($heading, SafeMarkup::render($heading, $theme)->toHtml());
        $this->assertTrue(SafeMarkup::hasUnsupported($heading));
        $this->assertStringNotContainsString('header__siteTitle', SafeMarkup::render($heading)->toHtml());
        $attribute = '<nav class="header__gnavi" data-entry data-lookup="postal">案内</nav>';
        $this->assertTrue(SafeMarkup::hasUnsupported($attribute, $theme));
        $this->assertSame('<nav class="header__gnavi">案内</nav>', SafeMarkup::render($attribute, $theme)->toHtml());
    }

    #[TestWith(['gradation', '//*[@id="wrapper" and contains(@class,"formPage")]//*[@id="container"]', '//*[@id="footer"]'])]
    #[TestWith(['two_column02', '//main[contains(@class,"content")]//*[contains(@class,"rightColumn")]//*[contains(@class,"formWrap")]', '//header[contains(@class,"header")]//h1[contains(@class,"header__siteTitle")]'])]
    #[TestWith(['one_column', '//main[contains(@class,"content")]//*[contains(@class,"contentHead")]//*[contains(@class,"contentHead__ttl")]', '//footer[contains(@class,"footer")]'])]
    public function test_preview_and_published_layout_expose_the_legacy_stylesheet_selectors(string $layout, string $body, string $edge): void
    {
        $theme = $this->importedTheme(['layout' => $layout, 'header' => '<h1 class="header__siteTitle">資料窓口</h1>']);
        $form = $this->form(theme: $theme->slug);
        $form->update(['awaiting_theme_publication' => true]);
        $this->actingAs($this->user('admin'));
        $preview = $this->get("/admin/forms/{$form->id}/preview")->assertOk();
        $this->post('/admin/themes/office/approve', ['confirmed' => 1, 'version' => $theme->editorVersion()])->assertSessionHasNoErrors();
        foreach ([$preview, $this->get('/contact')->assertOk()] as $page) {
            $doc = new DOMDocument();
            $doc->loadHTML($page->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new DOMXPath($doc);
            $this->assertCount(1, $xpath->query($body));
            $this->assertCount(1, $xpath->query($edge));
        }
    }

    public function test_existing_public_routes_and_manual_publish_do_not_expose_an_unreviewed_theme(): void
    {
        $theme = $this->importedTheme();
        $form = $this->form(theme: $theme->slug, published: true);
        $this->get('/contact')->assertNotFound();
        $this->post('/contact/confirm')->assertNotFound();
        $this->post('/contact/send')->assertNotFound();
        $form->update(['published' => false]);

        $adminPreview = "/admin/forms/{$form->id}/preview";
        $this->actingAs($this->user('editor'))->get($adminPreview)->assertForbidden();
        $edit = route('admin.edit', $form);
        $version = $this->get($edit)->assertOk()->viewData('version');
        $this->post(route('admin.publish', $form), ['version' => $version])
            ->assertRedirect($edit)->assertSessionHasErrors('definition');
        $this->assertFalse($form->refresh()->published);
        $this->actingAs($this->user('admin'))->get($adminPreview)->assertOk()->assertSee('お名前');
        $this->get('/admin')->assertSee($adminPreview);
        $this->get(route('admin.edit', $form))->assertSee($adminPreview);
    }

    public function test_admin_can_inspect_the_theme_diff_and_approve_only_the_expected_form_version(): void
    {
        $theme = $this->importedTheme();
        $form = $this->form(theme: $theme->slug);
        $form->update(['awaiting_theme_publication' => true]);
        $private = $this->form('private', 'office');
        $definition = $private->definition;
        $definition['settings']['view_flag'] = 0;
        $private->update(['definition' => $definition]);
        $url = '/admin/themes/office/approve';
        $version = $theme->editorVersion();
        $this->actingAs($this->user('editor'))->post($url, ['version' => $version, 'confirmed' => 1])->assertForbidden();
        $this->actingAs($this->user('admin'))->get('/admin/themes/office/edit')->assertOk()
            ->assertSee('js/office.js')->assertSee('footer.blade.php')->assertSee('css/old.css')->assertSee('画面遷移を確認')
            ->assertSee('余白を照合')->assertSee("/admin/forms/{$form->id}/preview");
        $this->post($url, ['version' => str_repeat('a', 64), 'confirmed' => 1])->assertConflict();
        $this->post($url, ['version' => $version])->assertSessionHasErrors('confirmed');
        $this->get('/contact')->assertNotFound();
        $this->post($url, ['version' => $version, 'confirmed' => 1])->assertRedirect('/admin/themes/office/edit');
        $this->get('/contact')->assertOk();
        $this->get('/private')->assertNotFound();
        $this->assertFalse($theme->refresh()->pendingReview());
        $this->post(route('admin.unpublish', $form), ['version' => app(FormEditor::class)->version($form->refresh())])->assertSessionHasNoErrors();
        $this->post($url, ['version' => $theme->editorVersion(), 'confirmed' => 1])->assertRedirect();
        $this->assertFalse($form->refresh()->published);
    }

    public function test_layout_uses_the_selected_logo_once_and_preserves_stylesheet_order_in_a_subdirectory(): void
    {
        config(['app.url' => 'https://example.test/team/index.php']);
        $image = ['mime' => 'image/png', 'hash' => str_repeat('a', 64)];
        $css = ['mime' => 'text/css', 'hash' => str_repeat('b', 64)];
        $theme = $this->importedTheme([
            'assets' => ['css/local.css' => $css, 'images/logo.png' => $image, 'css/base.css' => $css, 'images/new.png' => $image],
            'reviewed_at' => now(), 'header' => '<p><img src="theme:images/logo.png" alt="部署"> 相談窓口</p>',
            'footer' => '<p>受付時間</p>', 'logo' => 'images/logo.png',
        ]);
        $form = $this->form(theme: $theme->slug, published: true);
        $prefix = '/team/index.php/_theme/office/file/';
        $html = $this->get('/contact')->assertOk()->assertSeeInOrder([$prefix.'css/base.css', $prefix.'css/local.css'])
            ->assertSee('受付時間')->getContent();
        $this->assertSame(1, substr_count($html, 'src="'.$prefix.'images/logo.png"'));
        $this->actingAs($this->user('admin'));
        $data = ['slug' => 'office', 'name' => 'オフィス', 'layout' => 'gray', 'header' => $theme->header, 'footer' => $theme->footer, 'version' => $theme->editorVersion()];
        $this->post('/admin/themes', $data + ['logo' => 'css/base.css'])->assertSessionHasErrors('logo');
        $this->post('/admin/themes', $data + ['logo' => 'images/logo.png'])->assertSessionHasNoErrors();
        $this->assertFalse($theme->refresh()->pendingReview());
        $this->post('/admin/themes', $data + ['logo' => 'images/new.png'])->assertSessionHasNoErrors();
        $this->assertTrue($theme->refresh()->pendingReview());
        $this->assertFalse($form->refresh()->published);
        $html = $this->get("/admin/forms/{$form->id}/preview")->assertOk()->assertDontSee($prefix.'images/logo.png')
            ->assertSee('相談窓口')->getContent();
        $this->assertSame(1, substr_count($html, 'src="'.$prefix.'images/new.png"'));
        $this->post('/admin/themes', $data + ['logo' => 'images/logo.png'])->assertConflict();
    }

    public function test_admin_edits_individual_image_and_css_files_with_validation_and_stale_edit_protection(): void
    {
        Storage::fake('local');
        $theme = $this->importedTheme(['reviewed_at' => now()]);
        $this->form(theme: $theme->slug, published: true);
        $admin = $this->user('admin');
        $url = '/admin/themes/office/assets';
        $v = $theme->editorVersion();
        $request = ['path' => 'css/local.css', 'version' => $v, 'css' => '.site-banner { color: navy; }'];
        $this->actingAs($this->user('editor'))->post($url, $request)->assertForbidden();
        $this->actingAs($admin)->post($url, $request)->assertSessionHasNoErrors()->assertRedirect();
        $theme->refresh();
        $this->assertTrue($theme->pendingReview());
        $this->assertStringContainsString('navy', ThemeAssets::read($theme->assets['css/local.css']));
        $this->get('/admin/themes/office/edit?asset=css/local.css')->assertOk()->assertSee('navy');
        $this->post($url, $request)->assertConflict();
        $version = $theme->editorVersion();
        $this->post($url, ['version' => $version, 'path' => 'css/local.css', 'css' => '@import "other.css";'])->assertSessionHasErrors('css');
        $this->post($url, ['version' => $version, 'path' => '../local.css', 'css' => 'p { color: red; }'])->assertSessionHasErrors('path');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><circle cx="10" cy="10" r="8" fill="#444"/></svg>';
        $this->post($url, ['version' => $version, 'path' => 'images/mark.svg', 'file' => UploadedFile::fake()->createWithContent('mark.svg', $svg)])->assertSessionHasNoErrors();
        $theme->refresh();
        $first = $theme->assets['images/mark.svg'];
        $this->post($url, ['version' => $theme->editorVersion(), 'path' => 'images/mark.svg', 'file' => UploadedFile::fake()->createWithContent('mark.svg', 'broken image')])
            ->assertSessionHasErrors('file');
        $this->assertEquals($first, $theme->fresh()->assets['images/mark.svg']);
        $replacement = str_replace('#444', '#222', $svg);
        $this->post($url, ['version' => $theme->editorVersion(), 'path' => 'images/mark.svg', 'file' => UploadedFile::fake()->createWithContent('new.svg', $replacement)])->assertSessionHasNoErrors();
        $theme->refresh();
        $this->assertStringContainsString('#222', ThemeAssets::read($theme->assets['images/mark.svg']));
        $this->assertStringContainsString('navy', ThemeAssets::read($theme->assets['css/local.css']));
    }
}
