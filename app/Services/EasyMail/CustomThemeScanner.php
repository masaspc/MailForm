<?php

namespace App\Services\EasyMail;

use App\Services\Admin\FormEditor;
use App\Services\SafeMarkup;
use App\Services\ThemeAssets;
use App\Services\ThemeAssets\StylesheetReport;
use FilesystemIterator;
use RuntimeException;
use UnexpectedValueException;

/** Audit an offline EasyMail theme against the manifest of its identified release. */
final class CustomThemeScanner
{
    private const MAX_ENTRIES = 1024;
    private const MAX_FILE = 8 * 1024 * 1024;
    private const MAX_TOTAL = 32 * 1024 * 1024;

    private string $directory;
    private string $releaseHash;
    private array $stock = [];

    public function __construct(string $root, array $manifest)
    {
        $canonical = realpath($root);
        if ($canonical === false || is_link(rtrim($root, '/'))) {
            throw new RuntimeException('EasyMail の設置ディレクトリを確認できません。');
        }
        $this->directory = $canonical.'/resources/views/theme';
        ksort($manifest, SORT_STRING);
        $this->releaseHash = hash('sha256', serialize($manifest));
        foreach ($manifest as $path => $hash) {
            if (preg_match('~\Aresources/views/theme/([^/]+)/(.+)\z~', $path, $match) && isset(FormEditor::THEMES[$match[1]])) {
                $this->stock[$match[1]][$match[2]] = $hash;
            }
        }
    }

    public function names(): array
    {
        $this->requireDirectory($this->directory, 'resources/views/theme');
        $names = [];
        foreach (new FilesystemIterator($this->directory) as $file) {
            $name = $file->getFilename();
            if (!isset(FormEditor::THEMES[$name]) && ($file->isLink() || $file->isDir())) {
                $names[] = mb_scrub($name, 'UTF-8');
            }
            if (count($names) > self::MAX_ENTRIES) {
                throw new RuntimeException('独自テーマのディレクトリ数が 1024 件を超えています。');
            }
        }
        sort($names, SORT_STRING);
        return $names;
    }

    public function scan(string $name): array
    {
        $result = [
            'layout' => null, 'score' => 0, 'changes' => ['changed' => [], 'added' => [], 'removed' => []],
            'behaviors' => [], 'warnings' => [], 'errors' => [], 'integrity' => [], 'header' => '', 'footer' => '', 'logo' => null,
            'assets' => [], 'stylesheets' => [], 'fingerprint' => null, 'structure' => [], 'structure_sources' => [],
        ];
        if (strlen($name) > 255 || !preg_match('/\A[\pL\pM\pN_+ -][\pL\pM\pN._+ -]*\z/u', $name) || isset(FormEditor::THEMES[$name])) {
            $result['errors'][] = '独自テーマのディレクトリ名を安全に参照できません。';
            return $result;
        }
        try {
            $this->requireDirectory($this->directory, 'resources/views/theme');
            $files = $this->inventory($this->directory.'/'.$name);
        } catch (RuntimeException $error) {
            $result['errors'][] = $error->getMessage();
            if ($error instanceof IntegrityException) {
                $result['integrity'][] = $error->getMessage();
            }
            return $result;
        }
        $hashes = array_column($files, 'hash', 'path');
        $scores = [];
        foreach ($this->stock as $slug => $base) {
            $scores[$slug] = count(array_intersect_assoc($hashes, $base));
        }
        $score = $scores ? max($scores) : 0;
        $matches = array_keys($scores, $score, true);
        sort($matches, SORT_STRING);
        $layout = $score ? $matches[0] : null;
        $result['fingerprint'] = hash('sha256', serialize([$this->releaseHash, $layout, $hashes]));
        if ($layout === null) {
            $result['errors'][] = 'この版の標準テーマと同じ相対パス・SHA-256 のファイルがなく、派生元を特定できません。';
            return $result;
        }
        $result['layout'] = $layout;
        $result['score'] = $score;
        if (count($matches) > 1) {
            $result['warnings'][] = '標準テーマとの一致数が同点です ('.implode(', ', $matches).")。選択した {$layout} を確認してください。";
        }
        $base = $this->stock[$layout];
        $result['changes'] = [
            'changed' => array_keys(array_diff_assoc(array_intersect_key($hashes, $base), $base)),
            'added' => array_keys(array_diff_key($hashes, $base)),
            'removed' => array_keys(array_diff_key($base, $hashes)),
        ];
        try {
            $this->convert($name, $files, $base, $result);
        } catch (RuntimeException $error) {
            $result['errors'][] = $error->getMessage();
            $result['assets'] = $result['stylesheets'] = $result['structure'] = $result['structure_sources'] = [];
            $result['header'] = $result['footer'] = '';
            $result['logo'] = null;
        }
        return $result;
    }

