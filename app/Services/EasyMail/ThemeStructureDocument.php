<?php

namespace App\Services\EasyMail;

use App\Services\ThemeStructure;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use RuntimeException;
use WeakMap;

/** A bounded HTML tree with locations and semantic landmarks from unevaluated Blade. */
final class ThemeStructureDocument
{
    public readonly DOMElement $root;
    /** @var DOMElement[] */
    public array $elements = [];
    /** @var array<string, list<DOMElement>> Landmarks in document order. */
    public array $marks = [];
    public WeakMap $state;
    private array $events = [];
    private array $scopes = [];
    private array $closers = [];
    private array $context = [];
    private array $positions = [];
    private string $prefix;
    private string $pattern;
    private int $position = 0;

    public function __construct(public readonly string $path, string $source)
    {
        $this->state = new WeakMap;
        $this->prefix = 'mft'.bin2hex(random_bytes(8)).'x';
        $this->pattern = '~'.$this->prefix.'(\d+)\s*z~';
        $document = new DOMDocument;
        $html = $this->mask($source);
        $document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $this->root = $document->getElementsByTagName('body')->item(0);
        $this->walk($this->root, 0);
        if ($this->scopes) {
            throw new RuntimeException("{$this->path}: 閉じていない Blade ブロックがあります。");
        }
    }

