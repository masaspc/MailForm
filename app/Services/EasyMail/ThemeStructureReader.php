<?php

namespace App\Services\EasyMail;

use App\Services\PublicFields;
use App\Services\ThemeStructure;
use DOMElement;
use RuntimeException;

/** Infer the passive markup enclosing MailForm's own values, controls and actions. */
final class ThemeStructureReader
{
    private array $structure = [];
    private array $sources = [];
    private array $buttonStyleHooks = [];

    /** @param array<string,string> $templates UTF-8 files from the scanner's bounded inventory. */
    public function read(array $templates, array &$warnings = []): array
    {
        $this->structure = $this->sources = $this->buttonStyleHooks = [];
        $docs = [];
        foreach (['layout', 'index', 'form', 'thanks'] as $part) {
            $path = $part.'.blade.php';
            if (!isset($templates[$path])) {
                continue;
            }
            try {
                $docs[$part] = new ThemeStructureDocument($path, ThemeTemplateReader::checkedSource($path, $templates[$path]));
            } catch (RuntimeException $error) {
                $warnings[] = $error->getMessage().' このファイルで推定する部品には標準の表示を使います。';
            }
        }
        $layout = $docs['layout'] ?? null;
        $content = $layout?->marks['content'][0] ?? null;
        if ($content) {
            $this->chain('page', $layout, $content);
        }
        $index = $docs['index'] ?? null;
        $title = $include = null;
        if ($index) {
            $title = $this->title($index);
            $include = $index->marks['form_include'][0] ?? $this->firstTag($index, ['form']);
            $common = $title && $include ? $this->common([$title, $include]) : null;
            if ($common) {
                $this->chain('page', $index, $common, append: true);
            }
            $this->chain('title', $index, $title, $common);
            $body = $include?->tagName === 'form' ? $include->parentNode : $include;
            $this->chain('body', $index, $body, $common);
            $this->nearbyText('description', $index, $common);
            if (!$title && !$include) {
                $warnings[] = "{$index->path}:1 表題やフォームの挿入箇所を特定できず、対応する部品には標準の表示を使います。";
            }
        }
        $doc = $docs['form'] ?? ($include?->tagName === 'form' ? $index : null);
        if ($doc) {
            $form = $this->firstTag($doc, ['form']);
            if ($form) {
                $this->chain('form', $doc, $form, $form->parentNode);
                if ($doc !== $index) {
                    $this->chain('form_outer', $doc, $form->parentNode);
                }
            }
            if (!$title && $heading = $this->title($doc)) {
                $this->chain('title', $doc, $heading, $form?->contains($heading) ? $form : null);
            }
            if (!isset($this->structure['description']) && $description = $doc->marks['description'][0] ?? null) {
                $boundary = $form ? $this->common([$form, $description]) : $description->parentNode;
                $this->chain('description', $doc, $description, $boundary);
            }
            $this->fields($doc, $form ?? $doc->root);
            $this->buttons($doc, $form);
            if (!$form && !isset($this->structure['row'], $this->structure['label'])) {
                $warnings[] = "{$doc->path}:1 フォームや項目の行を特定できず、対応する部品には標準の表示を使います。";
            }
        }
        $stepHints = false;
        foreach (array_filter([$index, $doc, $layout]) as $candidate) {
            $stepHints = $this->steps($candidate) || $stepHints;
            if (isset($this->structure['steps'])) {
                break;
            }
        }
        if (!$stepHints && $index && $title && $include && isset($this->structure['row'])) {
            $this->value('steps_visibility', 'accessible', $index, $include);
        }
        if (isset($docs['thanks'])) {
            $thanks = $docs['thanks'];
            $message = $thanks->marks['thanks_message'][0] ?? null;
            if ($message && $message !== $thanks->root) {
                if ($content) {
                    $this->chain('thanks_page', $layout, $content);
                }
                $this->chain('thanks_page', $thanks, $message->parentNode, append: true);
                $this->chain('thanks_message', $thanks, $message, $message->parentNode);
            } else {
                $warnings[] = "{$thanks->path}:1 完了メッセージの表示要素を特定できず、標準の表示を使います。";
            }
        }
        $structure = ThemeStructure::clean($this->structure);
        return ['structure' => $structure, 'structure_sources' => array_intersect_key($this->sources, $structure),
            'button_style_hooks' => $this->buttonStyleHooks];
    }