    private function requireDirectory(string $path, string $label): void
    {
        if (realpath($path) !== $path || !is_dir($path) || !is_readable($path)) {
            throw new IntegrityException("{$label}: ディレクトリが欠損、リンク、または読み取り不能です。");
        }
    }

    private function inventory(string $directory): array
    {
        $pending = [''];
        $files = [];
        $entries = $total = 0;
        while ($pending) {
            $prefix = array_pop($pending);
            $folder = $directory.($prefix === '' ? '' : '/'.$prefix);
            $this->requireDirectory($folder, $prefix ?: basename($directory));
            try {
                $items = new FilesystemIterator($folder);
            } catch (UnexpectedValueException) {
                throw new IntegrityException(($prefix ?: basename($directory)).': ディレクトリを読み取れません。');
            }
            foreach ($items as $item) {
                $filename = $item->getFilename();
                if (!mb_check_encoding($filename, 'UTF-8') || preg_match('~[\x00-\x1f\x7f\\\\]~', $filename)) {
                    throw new RuntimeException('制御文字・不正な文字を含むファイル名を参照できません。');
                }
                $path = ltrim($prefix.'/'.$filename, '/');
                if (strlen($path) > 512) {
                    throw new RuntimeException("{$filename}: テーマ内の相対パスが 512 バイトを超えています。");
                }
                if (++$entries > self::MAX_ENTRIES || substr_count($path, '/') > 16) {
                    throw new RuntimeException("{$path}: ファイル・ディレクトリの数が 1024 件、または階層が 16 段を超えています。");
                }
                if ($item->isLink()) {
                    throw new IntegrityException("{$path}: シンボリックリンクを参照できません。");
                }
                if ($item->isDir()) {
                    $pending[] = $path;
                    continue;
                }
                $bytes = $this->read($directory.'/'.$path, $path, min(self::MAX_FILE, self::MAX_TOTAL - $total));
                $total += strlen($bytes);
                $files[$path] = ['path' => $path, 'hash' => hash('sha256', $bytes), 'bytes' => $bytes];
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    private function read(string $file, string $label, int $limit): string
    {
        clearstatcache(true, $file);
        $stat = @lstat($file);
        if (!$stat || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1 || realpath($file) !== $file || !is_readable($file)) {
            throw new IntegrityException("{$label}: 通常ファイルとして安全に読み取れません。");
        }
        if ($stat['size'] > $limit) {
            throw new RuntimeException("{$label}: ファイル容量が読み取り上限を超えています。");
        }
        $stream = @fopen($file, 'rb');
        if (!$stream) {
            throw new IntegrityException("{$label}: ファイルを開けません。");
        }
        try {
            $opened = fstat($stream);
            if (!$opened || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino'] || $opened['nlink'] !== 1) {
                throw new IntegrityException("{$label}: 読み取り中にファイルが変更されました。");
            }
            $bytes = stream_get_contents($stream, $limit + 1);
            if ($bytes === false || strlen($bytes) !== $stat['size'] || !feof($stream)) {
                throw new IntegrityException("{$label}: ファイル全体を読み取れません。");
            }
            return $bytes;
        } finally {
            fclose($stream);
        }
    }

    private function convert(string $name, array $files, array $base, array &$result): void
    {
        $warnings = &$result['warnings'];
        $images = $styles = [];
        foreach ($files as $path => $file) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($extension, ['png', 'gif', 'jpg', 'jpeg', 'webp', 'svg'], true)) {
                // Some stock ZIPs include AppleDouble metadata next to the real GIF.
                if (str_starts_with(basename($path), '._') && ($base[$path] ?? null) === $file['hash']) {
                    continue;
                }
                $asset = ThemeAssets::prepare($path, $file['bytes'], [], $warnings);
                if ($asset !== null) {
                    $images[$path] = $asset;
                }
            } elseif (str_starts_with($path, 'css/') && $extension === 'css' && ($base[$path] ?? null) !== $file['hash']) {
                $styles[$path] = $file['bytes'];
            }
        }
        $result['assets'] = $images;
        foreach ($result['changes'] as $kind => $paths) {
            foreach ($paths as $path) {
                $reason = $this->behaviorType($path, $kind === 'removed');
                if ($reason !== null) {
                    $result['behaviors'][] = $path.' ('.$reason.' / '.['changed' => '変更', 'added' => '追加', 'removed' => '欠損'][$kind].')';
                    if ($kind !== 'removed') {
                        $warnings[] = in_array($path, ['form.blade.php', 'index.blade.php', 'thanks.blade.php'], true)
                            ? "{$path}:1 表示用のタグと識別子を静的に調べました。旧テンプレートの処理は MailForm の標準処理に置き換えます。"
                            : "{$path}:1 動作ファイル全体を除外しました。";
                    }
                }
            }
        }
        $templates = [];
        foreach (['layout', 'index', 'form', 'thanks'] as $part) {
            $path = $part.'.blade.php';
            if (isset($files[$path])) {
                $templates[$path] = $files[$path]['bytes'];
            }
        }
        foreach ((new ThemeStructureReader)->read($templates, $warnings) as $key => $value) {
            $result[$key] = $value;
        }
        $markup = new StaticThemeMarkup($name, $images);
        $changed = array_flip([...$result['changes']['added'], ...$result['changes']['changed']]);
        $parts = ['header' => '', 'footer' => ''];
        foreach ($parts as $part => $_) {
            $path = $part.'.blade.php';
            if (isset($files[$path])) {
                $parts[$part] = $markup->partial($path, $this->template($files[$path]));
            }
        }
        $layout = 'layout.blade.php';
        if (isset($files[$layout])) {
            $source = $this->template($files[$layout]);
            $reference = '';
            if (isset($changed[$layout], $base[$layout])) {
                // The stock sibling may itself be customized. Trust it only after verifying this release's digest.
                try {
                    $official = $this->read($this->directory.'/'.$result['layout'].'/'.$layout, $layout, ThemeTemplateReader::MAX_BYTES);
                    if (hash('sha256', $official) === $base[$layout]) {
                        $reference = $official;
                    }
                } catch (RuntimeException) {
                    // A backup can legitimately contain only the custom theme.
                }
            }
            $parts = $markup->layout($layout, $source, $parts, isset($changed[$layout]), $reference);
        }
        foreach ($parts as $part => $html) {
            if (strlen($html) > 65535 || mb_strlen($html, 'UTF-8') > 20000) {
                throw new RuntimeException("{$part}: 抽出した HTML が管理画面またはデータベースの上限（20,000 文字・65,535 バイト）を超えています。");
            }
            $result[$part] = $html;
        }
        if ($markup->layoutBehavior) {
            $result['behaviors'][] = 'layout.blade.php (レイアウト内の独自動作)';
        }
        array_push($warnings, ...array_values(array_unique($markup->warnings)));
        if (preg_match('~<img src="theme:([^"]+)"~', $parts['header'], $match)) {
            $result['logo'] = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            foreach (array_keys($images) as $path) {
                if (preg_match('~(?:^|[/_. -])logo(?:$|[/_. -])~i', $path)) {
                    $result['logo'] = $path;
                    break;
                }
            }
        }
        // A custom component tree can rely on a stock grid that the default MailForm layout never loads.
        // Each processed stylesheet still shares one content-addressed blob across all themes.
        if (!empty($result['structure'])) {
            foreach ($markup->stylesheets as $path) {
                if (str_starts_with($path, 'css/') && str_ends_with(strtolower($path), '.css')
                    && isset($files[$path], $base[$path]) && $files[$path]['hash'] === $base[$path]) {
                    $styles[$path] = $files[$path]['bytes'];
                }
            }
        }
        $order = array_unique([...$markup->stylesheets, ...array_keys($styles)]);
        $cssReport = new StylesheetReport(linkedLogo: SafeMarkup::hasLinkedLogo($result['header']),
            actionAttributes: $result['button_style_hooks'] ?? []);
        unset($result['button_style_hooks']);
        foreach ($order as $path) {
            if (isset($styles[$path])) {
                $asset = ThemeAssets::prepare($path, $styles[$path], $images, $warnings, $cssReport);
                if ($asset !== null) {
                    $result['assets'][$path] = $asset;
                    $result['stylesheets'][] = $path;
                }
            }
        }
        if ($cssReport->backgroundLogo !== null) {
            $result['background_logo'] = $result['logo'] = $cssReport->backgroundLogo;
        }
        array_push($warnings, ...$cssReport->warnings());
        if (count($warnings) > 256) {
            $omitted = count($warnings) - 256;
            $warnings = array_slice($warnings, 0, 256);
            $warnings[] = "ほか {$omitted} 件の除外箇所があります。該当テーマを確認してください。";
        }
    }

    private function template(array $file): string
    {
        return ThemeTemplateReader::checkedSource($file['path'], $file['bytes']);
    }

    private function behaviorType(string $path, bool $removed): ?string
    {
        if (preg_match('/\.(?:[cm]?js|jsx|ts|tsx|vue)\z/i', $path)) {
            return 'JavaScript・入力チェック';
        }
        if (in_array($path, ['header.blade.php', 'footer.blade.php', 'layout.blade.php'], true)) {
            return $removed && $path === 'layout.blade.php' ? 'レイアウトのテンプレート' : null;
        }
        if (preg_match('/\.php\z/i', $path)) {
            return match (true) {
                str_contains($path, 'mail') => 'メールのテンプレート',
                str_contains($path, 'thanks') => '完了画面のテンプレート',
                default => '入力・確認・送信のテンプレート',
            };
        }
        return null;
    }
}
