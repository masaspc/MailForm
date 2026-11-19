<?php

namespace App\Services\EasyMail;

use App\Services\ThemeAssets;
use RuntimeException;

/** Extract literal HTML without compiling Blade or evaluating PHP. */
final class ThemeTemplateReader
{
    public string $slotTag;
    public string $omitted;
    public array $warnings = [];
    public array $behaviorCounts = [];
    public bool $behavior = false;
    private array $lines = [1 => 1];
    private int $outputLine = 1;

    private const BLOCKS = [
        'if' => 'endif', 'unless' => 'endunless', 'isset' => 'endisset', 'empty' => 'endempty',
        'switch' => 'endswitch', 'for' => 'endfor', 'foreach' => 'endforeach', 'forelse' => 'endforelse',
        'while' => 'endwhile', 'auth' => 'endauth', 'guest' => 'endguest', 'can' => 'endcan',
        'cannot' => 'endcannot', 'canany' => 'endcanany', 'env' => 'endenv', 'production' => 'endproduction',
        'error' => 'enderror', 'once' => 'endonce', 'push' => 'endpush', 'prepend' => 'endprepend',
        'section' => 'endsection', 'component' => 'endcomponent', 'slot' => 'endslot', 'verbatim' => 'endverbatim',
    ];

    public function __construct(
        private string $theme,
        private string $path,
        private string $reference = '',
        private bool $report = true,
        private array $stockBehaviors = [],
    )
    {
        $id = bin2hex(random_bytes(8));
        $this->slotTag = 'mailform-slot-'.$id;
        $this->omitted = 'mailform-omitted-'.$id;
    }

    public function line(int $parsedLine): int
    {
        return $this->lines[$parsedLine] ?? max(1, $parsedLine);
    }

    public function warn(int $line, string $reason, string $source = '', bool $behavior = false): void
    {
        $alreadyInStock = $source !== '' && str_contains($this->reference, $source);
        if ($behavior && $source !== '') {
            $hash = hash('sha256', $source);
            $this->behaviorCounts[$hash] = ($this->behaviorCounts[$hash] ?? 0) + 1;
            $alreadyInStock = $this->behaviorCounts[$hash] <= ($this->stockBehaviors[$hash] ?? 0);
        }
        if (!$this->report || $alreadyInStock) {
            return;
        }
        if (count($this->warnings) >= 2048) {
            throw new RuntimeException("{$this->path}: 除外箇所が 2048 件を超えています。");
        }
        $this->warnings[] = "{$this->path}:{$line} {$reason}";
        $this->behavior = $this->behavior || $behavior;
    }

