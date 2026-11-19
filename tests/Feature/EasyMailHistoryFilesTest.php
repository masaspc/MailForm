<?php

namespace Tests\Feature;

use App\Models\Attachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EasyMailHistoryFilesTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::connection('legacy')->getPdo()->exec(file_get_contents(base_path('tests/Fixtures/easymail-2.01.004.sql')));
        Storage::fake('local');
        $this->directory = realpath(sys_get_temp_dir()).'/easymail-attachments-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_mixed_bare_and_dotted_hash_names_survive_incomplete_snapshots_and_reimports(): void
    {
        $hashes = [
            str_repeat('a1', 20), str_repeat('b2', 20), str_repeat('c3', 20).'.tar.gz',
            str_repeat('d4', 20), str_repeat('e5', 20).'.pdf', str_repeat('f6', 20), str_repeat('g7', 20),
        ];
        $names = [...$hashes, '伝票-01.txt'];
        $this->references(implode('', $hashes).',伝票-01.txt');
        foreach ($names as $name) {
            file_put_contents($this->directory.'/'.$name, $name);
        }

        unlink($this->directory.'/'.$hashes[1]);
        $this->assertSame(7, $this->runImport(['--dry-run' => true])['forms'][0]['resolved_files']);
        $this->runImport();
        $partial = $this->verify(2);
        $this->assertSame(8, $partial['expected']);
        $this->assertSame(7, $partial['verified']);
        $this->assertSame(1, $partial['source_unavailable']);
        $this->assertSame(0, $partial['unsafe']);

        file_put_contents($this->directory.'/'.$hashes[1], $hashes[1]);
        $this->runImport();
        $this->assertSame([], $this->runImport()['forms'][0]['warnings']);
        $this->assertSame(8, $this->verify()['verified']);
        $copies = Attachment::pluck('path', 'name')->all();
        foreach ($names as $name) {
            $this->assertSame($name, Storage::disk('local')->get($copies[$name]));
        }

        unlink($this->directory.'/'.$hashes[2]);
        unlink($this->directory.'/'.$hashes[6]);
        symlink($this->directory.'/'.$hashes[0], $this->directory.'/'.$hashes[6]);
        $this->runImport();
        $partial = $this->verify(2);
        $this->assertSame(8, $partial['expected']);
        $this->assertSame(6, $partial['verified']);
        $this->assertSame(2, $partial['source_unavailable']);
        $this->assertSame(0, $partial['extra_links']);
        $this->assertEquals($copies, Attachment::pluck('path', 'name')->all());
        $this->assertSame($hashes[6], Storage::disk('local')->get($copies[$hashes[6]]));
    }

    public function test_literal_custom_names_keep_their_links_when_the_originals_are_missing(): void
    {
        $literal = str_repeat('a', 40).'.zip'.str_repeat('b', 40).'.pdf';
        $prefixed = 'scan-'.str_repeat('c', 40).'.tiff';
        $this->references($literal.','.$prefixed);
        file_put_contents($this->directory.'/'.$literal, 'one custom file');
        file_put_contents($this->directory.'/'.$prefixed, 'scan');
        $this->runImport();
        $this->assertSame([$literal, $prefixed], Attachment::orderBy('id')->pluck('name')->all());
        $this->assertSame(2, $this->verify()['verified']);

        unlink($this->directory.'/'.$literal);
        $this->runImport();
        $partial = $this->verify(2);
        $this->assertSame(2, $partial['expected']);
        $this->assertSame(1, $partial['source_unavailable']);
        $this->assertSame(0, $partial['extra_links']);
        $copy = Attachment::where('name', $literal)->sole();
        $this->assertSame('one custom file', Storage::disk('local')->get($copy->path));
    }

    public function test_competing_literal_and_split_files_require_review_and_preserve_previous_copies(): void
    {
        $first = str_repeat('a', 40).'.txt';
        $second = str_repeat('b', 40);
        $this->references($first.','.$second);
        file_put_contents($this->directory.'/'.$first, 'first');
        file_put_contents($this->directory.'/'.$second, 'second');
        $this->runImport();
        $copies = Attachment::pluck('path', 'name')->all();

        $this->references($first.$second);
        file_put_contents($this->directory.'/'.$first.$second, 'competing literal');
        $report = $this->runImport()['forms'][0];
        $this->assertNotEmpty($report['warnings']);
        $this->assertSame(1, $this->verify(2)['unsafe']);
        $this->assertEquals($copies, Attachment::pluck('path', 'name')->all());
        Storage::disk('local')->assertExists(array_values($copies));
    }

    public function test_later_dotted_names_allow_empty_and_long_original_extensions(): void
    {
        $names = [str_repeat('a', 40).'.'.str_repeat('x', 45), str_repeat('b', 40).'.', str_repeat('c', 40).'.pdf'];
        $this->references(implode('', $names));
        foreach ($names as $name) {
            file_put_contents($this->directory.'/'.$name, $name);
        }
        $this->runImport(['--source-version' => '2.00.133']);
        $this->assertSame($names, Attachment::orderBy('id')->pluck('name')->all());
        $this->assertSame(3, $this->verify(0, ['--source-version' => '2.00.133'])['verified']);
    }

    public function test_a_missing_long_extension_in_a_later_release_cannot_claim_someone_elses_files(): void
    {
        $short = str_repeat('a', 40).'.pdf';
        $bare = str_repeat('b', 40);
        $following = str_repeat('c', 40).'.jpg';
        $this->references($short.$bare.$following);
        foreach ([$short, $bare, $following] as $name) {
            file_put_contents($this->directory.'/'.$name, $name);
        }
        $source = ['--source-version' => '2.00.133'];
        $this->runImport($source);
        $this->assertSame([$following], Attachment::pluck('name')->all());
        $files = $this->verify(2, $source);
        $this->assertSame(2, $files['expected']);
        $this->assertSame(1, $files['source_unavailable']);
        $this->assertSame(1, $files['verified']);
    }

    private function references(string $value): void
    {
        DB::connection('legacy')->table('histories')->where('id', 80)->update(['attach_files' => $value]);
    }

    private function runImport(array $options = [], int $expected = 0): array
    {
        $code = Artisan::call('easymail:import', ['--json' => true, '--source-version' => '2.00.125', '--attachments' => $this->directory, ...$options]);
        $output = Artisan::output();
        $this->assertSame($expected, $code, $output);
        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    private function verify(int $expected = 0, array $options = []): array
    {
        return $this->runImport(['--verify-only' => true, ...$options], $expected)['reconciliation']['forms'][0]['files'];
    }
}
