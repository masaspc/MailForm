@php
    use App\Services\ThemeStructure;
    $value = $structure[$component] ?? null;
    $retry = old('slot') === $component && old('mode') === 'edit';
    $sources = $report['structure_sources'] ?? [];
    $order = $retry ? old('order', []) : ($value ?? ['zip', 'pref', 'address']);
@endphp
<section class="panel stack" id="structure">
<h2>部品の構造</h2>
<form class="filter" method="get" action="{{ route('admin.themes.edit', $editing->slug) }}#structure">
    <label>編集する部品<select name="component">
    @foreach($labels as $slot => $label)
        <option value="{{ $slot }}" @selected($component === $slot)>{{ $label }}（{{ $states[$slot] }}）</option>
    @endforeach
    </select></label>
    <button class="btn">部品を表示</button>
</form>
@if($report !== null)
<a class="text-link" href="#structure-diagnosis">移行時の差分で、全部品の推定結果を見る</a>
@endif
<form class="stack" method="post" action="{{ route('admin.themes.component', $editing->slug) }}">@csrf
    <input type="hidden" name="version" value="{{ $retry ? old('version', $version) : $version }}">
    <input type="hidden" name="slot" value="{{ $component }}">
    <div><h3>{{ $labels[$component] }} <span class="pill">{{ $states[$component] }}</span></h3>
        @if(isset($sources[$component]))<p class="help">推定元　<code>{{ $sources[$component] }}</code></p>@endif
        @if(!isset($value))<p class="help">この部品は標準の表示を継承します。種類別の選択肢や確認画面の行などは、対応する共通部品の設定も引き継ぎます。</p>@endif
    </div>
@if($component === 'address_order')
    <p>先に表示する欄から順に選びます。建物名を別欄にする場合は「建物名」を選んでください。ほかの未指定の欄は、フォームで使用する場合に末尾へ続きます。</p>
    <div class="field-grid">
    @for($i = 0; $i < count(ThemeStructure::ADDRESS_PARTS); $i++)
        <label>{{ $i + 1 }} 番目<select name="order[]"><option value="">指定なし</option>
        @foreach(ThemeStructure::ADDRESS_PARTS as $key => $label)
            <option value="{{ $key }}" @selected(is_array($order) && ($order[$i] ?? null) === $key)>{{ $label }}</option>
        @endforeach
        </select></label>
    @endfor
    </div>
@elseif($options)
    <label>{{ $labels[$component] }}<select name="option" required><option value="">選んでください</option>
        @foreach($options as $key => $label)
            <option value="{{ $key }}" @selected(($retry ? old('option') : $value) === $key)>{{ $label }}</option>
        @endforeach
    </select></label>
@else
    @php
        $wrappers = $value ?? [];
        $element = array_pop($wrappers) ?? [];
        if ($retry) {
            $element = old('element');
            $attempt = old('wrappers');
            $wrappers = is_array($attempt) ? array_values(array_filter($attempt, static fn ($row) =>
                is_array($row) && array_filter(\Illuminate\Support\Arr::only($row, ['tag', 'class', 'id'])))) : [];
        }
        $wrapperLimit = ThemeStructure::MAX_DEPTH - 1;
        $wrapperCount = min(count($wrappers), $wrapperLimit);
    @endphp
    <fieldset class="field stack"><legend>部品の要素</legend>
        @include('admin.theme-node', ['prefix' => 'element', 'node' => $element, 'tags' => ThemeStructure::tags($component)])
        <small>クラス名とIDは半角の英字か _ で始め、英数字・-・_ を64文字まで使えます。js-・js_ は動作用に予約されています。</small>
    </fieldset>
    <details @if($wrapperCount) open @endif><summary>部品を囲む要素（{{ $wrapperCount }} 階層）</summary>
    <p class="help">順番の小さい要素が外側です。削除する行はタグ・クラス・IDをすべて空にしてください。</p>
    @for($i = 0; $i < $wrapperCount; $i++)
        @include('admin.theme-wrapper', ['node' => $wrappers[$i]])
    @endfor
    @if($wrapperCount < $wrapperLimit)
        <details @if(!$wrapperCount) open @endif><summary>囲む要素を追加</summary>
        @for($i = $wrapperCount; $i < $wrapperLimit; $i++)
            @if($i === $wrapperCount)
                @include('admin.theme-wrapper', ['node' => []])
            @else
                <details><summary>{{ $i + 1 }} 行目を使う</summary>@include('admin.theme-wrapper', ['node' => []])</details>
            @endif
        @endfor
        </details>
    @endif
    </details>
@endif
    <div class="actions">
        <button type="submit" name="mode" value="edit" class="primary">この部品を保存</button>
        @if(isset($value) || $report !== null)
        <button type="submit" name="mode" value="standard" class="btn" formnovalidate>標準の構造を使う</button>
        @endif
        @if(isset($overrides[$component]))
        <button type="submit" name="mode" value="source" class="btn" formnovalidate>{{ $report !== null ? '取込時の推定に戻す' : '標準を継承する' }}</button>
        @endif
    </div>
    @if($report !== null)<small>手動設定と「標準の構造を使う」で選んだ部品は、通常の再取り込みでも保持されます。</small>@endif
</form>
</section>