    /** Replace each expression with an inert, unforgeable token, including inside attributes. */
    private function mask(string $source): string
    {
        $php = ThemeTemplateReader::phpRegions($source);
        $pattern = '~<!--|<\?|\{\{--|\{!!|\{\{|(?<![\w.+%\-])@([a-z_]\w*)|<(script|style|template|svg|math|object|iframe|noscript|head)\b~i';
        $out = '';
        $cursor = $count = 0;
        $length = strlen($source);
        while (preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $cursor)) {
            if (++$count > 8192) {
                throw new RuntimeException("{$this->path}: 構造解析の構文数が 8192 件を超えています。");
            }
            [$open, $at] = $m[0];
            $out .= substr($source, $cursor, $at - $cursor);
            $end = $at + strlen($open);
            $kind = 'omitted';
            $args = '';
            if ($open === '<!--' || $open === '{{--') {
                $close = $open === '<!--' ? '-->' : '--}}';
                $stop = strpos($source, $close, $end);
                if ($stop === false) {
                    throw new RuntimeException("{$this->path}: 閉じていないコメントがあります。");
                }
                $end = $stop + strlen($close);
            } elseif ($open === '<?') {
                $end = $php[$at] ?? $length;
            } elseif ($open === '{{' || $open === '{!!') {
                $close = $open === '{{' ? '}}' : '!!}';
                $stop = ThemeTemplateReader::expressionEnd($source, $end, $close);
                if ($stop === null) {
                    throw new RuntimeException("{$this->path}: Blade の式の終端を特定できません。");
                }
                $kind = 'value';
                $args = substr($source, $end, $stop - $end);
                $end = $stop + strlen($close);
            } elseif ($tag = $m[2][0] ?? null) {
                $end = preg_match("~</{$tag}\s*>~i", $source, $close, PREG_OFFSET_CAPTURE, $end)
                    ? $close[0][1] + strlen($close[0][0]) : $length;
            } else {
                $kind = strtolower($m[1][0]);
                if (preg_match('/\G\s*\(/', $source, $paren, 0, $end)) {
                    $from = $end + strlen($paren[0]);
                    $stop = ThemeTemplateReader::expressionEnd($source, $from, ')');
                    if ($stop === null) {
                        throw new RuntimeException("{$this->path}: Blade ディレクティブの引数の終端を特定できません。");
                    }
                    $args = substr($source, $from, $stop - $from);
                    $end = $stop + 1;
                }
                if (($kind === 'php' && $args === '') || $kind === 'verbatim') {
                    $close = '@end'.$kind;
                    $stop = $kind === 'php' ? ThemeTemplateReader::expressionEnd($source, $end, $close) : strpos($source, $close, $end);
                    if ($stop === null || $stop === false) {
                        throw new RuntimeException("{$this->path}: 閉じていない Blade ブロックがあります。");
                    }
                    $end = $stop + strlen($close);
                    $kind = 'omitted';
                }
                if (str_starts_with($kind, 'end')) {
                    $this->closers[substr($kind, 3)] = true;
                }
            }
            $this->events[$count] = [$kind, trim($args)];
            $out .= $this->prefix.$count.str_repeat("\n", substr_count($source, "\n", $at, $end - $at)).'z';
            $cursor = $end;
        }
        return $out.substr($source, $cursor);
    }

    private function walk(DOMNode $node, int $depth): void
    {
        if ($depth > 64 || ++$this->position > 32768) {
            throw new RuntimeException("{$this->path}: HTML の構造が 64 階層または解析上限を超えています。");
        }
        if ($node instanceof DOMText && $node->parentNode instanceof DOMElement) {
            $parent = $node->parentNode;
            $value = $node->textContent;
            preg_match_all($this->pattern, $value, $matches, PREG_OFFSET_CAPTURE);
            $cursor = 0;
            foreach ($matches[0] as $i => [$token, $at]) {
                $this->literal($parent, substr($value, $cursor, $at - $cursor));
                [$kind, $args] = $this->events[(int)$matches[1][$i][0]];
                $this->event($parent, $kind, $args);
                $cursor = $at + strlen($token);
            }
            $this->literal($parent, substr($value, $cursor));
        } elseif ($node instanceof DOMElement) {
            $this->state[$node] = $this->context;
            if (empty($this->context['skip'])) {
                $this->elements[] = $node;
                if (count($this->elements) > 4096) {
                    throw new RuntimeException("{$this->path}: HTML の要素数が 4096 件を超えています。");
                }
                if (!empty($this->context['required'])) {
                    $this->mark('required', $node);
                }
                if (!empty($this->context['error'])) {
                    $this->mark('error', $node);
                }
                if ($node->tagName === 'input' && in_array($node->getAttribute('type'), ['button', 'submit'], true)) {
                    $this->literal($node, $this->staticValue($node, 'value'));
                    preg_match_all($this->pattern, $node->getAttribute('value'), $tokens);
                    foreach ($tokens[1] as $token) {
                        [$kind, $arg] = $this->events[(int)$token];
                        if ($kind === 'value') {
                            $this->value($node, $arg);
                        }
                    }
                }
            }
            foreach ($node->childNodes as $child) {
                $this->walk($child, $depth + 1);
            }
        }
    }

    private function literal(DOMElement $node, string $text): void
    {
        $text = trim($text);
        if ($text === '' || !empty($this->context['skip']) || strlen($text) > 120) {
            return;
        }
        if (in_array($node->tagName, ['button', 'input', 'span', 'strong'], true)) {
            $slot = match (true) {
                (bool)preg_match('/確認|confirm|review/i', $text) => 'confirm_button',
                (bool)preg_match('/戻る|修正|back|edit/i', $text) => 'back_button',
                (bool)preg_match('/送信|send|submit/i', $text) => 'send_button',
                default => null,
            };
            if ($slot) {
                $this->mark($slot, $node);
            }
        }
        if (preg_match('/^(?:必須|required|※|\*)$/iu', $text)) {
            $this->mark('required', $node);
        }
        $step = match (strtolower($text)) {
            '入力', '入力画面', 'input', 'entry' => 'step_input',
            '確認', '確認画面', 'confirm', 'review' => 'step_confirm',
            '完了', '送信完了', 'complete', 'finish', 'thanks' => 'step_thanks',
            default => null,
        };
        if ($step) {
            $this->mark($step, $node);
        }
    }

    private function event(DOMElement $node, string $kind, string $args): void
    {
        $this->position++;
        if (str_starts_with($kind, 'end') || $kind === 'show' || $kind === 'stop') {
            $last = end($this->scopes);
            if ($last && ($kind === 'end'.$last[0] || (in_array($kind, ['show', 'stop'], true) && $last[0] === 'section'))) {
                [, $this->context] = array_pop($this->scopes);
            }
            return;
        }
        if ($kind === 'elseif' || $kind === 'else') {
            $last = end($this->scopes);
            if ($last && in_array($last[0], ['if', 'unless', 'isset', 'empty'], true)) {
                $previous = $this->context;
                $this->context = $last[1];
                if ($kind === 'elseif') {
                    $this->condition($node, $args);
                } elseif (isset($previous['stage']) && $previous['stage'] !== ($this->context['stage'] ?? null)) {
                    $this->context['stage'] = $previous['stage'] === 'confirm' ? 'input' : 'confirm';
                }
            }
            return;
        }
        if ($kind === 'case' || $kind === 'default') {
            $last = end($this->scopes);
            if ($last && $last[0] === 'switch' && preg_match('/\bform_type\b/', $last[2])) {
                $this->context['type'] = trim($args, "'\" \t\r\n");
            }
            return;
        }
        if ($kind === 'section' && preg_match('/^\s*([\'"])[\w.-]+\1\s*,/', $args)) {
            return; // Inline sections do not have a corresponding @endsection.
        }
        $blocks = ['if', 'unless', 'isset', 'empty', 'foreach', 'forelse', 'switch', 'section', 'error'];
        if ((in_array($kind, $blocks, true) || isset($this->closers[$kind])) && !($kind === 'empty' && $args === '')) {
            if (count($this->scopes) >= 64) {
                throw new RuntimeException("{$this->path}: Blade の階層が 64 段を超えています。");
            }
            $this->scopes[] = [$kind, $this->context, $args];
            if (!in_array($kind, $blocks, true) || ($kind === 'section' && trim($args, "'\" \t\r\n") !== 'content')) {
                $this->context['skip'] = true;
            } elseif (empty($this->context['skip'])) {
                if ($kind === 'foreach' || $kind === 'forelse') {
                    if (preg_match('/\b(?:choices|options)\b/i', $args)) {
                        $this->context['choices'] = $node;
                    } elseif (preg_match('/\b(?:form_items|fields|items|answers)\b/i', $args)) {
                        $this->context['rows'] = $node;
                    }
                } elseif ($kind === 'switch' && preg_match('/\bform_type\b/', $args)) {
                    $this->mark('field_host', $node);
                } elseif ($kind === 'error') {
                    $this->context['error'] = true;
                } elseif ($kind === 'if' || $kind === 'unless' || $kind === 'isset') {
                    $this->condition($node, $args);
                }
            }
            return;
        }
        if (!empty($this->context['skip'])) {
            return;
        }
        if ($kind === 'value') {
            $this->value($node, $args);
        } elseif ($kind === 'yield' && preg_match('/^\s*([\'"])content\1\s*$/', $args)) {
            $this->mark('content', $node);
        } elseif (in_array($kind, ['include', 'includeif', 'includewhen'], true)) {
            if (preg_match('~(?:[.\'/"])(form|steps?|progress|flow)[\'"]\s*(?:,|$)~i', $args, $m)) {
                $this->mark(strtolower($m[1]) === 'form' ? 'form_include' : 'step_hint', $node);
            }
        }
    }

    private function condition(DOMElement $node, string $args): void
    {
        if (preg_match('/\bform_type[\'"\]]*\s*={2,3}\s*([\'"])([\w-]+)\1/i', $args, $m)) {
            $this->context['type'] = strtolower($m[2]);
            if ($m[2] === 'text') {
                $this->mark('field_host', $node);
            }
        }
        if (preg_match('/(?:->|\b)required\b/i', $args) && !str_contains($args, '!')) {
            $this->context['required'] = true;
        }
        if (preg_match('/(?:\$(?:is_?confirm(?:ation)?|confirm(?:Mode)?)\b|(?:stage|mode)\s*={2,3}\s*[\'"]confirm[\'"])/i', $args)) {
            $this->context['stage'] = str_contains($args, '!') ? 'input' : 'confirm';
        }
    }

    private function value(DOMElement $node, string $args): void
    {
        // Never infer HTML from a string returned by PHP or Blade, including conditional HTML.
        if (str_contains($args, '<') || strlen($args) > 2048) {
            return;
        }
        if (preg_match('/^(?:__|trans)\s*\(\s*([\'"])([^\'"]+)\1\s*\)$/u', $args, $text)) {
            $this->literal($node, $text[2]);
        }
        $role = match (true) {
            (bool)preg_match('/\bform_title\b|\$form\s*(?:->|\[\s*[\'"])(?:name|title)\b/', $args) => 'title',
            (bool)preg_match('/\bthanks_message\b|\$thanksMessage\b/i', $args) => 'thanks_message',
            (bool)preg_match('/\bdescription_above\b/', $args) => 'above',
            (bool)preg_match('/\bdescription_below\b/', $args) => 'below',
            (bool)preg_match('/\bdescription\b|\b(?:input|conf|error)_form_(?:header|footer)_message\b/', $args) => 'description',
            (bool)preg_match('/\b(?:conf|confirm)_btn\b/', $args) => 'confirm_button',
            (bool)preg_match('/\b(?:back|return)_btn\b/', $args) => 'back_button',
            (bool)preg_match('/\b(?:send|submit)_btn\b/', $args) => 'send_button',
            (bool)preg_match('/\blabel_text\b|\$(?:choice|option)\s*$/', $args) => 'choice_text',
            (bool)preg_match('/^(?:e\s*\(\s*)?\$\w+\s*(?:->\s*(?:title|label)\b|\[\s*[\'"](?:title|label)[\'"]\s*\])|^\$(?:fieldLabel|field_title)\b/i', $args) => 'label',
            (bool)preg_match('/\$(?:errors?|messageBag)\b|\berror_message\b/', $args) => 'error',
            (bool)preg_match('/\$(?:answers?|confirmed_value|confirm_value)\b|\bconfirm_value\b/', $args)
                || (($this->context['stage'] ?? '') === 'confirm' && preg_match('/\bvalue\b/', $args)) => 'confirm_value',
            default => null,
        };
        if ($role !== null) {
            $this->mark($role, $node);
        }
    }

    private function mark(string $role, DOMElement $node): void
    {
        $id = spl_object_id($node);
        if (!isset($this->positions[$role][$id])) {
            $this->marks[$role][] = $node;
            $this->positions[$role][$id] = $this->position;
        }
    }

    public function position(string $role, DOMElement $node): int
    {
        return $this->positions[$role][spl_object_id($node)] ?? 0;
    }

    public function staticValue(DOMElement $node, string $attribute): string
    {
        $value = $node->getAttribute($attribute);
        // Strip conditional fragments as a whole. A sentinel embedded in a class token
        // invalidates that token; adjacent, wholly literal class names remain available.
        $tokens = preg_split($this->pattern, $value, flags: PREG_SPLIT_DELIM_CAPTURE);
        $literal = '';
        $depth = 0;
        foreach ($tokens as $i => $token) {
            if ($i % 2 === 0) {
                if (!$depth) {
                    $literal .= $token;
                }
                continue;
            }
            [$kind] = $this->events[(int)$token];
            if (str_starts_with($kind, 'end')) {
                $depth = max(0, $depth - 1);
            } elseif (isset($this->closers[$kind])) {
                $depth++;
            }
            $literal .= '?';
        }
        return trim($literal);
    }

    /** The only attributes that can leave this parser as presentation data. */
    public function descriptor(DOMElement $node): array
    {
        $out = ['tag' => $node->tagName];
        $class = ThemeStructure::classes($this->staticValue($node, 'class'));
        if ($class !== '') {
            $out['class'] = $class;
        }
        $id = $this->staticValue($node, 'id');
        if (ThemeStructure::identifier($id)) {
            $out['id'] = $id;
        }
        return $out;
    }
}
