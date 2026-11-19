<?php

namespace App\View;

use App\Services\ThemeStructure;
use Illuminate\Support\HtmlString;
use Illuminate\View\ComponentAttributeBag;

/** Renders validated presentation nodes around MailForm's own controls and actions. */
final class PublicFormTheme
{
    private const DEFAULT_NODES = [
        'page' => ['main'], 'title' => ['h1'], 'thanks_page' => ['main'],
        'steps' => ['ol', 'steps'], 'step' => ['li'], 'description' => ['p', 'message'],
        'form' => ['form'], 'row' => ['fieldset'], 'label' => ['legend'], 'required' => ['small'],
        'above' => ['p', 'hint'], 'below' => ['p', 'hint'], 'error' => ['p', 'problem'],
        'choices' => ['div', 'choices'], 'choice_label' => ['label', 'choice'],
        'confirm_row' => ['dl'], 'confirm_label' => ['dt'], 'confirm_field' => ['dd'],
        'confirm_button' => ['button', 'primary'], 'back_button' => ['button', 'secondary'],
        'send_button' => ['button', 'primary'], 'thanks_message' => ['p', 'message'],
    ];

    private const CONFIRM_SLOTS = [
        'confirm_rows' => 'rows', 'confirm_row' => 'row', 'confirm_label' => 'label', 'confirm_field' => 'field',
    ];

    private array $structure;
    private array $usedIds = [];
    private array $controls = [];
    private array $matches = [];
    private array $optionIds = [];
    private array $errorIds = [];
    private array $groupIds = [];
    public readonly ?string $formId;

    public function __construct(array $structure, array $controls = [])
    {
        $this->structure = ThemeStructure::clean($structure);
        // Reserve authored control IDs before choosing IDs for options, errors and imported wrappers.
        $controlIds = array_fill_keys(array_column($controls, 'id'), true);
        foreach ($controls as $key => $control) {
            $sourceId = (string) $control['id'];
            $authored = !empty($control['source']['html_id']) && $control['type'] === $control['source']['form_type'];
            $slot = in_array($control['type'], ['radio', 'checkbox'], true)
                ? $this->choiceSlot($control['type'], 'choices') : $this->controlSlot($control);
            $themeId = $this->sourceId($slot);
            $id = !$authored && !isset($controlIds[$themeId]) ? $this->claimId($themeId) : null;
            $this->controls[$key] = $id ?? $this->uniqueId(self::validId($sourceId) ? $sourceId : $key);
            $this->matches[$sourceId] ??= $this->controls[$key];
        }
        foreach ($controls as $key => $control) {
            $id = $this->controls[$key];
            if (in_array($control['type'], ['radio', 'checkbox'], true)) {
                foreach (array_keys($control['options']) as $index) {
                    $this->optionIds[$key][$index] = $this->uniqueId($id.'-'.$index);
                }
            }
            if ($key !== $control['field'] && !isset($this->groupIds[$control['field']])) {
                $groupId = (string) ($control['source']['html_id'] ?? '');
                $this->groupIds[$control['field']] = $this->claimId($groupId);
            }
        }
        $this->formId = $this->claimId($this->sourceId('form'))
            ?? ($this->buttonsPosition() === 'inside_form' ? null : $this->uniqueId('mailform-entry'));
    }

    private static function validId(string $id): bool
    {
        return preg_match('/\A[-\p{L}\p{N}_][\p{L}\p{M}\p{N}_.:-]{0,189}\z/uD', $id) === 1;
    }

    private function sourceId(string $slot): string
    {
        $nodes = $this->structure[$slot] ?? [];
        return $nodes ? ($nodes[array_key_last($nodes)]['id'] ?? '') : '';
    }

    public function claimId(string $id): ?string
    {
        if (!self::validId($id) || isset($this->usedIds[$id])) {
            return null;
        }
        $this->usedIds[$id] = true;
        return $id;
    }

    private function uniqueId(string $base): string
    {
        $id = $base;
        $suffix = 1;
        while (isset($this->usedIds[$id])) {
            $id = $base.'-'.++$suffix;
        }
        $this->usedIds[$id] = true;
        return $id;
    }

    public function controlId(string $key): string
    {
        return $this->controls[$key];
    }

    public function optionId(string $key, int $index): string
    {
        return $this->optionIds[$key][$index];
    }

    public function errorId(string $key): string
    {
        return $this->errorIds[$key] ??= $this->targetId('error', 'err-'.$this->controlId($key));
    }

    /** Keep an imported message's CSS ID when a label or control needs to reference it. */
    public function targetId(string $slot, string $fallback): string
    {
        return $this->claimId($this->sourceId($slot)) ?? $this->uniqueId($fallback);
    }

    public function groupId(string $field): ?string
    {
        return $this->groupIds[$field] ?? null;
    }

    public function matchId(string $original): ?string
    {
        return $this->matches[ltrim($original, '#')] ?? null;
    }

    public function has(string $slot): bool
    {
        return isset($this->structure[$slot]);
    }

    public function hasFormStructure(): bool
    {
        return (bool) array_intersect_key($this->structure, array_flip([
            'form', 'form_outer', 'row', 'rows', 'label', 'field', 'confirm_rows', 'confirm_row',
            'confirm_label', 'confirm_field', 'confirm_value', 'buttons', 'buttons_position',
        ]));
    }