    private function title(ThemeStructureDocument $doc): ?DOMElement
    {
        $headings = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
        foreach ($doc->marks['title'] ?? [] as $node) {
            if ($heading = $this->ancestor($node, $headings)) {
                return $heading;
            }
        }
        return $this->firstTag($doc, ['h1']);
    }

    private function fields(ThemeStructureDocument $doc, DOMElement $form): void
    {
        $controls = $addresses = $unbranchedAddress = $names = [];
        $valueNodes = $doc->marks['confirm_value'] ?? [];
        $errors = $doc->marks['error'] ?? [];
        foreach ($doc->elements as $node) {
            if (!$form->contains($node)) {
                continue;
            }
            $signature = $doc->staticValue($node, 'class');
            if (preg_match('/(?:^|[\s_-])(?:conf(?:irm)?[_-](?:msg|value)|answer[_-]value)(?:$|[\s_-])/i', $signature)) {
                $valueNodes[] = $node;
            }
            if (preg_match('/(?:^|[\s_-])(?:err(?:or)?[_-](?:msg|message|control)|invalid-feedback)(?:$|[\s_-])/i', $signature)) {
                $errors[] = $node;
            }
            if (!in_array($node->tagName, ['input', 'select', 'textarea'], true)) {
                continue;
            }
            $type = $this->controlType($doc, $node);
            $branch = $doc->state[$node]['type'] ?? null;
            if (!$branch && in_array($type, ['tel', 'text', 'select'], true) && $part = $this->addressPart($doc, $node)) {
                $unbranchedAddress[$part] ??= $node;
                continue;
            }
            if ($branch === 'zp_address' || $branch === 'address') {
                $part = $this->addressPart($doc, $node);
                if ($part !== null) {
                    $addresses[$part] ??= $node;
                }
            } elseif ($type === 'text' && in_array($branch, ['first_last_name', 'name_and_furigana'], true)) {
                $name = $doc->staticValue($node, 'name');
                $parts = $branch === 'first_last_name' ? ['first_name', 'last_name'] : ['your_name', 'name_and_furigana'];
                if (in_array($name, $parts, true)) {
                    $names[$name] ??= $node;
                }
            } elseif (in_array($type, PublicFields::TYPES, true) && (!$branch || $branch === $type)) {
                // Prefer a control in its declared field branch over a hard-coded sample.
                if (!isset($controls[$type]) || (!$this->typed($doc, $controls[$type]) && $branch)) {
                    $controls[$type] = $node;
                }
            }
        }
        if (!$addresses && isset($unbranchedAddress['zip'], $unbranchedAddress['pref'], $unbranchedAddress['address'])) {
            $addresses = $unbranchedAddress;
        } else {
            foreach ($unbranchedAddress as $node) {
                $controls[$this->controlType($doc, $node)] ??= $node;
            }
        }
        $host = $this->marked($doc->marks['field_host'] ?? [], $form);
        $anchor = $controls['text'] ?? $host ?? (reset($controls) ?: reset($addresses) ?: reset($names) ?: null);
        if (!$anchor) {
            $this->confirmation($doc, $form, null, null, null, $valueNodes);
            return;
        }
        $label = $this->fieldLabel($doc, $anchor, $form);
        $row = $label ? $this->common([$label, $anchor]) : null;
        if ($row === $label) {
            $row = $row->parentNode;
        }
        if (!$row || $row === $form || $row === $doc->root) {
            $row = $this->ancestor($anchor->parentNode, ['fieldset', 'tr', 'dl'], $form);
        }
        $field = null;
        if ($row) {
            $rows = $doc->state[$label ?? $anchor]['rows'] ?? $row->parentNode;
            $this->chain('rows', $doc, $rows, $form);
            $this->chain('row', $doc, $row, $rows);
            $this->chain('label', $doc, $label, $row);
            if ($label) {
                $required = $this->marked($doc->marks['required'] ?? [], $label)
                    ?? $this->marked($doc->marks['required'] ?? [], $row);
                if ($required && $required !== $row && $required !== $label) {
                    $boundary = $label->contains($required) ? $label : $row;
                    $this->chain('required', $doc, $required, $boundary);
                    if ($position = $doc->position('label', $label)) {
                        $order = $doc->position('required', $required) < $position ? 'before' : 'after';
                        $this->value('required_position', $order, $doc, $required);
                    }
                }
            }
            $field = $this->marked($doc->marks['field_host'] ?? [], $row)
                ?? $this->ancestor($anchor->parentNode, ['dd', 'td'], $row);
            if (!$field) {
                $siblings = array_filter([...array_values($controls), ...array_values($addresses), ...$errors, ...$valueNodes,
                    ...($doc->marks['above'] ?? []), ...($doc->marks['below'] ?? [])], $row->contains(...));
                $field = count($siblings) > 1 ? $this->common($siblings) : $anchor->parentNode;
                if ($field === $anchor) {
                    $field = $anchor->parentNode;
                }
            }
            $this->chain('field', $doc, $field, $row);
            foreach (['above', 'below'] as $slot) {
                $this->nearbyText($slot, $doc, $row, $field);
            }
            $error = $this->marked($errors, $row);
            $this->chain('error', $doc, $error, $field?->contains($error) ? $field : $row);
        }
        $choiceShapes = [];
        foreach ($controls as $type => $node) {
            $boundary = $field?->contains($node) ? $field : ($row?->contains($node) ? $row : $node->parentNode);
            if ($type === 'radio' || $type === 'checkbox') {
                $choiceShapes[$type] = $this->choice($doc, $type, $node, $boundary);
            } else {
                $this->chain($type, $doc, $node, $boundary);
            }
        }
        $this->choiceSlots($doc, $choiceShapes);
        foreach ($addresses as $type => $node) {
            $boundary = $field?->contains($node) ? $field : ($row?->contains($node) ? $row : $node->parentNode);
            $this->chain($type, $doc, $node, $boundary);
        }
        if ($addresses) {
            $this->value('address_order', array_keys($addresses), $doc, reset($addresses));
        }
        foreach ($names as $part => $node) {
            $boundary = $field?->contains($node) ? $field : $this->ancestor($node->parentNode, ['dd', 'td', 'fieldset'], $form);
            $this->chain($part, $doc, $node, $boundary ?? $node->parentNode);
        }
        if (isset($names['your_name'], $names['name_and_furigana'])) {
            $first = $this->ancestor($names['your_name'], ['dl', 'tr', 'fieldset'], $form);
            $second = $this->ancestor($names['name_and_furigana'], ['dl', 'tr', 'fieldset'], $form);
            if ($first && $second) {
                $this->value('kana_rows', $first === $second ? 'together' : 'separate', $doc, $second);
            }
        }
        $this->confirmation($doc, $form, $row, $field, $label, $valueNodes);
    }

