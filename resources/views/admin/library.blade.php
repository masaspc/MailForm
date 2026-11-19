@extends('admin.layout')
@section('title','項目')
@section('main')
<h1>{{ $template ? '共通項目の編集' : '項目' }}</h1>
@if(!$template)
<p>フォームを作成するときに選べる項目です。登録した設定は、各フォームにコピーされます。</p>
<h2>標準項目</h2>
<details class="panel"><summary>標準項目を表示</summary>
<ul>@foreach(array_slice($all,0,count($all)-count($custom)) as $field)<li>{{ $field['title'] }}（{{ \App\Services\Admin\FormEditor::TYPES[$field['form_type']] ?? $field['form_type'] }}）</li>@endforeach</ul>
</details>
<h2>登録した共通項目</h2>
@forelse($custom as $item)
<div class="panel actions"><a href="{{ route('admin.fields.edit',$item) }}">{{ $item->definition['title'] }}</a>
<form method="post" action="{{ route('admin.fields.destroy',$item) }}">@csrf @method('DELETE')<button class="plain danger">削除</button></form></div>
@empty<p>まだ登録されていません。</p>@endforelse
<h2>共通項目の追加</h2>
@endif
<form method="post" action="{{ $template ? route('admin.fields.update',$template) : route('admin.fields.store') }}">
@csrf
@if($template)@method('PUT')<input type="hidden" name="version" value="{{ hash('sha256',serialize($template->definition)) }}">@endif
@include('admin.field',['index'=>0,'field'=>old('fields.0',$template?->definition ?? [])])
<button class="primary" type="submit">保存する</button>
</form>
@endsection