    public function literalHtml(string $source): string
    {
        $out = '';
        $cursor = 0;
        $line = 1;
        $stack = [];
        $blockStart = $blockLine = $tokens = 0;
        $php = $this->phpRegions($source);
        preg_match_all('/@end([a-z_]\w*)/i', $source, $ends);
        $customBlocks = array_fill_keys(array_map('strtolower', $ends[1]), true);
        $pattern = '~<!--|<\?|<((?:script|style))\b|\{\{--|\{!!|\{\{|(?<![\w.+%\-])@([a-z_]\w*)~i';
        while (preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $cursor)) {
            if (++$tokens > 8192) {
                throw new RuntimeException("{$this->path}: テンプレートの構文数が上限を超えています。");
            }
            [$token, $start] = $m[0];
            $text = substr($source, $cursor, $start - $cursor);
            if (!$stack) {
                $this->append($out, $text, $line);
            }
            $line += substr_count($text, "\n");
            $end = $start + strlen($token);
            $replacement = '';
            $reason = '';
            $behavior = false;
            $directive = strtolower($m[2][0] ?? '');
            $tag = strtolower($m[1][0] ?? '');
            if ($token === '<!--' || $token === '{{--') {
                $close = $token === '<!--' ? '-->' : '--}}';
                $closeAt = strpos($source, $close, $end);
                $end = $closeAt === false ? strlen($source) : $closeAt + strlen($close);
            } elseif ($tag !== '') {
                $end = preg_match("~</{$tag}\s*>~i", $source, $close, PREG_OFFSET_CAPTURE, $end)
                    ? $close[0][1] + strlen($close[0][0]) : strlen($source);
                $reason = $tag.' 要素を除外しました。';
                $behavior = $tag === 'script';
            } elseif ($token === '<?') {
                // Unrecognized short tags have no reliable PHP token boundary on this runtime.
                $end = $php[$start] ?? strlen($source);
                $reason = 'PHP を除外しました。';
                $replacement = $this->omitted;
                $behavior = true;
            } elseif ($token === '{{' || $token === '{!!') {
                $close = $token === '{{' ? '}}' : '!!}';
                $stop = $this->expressionEnd($source, $end, $close);
                $expression = $stop === null ? '' : trim(substr($source, $end, $stop - $end));
                $end = $stop === null ? strlen($source) : $stop + strlen($close);
                $value = $this->assetExpression($expression);
                if ($value === null) {
                    $reason = 'Blade の値・関数を除外しました。';
                    $replacement = $this->omitted;
                } else {
                    $replacement = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
            } else {
                $args = '';
                if (preg_match('/\G[ \t\r\n]*\(/', $source, $open, 0, $end)) {
                    $from = $end + strlen($open[0]);
                    $stop = $this->expressionEnd($source, $from, ')');
                    $args = $stop === null ? '' : trim(substr($source, $from, $stop - $from));
                    $end = $stop === null ? strlen($source) : $stop + 1;
                }
                if ($directive === 'php' && $args === '') {
                    $stop = $this->expressionEnd($source, $end, '@endphp');
                    $end = $stop === null ? strlen($source) : $stop + 7;
                    $reason = 'Blade 内の PHP を除外しました。';
                    $replacement = $this->omitted;
                    $behavior = true;
                } elseif ($stack && ($directive === end($stack) || ($directive === 'show' && end($stack) === 'endsection'))) {
                    array_pop($stack);
                    if (!$stack) {
                        $this->warn($blockLine, 'Blade の条件・ブロックを除外しました。', substr($source, $blockStart, $end - $blockStart), true);
                    }
                } elseif ((isset(self::BLOCKS[$directive]) || isset($customBlocks[$directive])) && !($directive === 'empty' && $args === '')) {
                    if (!$stack) {
                        $blockStart = $start;
                        $blockLine = $line;
                    }
                    $stack[] = self::BLOCKS[$directive] ?? 'end'.$directive;
                } elseif ($directive === 'include' && $part = $this->includedPart($args)) {
                    $replacement = "<{$this->slotTag} part=\"{$part}\"></{$this->slotTag}>";
                } elseif ($directive !== 'yield' || !preg_match('~\A([\'\"])(?:content|style|script)\1\z~', $args)) {
                    $reason = 'Blade ディレクティブを除外しました。';
                    $behavior = true;
                }
            }
            $raw = substr($source, $start, $end - $start);
            if (!$stack) {
                if ($reason !== '') {
                    $this->warn($line, $reason, $raw, $behavior);
                }
                $this->append($out, $replacement, $line);
            }
            $line += substr_count($raw, "\n");
            $cursor = $end;
        }
        if ($stack) {
            $this->warn($blockLine, '閉じていない Blade ブロック以降を除外しました。', '', true);
        } else {
            $this->append($out, substr($source, $cursor), $line);
        }
        return $out;
    }

    private function append(string &$out, string $text, int $sourceLine): void
    {
        if ($text === '') {
            return;
        }
        $this->lines[$this->outputLine] = $sourceLine;
        $count = substr_count($text, "\n");
        for ($i = 0; $i < $count; $i++) {
            $this->lines[++$this->outputLine] = ++$sourceLine;
        }
        $out .= $text;
    }

