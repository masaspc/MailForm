@extends('admin.layout')
@section('title', $editing ? $editing->name.' · テーマ管理' : 'テーマ管理')
@section('main')
@php
    $layouts = \App\Services\Admin\FormEditor::THEMES;
    $report = $editing?->import_report;
    $version = $editing?->exists ? $editing->editorVersion() : '';
@endphp
@if($editing)
<a class="back" href="{{ route('admin.themes') }}">← テーマ一覧</a>
<header class="head"><h1>{{ $editing->name }}</h1>
@if($report !== null)<span @class(['pill', 'good' => !$editing->pendingReview()])>{{ $editing->pendingReview() ? '照合待ち' : '照合済み' }}</span>@endif
</header>
<nav class="anchors" aria-label="テーマ編集セクション"><a href="#settings">表示設定</a>
@if($editing->exists)<a href="#structure">部品の構造</a><a href="#assets">CSS・画像</a>@endif
@if($report !== null)<a href="#report">移行時の差分</a><a href="#review">プレビューと公開</a>@endif</nav>
@else
<header class="head"><h1>テーマ管理</h1><a class="btn primary" href="#settings">＋ テーマを追加</a></header>
<div class="table-wrap"><table><thead><tr><th>テーマ名</th><th>識別名</th><th>移行確認</th><th>操作</th></tr></thead>
<tbody>@foreach($themes as $slug => $name)
<tr><td>{{ $name }}</td><td>{{ $slug }}</td>
<td>@if($reviews->has($slug))<span @class(['pill', 'good' => $reviews[$slug] !== null])>{{ $reviews[$slug] ? '照合済み' : '照合待ち' }}</span>@else — @endif</td>
<td><a class="text-link" href="{{ route('admin.themes.edit', $slug) }}">編集</a></td></tr>
@endforeach</tbody></table></div>
@endif

<form class="panel stack" id="settings" method="post" action="{{ route('admin.themes.save') }}">@csrf
<h2>{{ $editing ? '表示設定' : 'テーマを追加' }}</h2>
<input type="hidden" name="version" value="{{ old('version', $version) }}">
<div class="field-grid">
<label>テーマ名<input name="name" required maxlength="150" value="{{ old('name', $editing?->name) }}"></label>
<label>識別名<input name="slug" required maxlength="80" pattern="[a-zA-Z][a-zA-Z0-9_\-]*" @readonly($editing) value="{{ old('slug', $editing?->slug) }}"></label>
<label>レイアウト<select name="layout">@foreach($layouts as $key => $label)<option value="{{ $key }}" @selected(old('layout', $editing?->layout) === $key)>{{ $label }}</option>@endforeach</select></label>
@if($images)
<label>ヘッダーのロゴ<select name="logo"><option value="">画像なし</option>
@foreach(array_keys($images) as $path)<option value="{{ $path }}" @selected(old('logo', $editing?->logo) === $path)>{{ $path }}</option>@endforeach
</select></label>@endif
</div>
<label>ヘッダー<textarea rows="4" name="header">{{ old('header', $editing?->header) }}</textarea></label>
<label>フッター<textarea rows="4" name="footer">{{ old('footer', $editing?->footer) }}</textarea></label>
<p>段落・強調・リスト・リンクを使用できます。@if($images)登録画像は <code>&lt;img src="theme:{{ array_key_first($images) }}" alt="画像の説明"&gt;</code> で挿入できます。@endif
@if($report !== null)レイアウト、部品の構造、素材を変更すると、移行テーマは再び照合待ちになります。@else 変更はこのテーマを使うフォームに反映されます。@endif</p>
<div><button type="submit" class="primary">表示設定を保存</button></div>
</form>

@if($editing?->exists)
@include('admin.theme-structure')
<section class="panel stack" id="assets"><h2>CSS・画像</h2>
@if($paths)
<ul class="theme-files">
@foreach($paths as $path)<li><a class="text-link" href="{{ route('admin.themes.edit', ['slug' => $editing->slug, 'asset' => $path]) }}#assets" @if($path === $selected) aria-current="true" @endif>{{ $path }}</a></li>@endforeach
</ul>
<h3>{{ $selected }}</h3>
@if($isCss)
<form class="stack" method="post" action="{{ route('admin.themes.asset', $editing->slug) }}">@csrf
<input type="hidden" name="version" value="{{ old('version', $version) }}"><input type="hidden" name="path" value="{{ $selected }}">
@if($css === null)<p class="alert">保存されたCSSを読み込めません。内容を入力するか、ファイルを再登録してください。</p>@endif
<label>CSSの内容<textarea class="theme-code" name="css" rows="8" required spellcheck="false">{{ old('path') === $selected && old('css') !== null ? old('css') : $css }}</textarea></label>
<div><button class="btn">CSSを保存</button></div></form>
@else
<img class="theme-image" src="{{ \App\Services\ThemeAssets::url($editing->slug, $selected) }}" alt="{{ $selected }} のプレビュー">
@endif
<form class="stack" method="post" enctype="multipart/form-data" action="{{ route('admin.themes.asset', $editing->slug) }}">@csrf
<input type="hidden" name="version" value="{{ old('version', $version) }}"><input type="hidden" name="path" value="{{ $selected }}">
<label>差し替えるファイル<input type="file" name="file" accept=".{{ pathinfo($selected, PATHINFO_EXTENSION) }}" required></label>
<div><button class="btn">このファイルを差し替え</button></div></form>
@endif
<details @if(!$paths) open @endif><summary>CSS・画像を追加</summary>
<form class="stack" method="post" enctype="multipart/form-data" action="{{ route('admin.themes.asset', $editing->slug) }}">@csrf
<input type="hidden" name="version" value="{{ old('version', $version) }}">
<label>テーマ内の保存先<input name="path" placeholder="例：images/logo.png" required maxlength="512" value="{{ old('path') !== $selected ? old('path') : '' }}"></label>
<label>ファイル<input type="file" name="file" accept=".css,.png,.gif,.jpg,.jpeg,.webp,.svg" required>
<small>CSSは256 KiB、SVGは512 KiB、PNG・GIF・JPEG・WebPは4 MiBまでです。CSSで使う画像は先に追加してください。</small></label>
<div><button class="btn">ファイルを保存</button></div></form></details>
</section>
@elseif($editing)
<p>表示設定を保存すると、部品の構造を編集し、CSS・画像を追加できます。</p>
@endif

