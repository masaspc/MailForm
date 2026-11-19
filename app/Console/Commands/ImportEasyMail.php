<?php

namespace App\Console\Commands;

use App\Services\EasyMail\Importer;
use App\Services\EasyMail\IntegrityException;
use App\Services\EasyMail\SourceRelease;
use App\Services\PublicFormUrl;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;

class ImportEasyMail extends Command
{
    protected $signature = 'easymail:import
        {--dry-run : 診断のみ。新DB・添付ストレージへの書き込みを省く}
        {--verify-only : 旧複製と移行済みのフォーム・履歴・非公開添付を読み取り照合し、差異があれば終了コード2を返す}
        {--source-version= : 旧版（DB単独の場合に必須、2.00.124 / 2.00.125 / 2.00.133 / 2.01.004）}
        {--source-root= : 旧EasyMailの設置ディレクトリ（バックアップ）}
        {--source-base= : 旧公開URLの設置先（例 https://example.jp/form）。旧・新の公開入口を一覧に追加}
        {--attachments= : 旧 storage/app/attach_file の場所を指定}
        {--form= : 旧フォームIDを指定}
        {--json : 診断結果をJSONで出力}
        {--overwrite-themes : 管理画面でのテーマの手直しをバックアップの内容で上書きする}
        {--strict : DB構造・公開パスの競合・添付欠損などの整合性エラーで終了コード2。フォーム単位の公開保留は継続}';

    protected $description = 'EasyMail の複製DBからフォーム・受信履歴・添付を移行する';

    public function handle(Importer $importer): int
    {
        try {
            $sourceBase = $this->option('source-base');
            if ($sourceBase !== null) {
                $sourceBase = $this->baseUrl($sourceBase, '--source-base');
                $targetBase = $this->baseUrl(config('app.url'), 'APP_URL');
            }
            $release = SourceRelease::identify($this->directory('source-root'), $this->option('source-version'));
            $root = $release->root;
            $attachments = $this->directory('attachments');
            if ($root !== null && $attachments === null) {
                $default = $root.'/storage/app/attach_file';
                if (is_dir($default) && realpath($default) === $default) {
                    $attachments = $default;
                }
            }
            $id = $this->option('form');
            if ($id !== null && (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id < 1)) {
                throw new RuntimeException('--form には旧フォームの正の整数IDを指定してください。');
            }
            $report = $importer->run($release, $attachments, $id === null ? null : (int) $id, (bool) $this->option('dry-run'), (bool) $this->option('verify-only'), (bool) $this->option('overwrite-themes'));
            if ($sourceBase !== null) {
                foreach ($report['forms'] as $key => $form) {
                    $source = $form['source_published'] && PublicFormUrl::isSegment($form['source_url'])
                        ? PublicFormUrl::entry($sourceBase, $form['source_url']) : null;
                    $targetPath = $report['verify_only'] ? $form['current_public_path'] : ($form['published'] ? $form['url'] : null);
                    $target = $targetPath === null ? null : $targetBase.$targetPath;
                    $report['forms'][$key] += [
                        'source_entry_url' => $source, 'target_entry_url' => $target,
                        'planned_entry_url' => $targetBase.$form['url'],
                        'same_public_url' => $source === null ? null : $source === $target,
                    ];
                }
            }
        } catch (IntegrityException $e) {
            if ($this->option('json')) {
                $this->writeJson(['integrity_errors' => [$e->getMessage()], 'forms' => []]);
            } else {
                $this->error($this->printable($e->getMessage()));
            }
            return $this->option('strict') || $this->option('verify-only') ? self::INVALID : self::FAILURE;
        } catch (QueryException) {
            $this->error('DBを読み書きできません。接続先、権限、移行先のマイグレーションを確認してください。');
            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error(OutputFormatter::escape($e->getMessage()));
            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->writeJson($report);
        } else {
            $this->info($report['verify_only'] ? '移行済みデータの照合' : ($report['dry_run'] ? '移行診断（書き込みなし）' : '移行結果'));
            $this->line(sprintf('共通項目　独自・変更済み %d・標準と一致 %d', $report['library']['custom'], $report['library']['stock']));
            foreach ($report['warnings'] as $warning) {
                $this->warn($this->printable($warning));
            }
            foreach ($report['integrity_errors'] as $error) {
                $this->error('整合性エラー　'.$this->printable($error));
            }
            if ($report['extra_tables']) {
                $this->line('標準外のテーブル '. $this->printable(implode(', ', $report['extra_tables'])));
            }
            foreach ($report['plugins'] as $plugin) {
                $this->line('プラグイン '. $this->printable(($plugin['name'] ?: $plugin['uid']).' '. $plugin['version']).($plugin['active_flag'] ? '（有効）' : '（無効）'));
            }
            foreach ($report['themes'] as $name => $theme) {
                $this->line('独自テーマ '.$this->printable((string) $name).'　元テーマ '.$this->printable($theme['layout'] ?? '推定できません'));
                $labels = \App\Services\ThemeStructure::labels();
                foreach ($theme['structure_sources'] ?? [] as $slot => $location) {
                    $this->line('  構造を推定　'.$this->printable(($labels[$slot] ?? $slot).'　'.$location));
                }
                if (!empty($theme['structure_fallbacks'])) {
                    $this->line('  個別の推定なし（共通または標準の構造を使用）　'.$this->printable(implode('、', array_map(
                        static fn ($slot) => $labels[$slot] ?? $slot, $theme['structure_fallbacks']))));
                }
                foreach (['changed' => '変更', 'added' => '追加', 'removed' => '削除'] as $key => $label) {
                    foreach ($theme['changes'][$key] as $path) {
                        $this->line('  '.$label.' '.$this->printable($path));
                    }
                }
                foreach (array_unique([...$theme['behaviors'], ...$theme['warnings'], ...$theme['errors'], ...$theme['integrity']]) as $notice) {
                    $this->warn('  '.$this->printable($notice));
                }
            }
            foreach ($report['forms'] as $form) {
                $status = $form['published'] ? '公開' : '下書き';
                if ($form['theme_review'] === 'pending') {
                    $status .= '（テーマ照合待ち）';
                }
                $this->line(sprintf('#%d %s %s　項目 %d・履歴 %d・添付照合 %d', $form['legacy_id'], $this->printable($form['url']), $status, $form['field_count'], $form['history_count'], $form['resolved_files']));
                if ($sourceBase !== null) {
                    $this->line('  旧 '.$this->printable($form['source_entry_url'] ?? '公開入口なし').' → 新 '.$this->printable($form['target_entry_url'] ?? '公開入口なし'));
                }
                foreach (array_diff([...$form['issues'], ...$form['warnings'], ...$form['notices']], $form['integrity_errors']) as $issue) {
                    $this->warn('  '.$this->printable($issue));
                }
                foreach (array_diff($form['integrity_errors'], $report['integrity_errors']) as $error) {
                    $this->error('  整合性エラー　'.$this->printable($error));
                }
            }
            foreach ($report['reconciliation']['forms'] ?? [] as $check) {
                $this->line(sprintf('%s 旧フォーム %s → 新フォーム %s（%s）　旧履歴 %d・一致 %d・変更 %d・未取込 %d・消去待ち %d・消去済み %d・旧にない取込履歴 %d・新規受付 %d・添付一致 %d/%d',
                    $check['ok'] ? 'OK' : '要照合', $check['legacy_id'] ?? '―', $check['form_id'] ?? '―', $check['form_state'],
                    $check['source']['histories'], $check['source']['matched'], $check['source']['changed'], $check['source']['missing'],
                    $check['source']['pending_purge'], $check['source']['purged'], array_sum($check['absent_from_source']), array_sum($check['new_only']),
                    $check['files']['verified'], $check['files']['expected']));
                foreach ($check['examples'] as $reason => $ids) {
                    $this->warn('  '.$reason.'　旧履歴ID '.implode(', ', $ids));
                }
            }
        }
        $integrityFailed = $report['integrity_errors'] || array_filter(array_column($report['forms'], 'integrity_errors'));
        return ($report['reconciliation']['ok'] ?? true) && !($integrityFailed && $this->option('strict')) ? self::SUCCESS : self::INVALID;
    }

    private function writeJson(array $report): void
    {
        $this->output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $this->output::OUTPUT_RAW);
    }

    private function baseUrl(string $url, string $setting): string
    {
        $url = PublicFormUrl::encodeInstallationPath($url);
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts ?: [])) || str_contains($url, '\\')) {
            throw new RuntimeException("{$setting} にはスキーム・ホスト・設置パスだけの絶対URLを指定してください。");
        }

        return rtrim($url, '/');
    }

    private function directory(string $option): ?string
    {
        $value = $this->option($option);
        if ($value === null) {
            return null;
        }
        $path = $value === '' ? false : realpath($value);
        if (!$path || !is_dir($path) || !is_readable($path)) {
            throw new RuntimeException("--{$option} に読み取り可能なディレクトリを指定してください。");
        }
        return $path;
    }

    private function printable(string $value): string
    {
        return OutputFormatter::escape(preg_replace('/[[:cntrl:]]/u', '?', $value));
    }
}