    private function controlType(ThemeStructureDocument $doc, DOMElement $node): string
    {
        $type = strtolower($doc->staticValue($node, 'type'));
        $branch = $doc->state[$node]['type'] ?? null;
        if ($node->tagName === 'input' && in_array($type, ['', '?', 'text'], true)
            && in_array($branch, ['text', 'email', 'tel', 'date', 'datetime', 'month', 'time', 'number', 'url'], true)) {
            return $branch;
        }
        return match ($node->tagName) {
            'select' => $node->hasAttribute('multiple') || $branch === 'multi_select' ? 'multi_select' : 'select',
            'textarea' => 'textarea',
            default => match ($type) {
                '', 'text' => 'text', 'datetime-local' => 'datetime', default => $type,
            },
        };
    }

    private function typed(ThemeStructureDocument $doc, DOMElement $node): bool
    {
        return !empty($doc->state[$node]['type']);
    }

    private function addressPart(ThemeStructureDocument $doc, DOMElement $node): ?string
    {
        $parts = [
            'building' => 'building(?:_?name)?|address[_-]?(?:2|line2)|apartment|room',
            'zip' => 'zip(?:_?code)?|postal(?:[_-]?code)?',
            'pref' => 'pref(?:ecture)?|address-level1',
            'address' => 'address(?:[_-]?(?:1|line1))?|street-address',
        ];
        foreach (['name', 'id', 'autocomplete', 'class'] as $attr) {
            $value = $doc->staticValue($node, $attr);
            foreach ($parts as $part => $pattern) {
                if (preg_match('~(?:^|[\s_\[-])(?:'.$pattern.')(?:$|[?\s_\]-])~i', $value)) {
                    return $part;
                }
            }
        }
        return null;
    }