    /** Keep HTML inside open PHP conditionals within the same omitted region. */
    private function phpRegions(string $source): array
    {
        if (!str_contains($source, '<?')) {
            return [];
        }
        $regions = [];
        $start = null;
        $offset = 0;
        $braces = $alternative = $parentheses = 0;
        $control = false;
        foreach (token_get_all($source) as $token) {
            $id = is_array($token) ? $token[0] : null;
            if ($id === T_OPEN_TAG || $id === T_OPEN_TAG_WITH_ECHO) {
                $start ??= $offset;
            } elseif (in_array($id, [T_IF, T_FOR, T_FOREACH, T_WHILE, T_SWITCH, T_DECLARE], true)) {
                $control = true;
            } elseif (in_array($id, [T_ENDIF, T_ENDFOR, T_ENDFOREACH, T_ENDWHILE, T_ENDSWITCH, T_ENDDECLARE], true)) {
                $alternative = max(0, $alternative - 1);
            } elseif ($token === '(') {
                $parentheses++;
            } elseif ($token === ')') {
                $parentheses--;
            } elseif ($token === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $braces++;
                $control = false;
            } elseif ($token === '}') {
                $braces--;
            } elseif ($token === ':' && $control && $parentheses === 0) {
                $alternative++;
                $control = false;
            } elseif ($token === ';') {
                $control = false;
            }
            $offset += strlen(is_array($token) ? $token[1] : $token);
            if ($id === T_CLOSE_TAG && $start !== null && !$braces && !$alternative && !$control) {
                $regions[$start] = $offset;
                $start = null;
            }
        }
        if ($start !== null) {
            $regions[$start] = $offset;
        }
        return $regions;
    }

    /** Locate a Blade delimiter outside strings, comments and nested expression delimiters. */
    private function expressionEnd(string $source, int $from, string $delimiter): ?int
    {
        $stack = [];
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];
        $length = strlen($source);
        for ($i = $from; $i < $length; $i++) {
            $char = $source[$i];
            if (!$stack && $char === $delimiter[0] && substr_compare($source, $delimiter, $i, strlen($delimiter)) === 0) {
                return $i;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                for ($i++; $i < $length && $source[$i] !== $char; $i++) {
                    if ($source[$i] === '\\') {
                        $i++;
                    }
                }
            } elseif (substr_compare($source, '/*', $i, 2) === 0) {
                $next = strpos($source, '*/', $i + 2);
                if ($next === false) {
                    return null;
                }
                $i = $next + 1;
            } elseif (substr_compare($source, '//', $i, 2) === 0 || $char === '#') {
                $next = strpos($source, "\n", $i + 1);
                if ($next === false) {
                    return null;
                }
                $i = $next;
            } elseif (substr_compare($source, '<<<', $i, 3) === 0) {
                return null; // A heredoc can contain apparent Blade delimiters; omit the remainder.
            } elseif (isset($pairs[$char])) {
                $stack[] = $pairs[$char];
            } elseif ($stack && end($stack) === $char) {
                array_pop($stack);
            }
        }
        return null;
    }

    private function assetExpression(string $expression): ?string
    {
        $prefix = '\$(?:data\s*->\s*)?theme_path';
        if (preg_match('~\A'.$prefix.'\z~', $expression)) {
            return 'theme:';
        }
        if (preg_match('~\A(?:asset|theme_asset)\s*\(\s*(?:'.$prefix.'\s*\.\s*)?([\'\"])([^\'\"$\\\\\x00-\x1f]+)\1\s*\)\z~', $expression, $m)) {
            $path = $this->localPath($m[2]);
            return $path === null ? null : 'theme:'.$path;
        }
        return null;
    }

    public function localPath(string $value): ?string
    {
        $value = trim($value);
        if (str_starts_with($value, 'theme:')) {
            $value = substr($value, 6);
        }
        foreach (['theme/', 'themes/', 'resources/views/theme/'] as $prefix) {
            $prefix .= $this->theme.'/';
            if (str_starts_with(ltrim($value, '/'), $prefix)) {
                $value = substr(ltrim($value, '/'), strlen($prefix));
                break;
            }
        }
        if (str_starts_with($value, './')) {
            $value = substr($value, 2);
        }
        return str_contains($value, $this->omitted) || !ThemeAssets::validPath($value) ? null : $value;
    }

    private function includedPart(string $args): ?string
    {
        $theme = preg_quote($this->theme, '~');
        if (preg_match('~\A([\'\"])(?:theme\.(?:\{\$data->theme_name\}|'.$theme.')\.)?(header|footer)\1\z~', $args, $m)) {
            return $m[2];
        }
        return null;
    }
}
