@extends('admin.layout')
@section('title','テーマ管理')
@section('main')
<h1>テーマ管理</h1>
<div class="table-wrap"><table><thead><tr><th>テーマ名</th><th>識別名</th><th></th></tr></thead>
<tbody>@foreach($themes as $slug=>$name)<tr><td>{{ $name }}</td><td>{{ $slug }}</td><td><a href="{{ route('admin.themes.edit',$slug) }}">編集</a></td></tr>@endforeach</tbody></table></div>
<h2>{{ $editing ? 'テーマの編集' : 'テーマの追加' }}</h2>
<form class="panel" method="post" action="{{ route('admin.themes.save') }}">@csrf
<input type="hidden" name="version" value="{{ $editing?->exists ? hash('sha256', serialize($editing->getAttributes())) : '' }}">
<div class="field-grid">
<label>テーマ名<input name="name" required value="{{ old('name',$editing?->name) }}"></label>
<label>識別名<input name="slug" required pattern="[a-zA-Z][a-zA-Z0-9_\-]*" @readonly($editing) value="{{ old('slug',$editing?->slug) }}"></label>
<label>レイアウト<select name="layout">@foreach(\App\Services\Admin\FormEditor::THEMES as $key=>$label)<option value="{{ $key }}" @selected(old('layout',$editing?->layout)===$key)>{{ $label }}</option>@endforeach</select></label>
</div>
<label>ヘッダー<textarea rows="4" name="header">{{ old('header',$editing?->header) }}</textarea></label>
<label>フッター<textarea rows="4" name="footer">{{ old('footer',$editing?->footer) }}</textarea></label>
<p>段落・強調・リスト・リンクを使用できます。変更はこのテーマを使う全フォームに反映されます。</p>
<button type="submit" class="primary">保存する</button> @if($editing)<a href="{{ route('admin.themes') }}">新規テーマを追加</a>@endif
</form>
@endsection