    private function fieldLabel(ThemeStructureDocument $doc, DOMElement $anchor, DOMElement $form, bool $confirm = false): ?DOMElement
    {
        $labels = array_filter($doc->marks['label'] ?? [], fn ($node) => !$this->ancestor($node, ['select', 'option', 'textarea', 'button']));
        if (!$labels) {
            $labels = array_filter($doc->elements, static fn (DOMElement $n) => in_array($n->tagName, ['dt', 'th', 'label', 'legend'], true));
        }
        // Find a label in the same row before considering labels in compound-field or stage branches.
        for ($ancestor = $anchor->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
            foreach ($labels as $candidate) {
                if ($ancestor->contains($candidate)
                    && ($confirm || ($doc->state[$candidate]['stage'] ?? '') !== 'confirm')) {
                    return $candidate;
                }
            }
            if ($ancestor === $form) {
                break;
            }
        }
        return null;
    }

    private function confirmation(ThemeStructureDocument $doc, DOMElement $form, ?DOMElement $row, ?DOMElement $field,
        ?DOMElement $label, array $values): void
    {
        $value = null;
        foreach ($values as $candidate) {
            if (($doc->state[$candidate]['stage'] ?? '') === 'confirm') {
                $value = $candidate;
                break;
            }
        }
        $value ??= $this->marked($values, $row ?? $form);
        if (!$value) {
            return;
        }
        if (!$row?->contains($value)) {
            $form = $this->ancestor($value, ['form']) ?? $doc->root;
            $label = $this->fieldLabel($doc, $value, $form, true);
            $row = $label ? $this->common([$label, $value]) : null;
            $field = $this->ancestor($value, ['dd', 'td'], $row);
            if (!$field && $row && $value->parentNode !== $row) {
                $field = $value->parentNode;
            }
        }
        if ($row && $row !== $form && $row !== $doc->root) {
            $list = $doc->state[$label ?? $value]['rows'] ?? $row->parentNode;
            $this->chain('confirm_rows', $doc, $list, $form);
            $this->chain('confirm_row', $doc, $row, $list);
            $this->chain('confirm_label', $doc, $label, $row);
            $this->chain('confirm_field', $doc, $field, $row);
        }
        $this->chain('confirm_value', $doc, $value, $field ?? $row ?? $value->parentNode);
    }

    private function choice(ThemeStructureDocument $doc, string $type, DOMElement $node, DOMElement $field): array
    {
        $label = $this->ancestor($node->parentNode, ['label'], $field);
        $loop = $doc->state[$node]['choices'] ?? null;
        if (!$label && $loop) {
            $label = $this->firstTag($doc, ['label'], $loop);
        }
        $outer = $loop?->parentNode instanceof DOMElement && $field->contains($loop) ? $loop : $field;
        $item = $label?->parentNode ?? $node->parentNode;
        if (!$loop && $item !== $field && $item->parentNode instanceof DOMElement && $field->contains($item->parentNode)) {
            $outer = $item->parentNode;
        }
        $text = $label ? $this->marked($doc->marks['choice_text'] ?? [], $label) : null;
        $this->chain($type, $doc, $node, $label?->contains($node) ? $label : $item);
        return [
            'choices' => [$outer, $field], 'choice' => [$item, $outer],
            'choice_label' => [$label, $label?->parentNode], 'choice_text' => [$text, $label],
        ];
    }

