<?php

namespace App\Services\ThemeAssets;

final class StylesheetReport
{
    private array $groups = [];
    public ?string $backgroundLogo = null;

    public function __construct(public readonly bool $linkedLogo = false, public readonly array $actionAttributes = []) {}

    public function record(string $path, array $errors): void
    {
        foreach ($errors as [$code, $line, $column]) {
            $this->groups[$code] ??= ['count' => 0, 'example' => "{$path}:{$line}:{$column}"];
            $this->groups[$code]['count']++;
        }
    }

    public function warnings(): array
    {
        $warnings = [];
        foreach ($this->groups as $code => ['count' => $count, 'example' => $example]) {
            $kind = match ($code) {
                'unrecognized-property' => '未対応のプロパティ',
                'bad-value-for-property' => '未対応のプロパティ値',
                'missing-value-for-property' => 'プロパティ値の欠落',
                'invalid-selector-list' => '未対応のセレクター',
                'invalid-media-query' => '未対応のメディア条件',
                'unsupported-function-or-image' => '未対応の関数または画像参照',
                'unrecognized-rule' => '未対応の規則',
                'stylesheet-too-complex' => '規則数または記述の複雑さの上限超過',
                default => $code,
            };
            $warnings[] = "CSS の除外（{$kind}）{$count} 件（例 {$example}）。";
        }
        return $warnings;
    }
}
