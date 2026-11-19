<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Services\EasyMail\InstallationAudit;
use App\Services\PublicFormUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicFormUrlTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://forms.example.test';
    private const BASE = self::HOST.'/support';

    private function source(): \Illuminate\Database\Connection
    {
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $connection = DB::connection('legacy');
        $connection->getPdo()->exec(file_get_contents(base_path('tests/Fixtures/easymail-2.01.004.sql')));

        return $connection;
    }

    private function inventory(array $options = []): array
    {
        $code = Artisan::call('easymail:import', [
            '--source-version' => '2.01.004', '--source-base' => self::BASE.'/', '--json' => true,
            ...$options,
        ]);
        $output = Artisan::output();
        $this->assertSame(0, $code, $output);

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR)['forms'][0];
    }

    private function mount(string $script = '/support/index.php'): void
    {
        config(['app.url' => self::BASE]);
        $this->withServerVariables(['SCRIPT_NAME' => $script, 'PHP_SELF' => $script, 'SCRIPT_FILENAME' => public_path('index.php')]);
    }

    public function test_imported_encoded_url_opens_and_completes_under_the_original_subdirectory(): void
    {
        $this->mount();
        $slug = '春 申込+50%~.html';
        $this->source()->table('forms')->update(['url' => $slug]);
        $row = $this->inventory();
        $path = '/support/'.rawurlencode($slug);
        $this->assertSame($slug, $row['source_url']);
        $this->assertSame(self::HOST.$path, $row['source_entry_url']);
        $this->assertSame($row['source_entry_url'], $row['target_entry_url']);
        $this->assertTrue($row['same_public_url']);

        $entry = $this->get(self::HOST.$path.'/?utm_source=qr&url=elsewhere&next=https%3A%2F%2Fother.example')
            ->assertOk()->assertSee('action="'.$path.'/confirm"', false)
            ->assertSee('href="/support/assets/form.css"', false)->assertSee('src="/support/assets/form.js"', false);
        $this->assertSame(1, preg_match('/name="receipt_token" value="([^"]+)"/', $entry->getContent(), $match));
        $data = ['receipt_token' => $match[1], 'email' => 'test@example.test'];
        $this->post(self::HOST.$path.'/send', ['email' => 'old-form@example.test'])->assertStatus(419);
        $this->post(self::HOST.$path.'/confirm/', $data)->assertOk()
            ->assertSee('action="'.$path.'/send"', false)->assertSee('formaction="'.$path.'/back"', false);
        $this->post(self::HOST.$path.'/back', $data)->assertOk()->assertSee('test@example.test');
        $this->post(self::HOST.$path, $data)->assertOk();
        $this->post(self::HOST.$path.'/send', $data)->assertStatus(303)->assertHeader('Location', $path.'/thanks');
        $this->get(self::HOST.$path.'/thanks')->assertOk()->assertSee('test@example.test 様');
        $this->assertDatabaseCount('deliveries', 2);
    }

    public function test_root_front_controller_and_prefix_stripping_proxy_use_the_external_mount(): void
    {
        $this->mount();
        $this->source();
        $row = $this->inventory();
        $this->assertSame(self::BASE.'/', $row['source_entry_url']);
        $this->get(self::BASE.'/?url=unknown')->assertOk()->assertSee('action="/support/confirm"', false);
        $this->get(self::BASE.'/index.php/?ref=qr')->assertOk()->assertSee('action="/support/index.php/confirm"', false)
            ->assertSee('href="/support/assets/themes/stock.css"', false);
        $this->post(self::BASE.'/index.php/send', ['_token' => 'old'])->assertStatus(419);
        $this->mount('/index.php');
        $this->get(self::HOST.'/')->assertOk()->assertSee('action="/support/confirm"', false)
            ->assertSee('src="/support/assets/form.js"', false);
    }

    public function test_percent_sequences_in_the_database_are_literal_and_url_reserved_punctuation_round_trips(): void
    {
        $this->mount();
        $slug = 'A%2FB#1?x=y&z';
        $this->source()->table('forms')->update(['url' => $slug]);
        $row = $this->inventory();
        $this->assertSame(self::BASE.'/A%252FB%231%3Fx%3Dy%26z', $row['source_entry_url']);
        $this->get($row['source_entry_url'])->assertOk()->assertSee('action="/support/A%252FB%231%3Fx%3Dy%26z/confirm"', false);
        $this->get(self::BASE.'/A%2FB')->assertNotFound();
    }

    public function test_reserved_collisions_and_non_segments_have_no_published_target_and_staging_urls_are_distinct(): void
    {
        config(['app.url' => 'https://preview.example.test/new']);
        $legacy = $this->source();
        foreach (['AdMiN', 'up', 'send', 'ASSETS', 'robots.txt', 'favicon.ico', 'index.php', '.htaccess', '..', 'a/b', 'a\\b', "bad\npath"] as $slug) {
            $legacy->table('forms')->update(['url' => $slug]);
            $row = $this->inventory(['--dry-run' => true]);
            $this->assertTrue($row['source_published']);
            $this->assertFalse($row['published'], $slug);
            $this->assertNull($row['target_entry_url']);
            $this->assertNotEmpty($row['issues']);
            $this->assertNotNull(PublicFormUrl::problem($slug));
        }
        $legacy->table('forms')->update(['url' => 'contact.v2']);
        $row = $this->inventory(['--dry-run' => true]);
        $this->assertFalse($row['same_public_url']);
        $this->assertSame('https://preview.example.test/new/contact.v2', $row['target_entry_url']);
        $this->assertSame(0, Form::count());

        foreach (['/support', self::BASE.'?id=2', 'https://user:secret@example.test/'] as $base) {
            $this->assertSame(1, Artisan::call('easymail:import', ['--source-base' => $base, '--source-version' => '2.01.004']));
        }
        $this->assertSame(0, Form::count());
    }

    public function test_a_reserved_form_cannot_take_another_published_forms_old_url_for_its_placeholder(): void
    {
        $legacy = $this->source();
        $legacy->table('forms')->update(['url' => 'up']);
        $legacy->table('forms')->insert([...((array) $legacy->table('forms')->first()), 'id' => 6, 'url' => 'LEGACY-DRAFT-5']);
        $row = $this->inventory(['--dry-run' => true, '--form' => '5']);
        $this->assertFalse($row['published']);
        $this->assertNotSame('/legacy-draft-5', strtolower($row['url']));
        $this->assertSame(self::BASE.'/up', $row['source_entry_url']);
    }

    public function test_verification_lists_the_current_destination_and_handles_a_unicode_installation_directory(): void
    {
        $base = self::HOST.'/日本の 受付';
        $encodedBase = self::HOST.'/'.rawurlencode('日本の 受付');
        config(['app.url' => $base]);
        $this->source()->table('forms')->update(['url' => 'contact.v2']);
        $expected = $this->inventory(['--source-base' => $encodedBase]);
        $this->assertSame($encodedBase.'/contact.v2', $expected['target_entry_url']);
        $this->assertTrue($expected['same_public_url']);
        Form::sole()->update(['slug' => '別の入口']);
        $options = ['--source-version' => '2.01.004', '--source-base' => $base, '--verify-only' => true, '--json' => true];
        $this->assertSame(2, Artisan::call('easymail:import', $options));
        $actual = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['forms'][0];
        $this->assertSame($encodedBase.'/'.rawurlencode('別の入口'), $actual['target_entry_url']);
        $this->assertFalse($actual['same_public_url']);
        Form::sole()->update(['published' => false]);
        $this->assertSame(2, Artisan::call('easymail:import', $options));
        $this->assertNull(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['forms'][0]['target_entry_url']);
    }

    public function test_dotted_permalink_with_a_preempting_legacy_view_is_recognized(): void
    {
        $disk = Storage::fake('local');
        $path = 'resources/views/theme/season/open.html';
        $disk->put('source/'.$path, '旧ページ');
        $audit = new InstallationAudit(realpath($disk->path('source')), '2.01.004');
        $this->assertContains('URL別テンプレート: '.$path, $audit->forForm('gray', 'season.open', 'ja'));
        $this->assertNull(PublicFormUrl::problem(str_repeat('a', 255)));
        $this->assertNotNull(PublicFormUrl::problem(str_repeat('a', 256)));
    }
}