    private function choiceSlots(ThemeStructureDocument $doc, array $shapes): void
    {
        foreach (['choices', 'choice', 'choice_label', 'choice_text'] as $slot) {
            $chains = [];
            foreach ($shapes as $type => $slots) {
                [$node, $boundary] = $slots[$slot];
                $chains[$type] = $this->descriptors($slot, $doc, $node, $boundary);
            }
            if ($chains && count(array_unique($chains, SORT_REGULAR)) === 1) {
                $type = array_key_first($chains);
                $this->chain($slot, $doc, ...$shapes[$type][$slot]);
            } else {
                foreach ($shapes as $type => $slots) {
                    $this->chain($type.'_'.$slot, $doc, ...$slots[$slot]);
                }
            }
        }
    }

    private function buttons(ThemeStructureDocument $doc, ?DOMElement $form): void
    {
        $buttons = [];
        foreach (['confirm', 'back', 'send'] as $key) {
            foreach ($doc->marks[$key.'_button'] ?? [] as $node) {
                if ($button = $this->ancestor($node, ['button', 'input'])) {
                    $buttons[$key] = $button;
                    break;
                }
            }
        }
        foreach ($doc->elements as $node) {
            if ($node->tagName !== 'button' && !($node->tagName === 'input' && in_array($node->getAttribute('type'), ['button', 'submit'], true))) {
                continue;
            }
            $hints = implode(' ', array_map(fn ($k) => $doc->staticValue($node, $k), ['class', 'id', 'name', 'value', 'v-on:click']));
            foreach (['confirm' => 'conf(?:irm)?|review', 'back' => 'back|return|edit', 'send' => 'send|submit'] as $key => $pattern) {
                if (preg_match('~(?:^|[\s_-])(?:'.$pattern.')(?:$|[\s_-])~i', $hints)) {
                    $buttons[$key] ??= $node;
                    break;
                }
            }
        }
        if (!$buttons) {
            return;
        }
        // Keep presentation rules for a hook used exclusively on one known action. Shared state
        // classes (often used to hide an entire stage with JS) must never receive an alias.
        $owners = [];
        foreach ($doc->elements as $element) {
            foreach (preg_split('/\s+/', $doc->staticValue($element, 'class')) as $class) {
                if (preg_match('/\Ajs[-_][a-z0-9_-]{1,60}\z/i', $class)) {
                    $owners[$class] = array_key_exists($class, $owners) ? null : $element;
                }
            }
        }
        foreach ($buttons as $action => $button) {
            foreach (preg_split('/\s+/', $doc->staticValue($button, 'class')) as $class) {
                if (($owners[$class] ?? null) === $button) {
                    $this->buttonStyleHooks[$class] = ThemeStructure::BUTTON_ATTRIBUTES[$action];
                }
            }
        }
        $group = $this->common(array_map(static fn ($button) => $button->parentNode, $buttons));
        $boundary = $doc->root;
        if ($form) {
            $boundary = $this->common([$form, $group]);
            $position = match (true) {
                $form->contains($group) => 'inside_form',
                $boundary === $doc->root && $form->parentNode !== $doc->root => 'after_form_outer',
                default => 'outside_form',
            };
            $this->value('buttons_position', $position, $doc, reset($buttons));
        }
        $this->chain('buttons', $doc, $group, $boundary);
        foreach ($buttons as $key => $button) {
            $this->chain($key.'_wrap', $doc, $button->parentNode, $group);
            $this->chain($key.'_button', $doc, $button, $button->parentNode);
        }
    }