@if($report !== null)
<section class="panel stack" id="report"><h2>移行時の差分</h2>
@php($sourceLayout = $report['layout'] ?? $editing->layout)
<p>比較した標準テーマは <strong>{{ $layouts[$sourceLayout] ?? $sourceLayout }}</strong> です。@isset($report['score'])同じ内容のファイルが {{ number_format($report['score']) }} 件ありました。@endisset</p>
<details id="structure-diagnosis"><summary>部品ごとの推定結果と適用状況（自動推定 {{ count($inferred) }} 件・個別の推定なし {{ count(array_diff_key($labels, $inferred)) }} 件）</summary>
    <div class="table-wrap"><table>
    <thead><tr><th scope="col">部品</th><th scope="col">取込時の推定元</th><th scope="col">現在の設定</th></tr></thead><tbody>
    @foreach($labels as $slot => $label)
        <tr><td><a class="text-link" href="{{ route('admin.themes.edit', ['slug' => $editing->slug, 'component' => $slot]) }}#structure">{{ $label }}</a></td>
        <td>@if(isset($inferred[$slot]))<code>{{ $report['structure_sources'][$slot] ?? '自動推定' }}</code>@else 共通の部品または標準レイアウト @endif</td><td>{{ $states[$slot] }}</td></tr>
    @endforeach
    </tbody></table></div>
</details>
@foreach(['changed' => '内容を変更', 'added' => '独自に追加', 'removed' => '標準テーマから削除'] as $kind => $label)
@php($files = $report['changes'][$kind] ?? [])
<details><summary>{{ $label }}（{{ count($files) }} 件）</summary>
@if($files)<ul class="theme-files">@foreach($files as $file)<li><code>{{ $file }}</code></li>@endforeach</ul>@endif</details>
@endforeach
@if($report['behaviors'] ?? [])<div><h3>動作の確認・再実装が必要な差分</h3>
<ul>@foreach($report['behaviors'] as $item)<li>{{ $item }}</li>@endforeach</ul></div>@endif
@if($report['warnings'] ?? [])<div><h3>変換時の注意事項</h3>
<ul>@foreach($report['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul></div>@endif
</section>
<section class="panel stack" id="review"><h2>プレビューと公開</h2>
@if($forms->isNotEmpty())
<ul>@foreach($forms as $form)<li>{{ $form->name }}（/{{ $form->slug }}）
<a class="text-link" href="{{ route('admin.preview', ['form' => $form, 'version' => 'current']) }}">入力画面</a> ·
<a class="text-link" href="{{ route('admin.preview', ['form' => $form, 'version' => 'current', 'stage' => 'confirm']) }}">確認画面</a> ·
<a class="text-link" href="{{ route('admin.preview', ['form' => $form, 'version' => 'current', 'stage' => 'thanks']) }}">完了画面</a>
@if($form->awaiting_theme_publication) · テーマ確認後に公開 @endif</li>@endforeach</ul>
@else<p>このテーマを使用しているフォームはありません。</p>@endif
@if($editing->pendingReview())
<p>プレビューでロゴ・画像・文言・レイアウトを照合してください。確認を完了すると、移行元で公開されていて診断を通過した公開待ちフォームの受付を開始します。</p>
<form class="stack" method="post" action="{{ route('admin.themes.approve', $editing->slug) }}">@csrf
<input type="hidden" name="version" value="{{ $version }}">
<label class="check"><input type="checkbox" name="confirmed" value="1" required>移行時の差分と表示を確認しました</label>
<div><button class="primary">確認を完了して対象フォームを公開</button></div></form>
@else
<p>確認日　{{ $editing->reviewed_at->format('Y.m.d H:i') }}</p>
@endif
</section>
@endif
@endsection
