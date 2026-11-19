<?php

namespace App\Console\Commands;

use App\Services\EasyMail\Importer;
use App\Services\EasyMail\SourceRelease;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;

class ImportEasyMail extends Command
{
    protected $signature = 'easymail:import
        {--dry-run : 診断のみ。新DB・添付ストレージへの書き込みを省く}
        {--source-version= : 旧版（DB単独の場合に必須、2.00.133 / 2.01.004）}
        {--source-root= : 旧EasyMailの設置ディレクトリ（バックアップ）}
        {--attachments= : 旧 storage/app/attach_file の場所を指定}
        {--form= : 旧フォームIDを指定}
        {--json : 診断結果をJSONで出力}
        {--strict : 公開保留または照合警告がある場合は終了コード2を返す}';

    protected $description = 'EasyMail 2.00.133 / 2.01.004 の複製DBからフォーム・受信履歴・添付を移行する';

    public function handle(Importer $importer): int
    {
        try {
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
            $report = $importer->run($release, $attachments, $id === null ? null : (int) $id, (bool) $this->option('dry-run'));
        } catch (QueryException) {
            $this->error('DBを読み書きできません。接続先、権限、移行先のマイグレーションを確認してください。');
            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error(OutputFormatter::escape($e->getMessage()));
            return self::FAILURE;
        }

        $review = (bool) $report['warnings'];
        foreach ($report['forms'] as $form) {
            $review = $review || $form['issues'] || $form['warnings'];
        }
        if ($this->option('json')) {
            $this->output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $this->output::OUTPUT_RAW);
        } else {
            $this->info($report['dry_run'] ? '移行診断（書き込みなし）' : '移行結果');
            $this->line(sprintf('共通項目　追加・変更 %d・標準と一致 %d', $report['library']['custom'], $report['library']['stock']));
            foreach ($report['warnings'] as $warning) {
                $this->warn($this->printable($warning));
            }
            if ($report['extra_tables']) {
                $this->line('標準外のテーブル '. $this->printable(implode(', ', $report['extra_tables'])));
            }
            foreach ($report['plugins'] as $plugin) {
                $this->line('プラグイン '. $this->printable(($plugin['name'] ?: $plugin['uid']).' '. $plugin['version']).($plugin['active_flag'] ? '（有効）' : '（無効）'));
            }
            foreach ($report['forms'] as $form) {
                $this->line(sprintf('#%d %s %s　項目 %d・履歴 %d・添付照合 %d', $form['legacy_id'], $this->printable($form['url']), $form['published'] ? '公開' : '下書き', $form['field_count'], $form['history_count'], $form['resolved_files']));
                foreach (array_merge($form['issues'], $form['warnings']) as $issue) {
                    $this->warn('  '.$this->printable($issue));
                }
            }
        }
        return $review && $this->option('strict') ? self::INVALID : self::SUCCESS;
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