    private function steps(ThemeStructureDocument $doc): bool
    {
        $list = $current = null;
        foreach ($doc->elements as $node) {
            $classes = $doc->staticValue($node, 'class').' '.$doc->staticValue($node, 'id');
            if ($node->getAttribute('aria-current') === 'step') {
                $current ??= $node;
            }
            if (in_array($node->tagName, ['ol', 'ul', 'nav', 'div'], true)
                && (preg_match('/(?:^|[\s_-])(?:steps?|progress|flow|wizard)(?:$|[\s_-])/i', $classes)
                    || preg_match('/step|progress|手順|進捗/i', $node->getAttribute('aria-label')))) {
                $list ??= $node;
            }
        }
        $list ??= $current ? $this->ancestor($current->parentNode, ['ol', 'ul', 'nav']) : null;
        if (!$list && isset($doc->marks['step_input'], $doc->marks['step_confirm'], $doc->marks['step_thanks'])) {
            $candidate = $this->common([$doc->marks['step_input'][0], $doc->marks['step_confirm'][0], $doc->marks['step_thanks'][0]]);
            if ($candidate && in_array($candidate->tagName, ['ol', 'ul', 'nav'], true)) {
                $list = $candidate;
            }
        }
        if (!$list) {
            return isset($doc->marks['step_hint']);
        }
        $container = in_array($list->tagName, ['ol', 'ul'], true) ? $list : ($this->firstTag($doc, ['ol', 'ul'], $list) ?? $list);
        $items = [];
        foreach ($container->childNodes as $child) {
            if ($child instanceof DOMElement && in_array($child->tagName, ['li', 'p', 'div', 'span'], true)) {
                $items[] = $child;
                if ($child->contains($current) || preg_match('/(?:^|[\s_-])(?:active|current)(?:$|[\s_-])/i', $doc->staticValue($child, 'class'))) {
                    $current = $child;
                }
            }
        }
        if (!$items) {
            return true;
        }
        $normal = current(array_filter($items, static fn ($node) => $node !== $current)) ?: $items[0];
        $this->chain('steps', $doc, $container, $list->parentNode);
        $this->chain('step', $doc, $normal, $container);
        $this->chain('step_current', $doc, $current, $container);
        $this->value('steps_visibility', 'visible', $doc, $list);
        return true;
    }

    private function nearbyText(string $slot, ThemeStructureDocument $doc, ?DOMElement $region, ?DOMElement $preferred = null): void
    {
        $node = $this->marked($doc->marks[$slot] ?? [], $region ?? $doc->root);
        if ($node && $node !== $region) {
            $boundary = $preferred?->contains($node) ? $preferred : ($region ?? $node->parentNode);
            $this->chain($slot, $doc, $node, $boundary);
        }
    }

    private function firstTag(ThemeStructureDocument $doc, array $tags, ?DOMElement $inside = null): ?DOMElement
    {
        foreach ($doc->elements as $node) {
            if (in_array($node->tagName, $tags, true) && (!$inside || $inside->contains($node))) {
                return $node;
            }
        }
        return null;
    }

    private function marked(array $nodes, DOMElement $inside): ?DOMElement
    {
        foreach ($nodes as $node) {
            if ($inside->contains($node)) {
                return $node;
            }
        }
        return null;
    }

    private function ancestor(?DOMElement $node, array $tags, ?DOMElement $until = null): ?DOMElement
    {
        for (; $node && $node !== $until; $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null) {
            if (in_array($node->tagName, $tags, true)) {
                return $node;
            }
        }
        return null;
    }

    private function common(array $nodes): ?DOMElement
    {
        $first = reset($nodes);
        for ($common = $first; $common instanceof DOMElement; $common = $common->parentNode) {
            foreach ($nodes as $node) {
                if (!$common->contains($node)) {
                    continue 2;
                }
            }
            return $common;
        }
        return null;
    }

    private function descriptors(string $slot, ThemeStructureDocument $doc, ?DOMElement $node, ?DOMElement $boundary): array
    {
        if (!$node || ($boundary && !$boundary->contains($node))) {
            return [];
        }
        $result = [];
        for ($p = $node; $p instanceof DOMElement && $p !== $boundary && $p !== $doc->root; $p = $p->parentNode) {
            if (in_array($p->tagName, $result ? ThemeStructure::WRAPPERS : ThemeStructure::tags($slot), true)) {
                $result[] = $doc->descriptor($p);
            }
        }
        return array_reverse($result);
    }

    private function chain(string $slot, ThemeStructureDocument $doc, ?DOMElement $node, ?DOMElement $boundary = null, bool $append = false): void
    {
        $chain = $this->descriptors($slot, $doc, $node, $boundary);
        if ($chain) {
            $this->value($slot, $append ? [...($this->structure[$slot] ?? []), ...$chain] : $chain, $doc, $node);
        }
    }

    private function value(string $slot, array|string $value, ThemeStructureDocument $doc, DOMElement $node): void
    {
        $this->structure[$slot] = $value;
        $this->sources[$slot] = $doc->path.':'.max(1, $node->getLineNo());
    }
}