    public function buttonsPosition(): string
    {
        return $this->structure['buttons_position'] ?? 'inside_form';
    }

    public function requiredFirst(): bool
    {
        return ($this->structure['required_position'] ?? 'after') === 'before';
    }

    public function separateKana(): bool
    {
        return ($this->structure['kana_rows'] ?? 'together') === 'separate';
    }

    public function accessibleSteps(): bool
    {
        return ($this->structure['steps_visibility'] ?? '') === 'accessible';
    }

    public function orderAddress(iterable $group): iterable
    {
        if (!isset($this->structure['address_order'])) {
            return $group;
        }
        $positions = array_flip($this->structure['address_order']);
        return collect($group)->sortBy(fn ($control) => $positions[$control['address_part'] ?? ''] ?? PHP_INT_MAX);
    }

    public function controlSlot(array $control): string
    {
        $part = match ($control['source']['form_type']) {
            'zp_address' => $control['address_part'] ?? null,
            'first_last_name', 'name_and_furigana' => $control['key'],
            default => null,
        };
        return $part && $this->has($part) ? $part : $control['type'];
    }

    public function choiceSlot(string $type, string $slot): string
    {
        $variant = $type.'_'.$slot;
        return $this->has($variant) ? $variant : $slot;
    }

    private function nodes(string $slot): array
    {
        if ($this->has($slot)) {
            return $this->structure[$slot];
        }
        if ($slot === 'step_current') {
            return $this->nodes('step');
        }
        if (isset(self::CONFIRM_SLOTS[$slot], $this->structure[self::CONFIRM_SLOTS[$slot]])) {
            return $this->structure[self::CONFIRM_SLOTS[$slot]];
        }
        if (in_array($slot, ['row', 'confirm_row'], true)
            && in_array($this->tag($slot === 'row' ? 'rows' : 'confirm_rows'), ['table', 'tbody', 'thead', 'tfoot'], true)) {
            return [['tag' => 'tr']];
        }
        if (in_array($slot, ['label', 'field', 'confirm_label', 'confirm_field'], true)) {
            $rowSlot = str_starts_with($slot, 'confirm_') ? 'confirm_row' : 'row';
            if ($this->tag($rowSlot) === 'tr') {
                return [['tag' => str_ends_with($slot, 'label') ? 'th' : 'td']];
            }
        }
        if (in_array($slot, ['label', 'field'], true) && $this->tag('row') === 'dl') {
            return [['tag' => $slot === 'label' ? 'dt' : 'dd']];
        }
        if ($slot === 'label' && $this->tag('row') !== 'fieldset') {
            return [['tag' => 'div']];
        }
        $default = self::DEFAULT_NODES[$slot] ?? null;
        return $default ? [array_filter(['tag' => $default[0], 'class' => $default[1] ?? null])] : [];
    }

    public function tag(string $slot): ?string
    {
        $nodes = $this->nodes($slot);
        return $nodes ? $nodes[array_key_last($nodes)]['tag'] : null;
    }

    public function containsTag(string $slot, string $tag): bool
    {
        return in_array($tag, array_column($this->nodes($slot), 'tag'), true);
    }

    /** Generated attributes belong to the leaf. Only class and a unique ID come from the theme. */
    public function open(string $slot, array $attributes = [], ?string $nativeTag = null): HtmlString
    {
        $nodes = $this->nodes($slot);
        if ($nativeTag !== null) {
            if (!$nodes) {
                $nodes[] = ['tag' => $nativeTag];
            } else {
                $nodes[array_key_last($nodes)]['tag'] = $nativeTag;
            }
        }
        $html = '';
        foreach ($nodes as $index => $node) {
            $attrs = $index === array_key_last($nodes) ? $attributes : [];
            if (!array_key_exists('id', $attrs) && isset($node['id'])) {
                $attrs['id'] = $this->claimId($node['id']);
            }
            $classes = trim(($node['class'] ?? '').' '.ThemeStructure::classes($attrs['class'] ?? ''));
            $attrs['class'] = $classes !== '' ? $classes : null;
            $bag = (string) (new ComponentAttributeBag())->merge($attrs);
            $html .= '<'.$node['tag'].($bag !== '' ? ' '.$bag : '').'>';
        }
        return new HtmlString($html);
    }

    public function close(string $slot, ?string $nativeTag = null): HtmlString
    {
        $tags = array_column($this->nodes($slot), 'tag');
        if ($nativeTag !== null) {
            $tags[$tags ? array_key_last($tags) : 0] = $nativeTag;
        }
        $html = '';
        foreach (array_reverse($tags) as $tag) {
            if ($tag !== 'input' && $tag !== 'br') {
                $html .= '</'.$tag.'>';
            }
        }
        return new HtmlString($html);
    }

    public function button(string $slot, string $label, array $attributes): HtmlString
    {
        $tag = $this->tag($slot) === 'input' ? 'input' : 'button';
        if ($this->has($slot)) {
            $attributes[ThemeStructure::BUTTON_ATTRIBUTES[substr($slot, 0, -strlen('_button'))]] = true;
        }
        if ($tag === 'input') {
            $attributes['value'] = $label;
        }
        return new HtmlString($this->open($slot, ['type' => 'submit'] + $attributes, $tag)
            .($tag === 'button' ? e($label) : '').$this->close($slot, $tag));
    }
}
