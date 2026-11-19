@extends('admin.layout')
@section('title', 'フォーム管理')
@section('main')
<header class="head"><div><div class="kicker">WORKSPACE</div><h1>{{ $archived ? '保管済みフォーム' : 'フォーム管理' }}</h1></div>
@if(in_array(auth()->user()->role, ['admin','editor']))
<a href="{{ route('admin.create') }}" class="btn primary">＋ フォームを作成</a>
@endif</header>
@if(auth()->user()->role === 'admin')
<nav class="anchors" aria-label="フォームの保管状態"><a href="{{ route('admin.forms') }}" @if(!$archived) aria-current="page" @endif>使用中</a><a href="{{ route('admin.forms',['archived'=>1]) }}" @if($archived) aria-current="page" @endif>保管済み</a></nav>
@endif
<div class="section-head"><h2>フォーム一覧</h2><span class="count">{{ number_format($forms->total()) }} 件</span></div>
<div class="table-wrap form-list">
<table><thead><tr><th>フォーム名 / 公開パス</th><th>状態</th><th>受信件数</th><th>更新日</th><th aria-label="操作"></th></tr></thead>
<tbody>
@forelse($forms as $form)
<tr>
    <td><strong>{{ $form->name }}</strong><small class="path">/{{ $form->slug }}</small></td>
    <td><span @class(['pill', 'good' => $form->published])>{{ $archived ? '保管済み' : ($form->published ? '公開中' : '下書き') }}</span>
    @if($form->draft_definition)<small>未公開の編集あり</small>@endif
    @if($form->definition['issues'] ?? [])<small class="warn">移行の要確認事項あり</small>@endif</td>
    <td><a class="text-link" href="{{ route('admin.history', ['form_id' => $form->id]) }}">{{ number_format($form->submissions_count) }} 件</a></td>
    <td><time>{{ $form->updated_at?->format('Y.m.d') }}</time></td>
    <td><div class="actions">@if($archived)
        <form method="post" action="{{ route('admin.restore', $form) }}">@csrf<input type="hidden" name="version" value="{{ app(\App\Services\Admin\FormEditor::class)->version($form) }}"><button class="btn compact">下書きに戻す</button></form>
        @elseif(in_array(auth()->user()->role, ['admin','editor']))
        <a class="btn compact" href="{{ route('admin.edit', $form) }}">フォーム編集</a>
        <a class="btn compact" href="{{ route('admin.edit', $form) }}#fields">項目編集</a>
        <form method="post" action="{{ route('admin.copy',$form) }}">@csrf<button class="btn compact">複製</button></form>
        @endif @if($form->published)<a class="text-link" href="{{ $form->publicPath() }}">公開画面 ↗</a>@endif
    </div></td>
</tr>
@empty
<tr><td colspan="5" class="empty"><strong>{{ $archived ? '保管済みのフォームはありません' : 'フォームはまだありません' }}</strong></td></tr>
@endforelse
</tbody></table></div>
@include('admin.pagination', ['page' => $forms])
@endsection
