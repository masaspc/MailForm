@extends('admin.layout')
@section('title','受付詳細')
@section('main')
<a class="back" href="{{ route('admin.history', ['form_id'=>$submission->form_id]) }}">← 受信履歴</a>
<header class="head"><div><div class="kicker">SUBMISSION #{{ $submission->id }}</div><h1>受付詳細</h1></div>
<time>{{ $submission->created_at?->format('Y.m.d H:i:s') }}</time></header>
<div class="panel stack"><h2>{{ data_get($submission->snapshot,'legacy_history.form_name') ?: (data_get($submission->snapshot,'definition.settings.name') ?: $submission->form->name) }}</h2>
<dl class="answers">@forelse($cells as $cell)<div><dt>{{ $cell['title'] }}</dt><dd>{{ $cell['value'] !== '' ? $cell['value'] : '—' }}</dd></div>@empty
    <div><dt>移行元の記録</dt><dd>{{ data_get($submission->snapshot, 'legacy_history.content') ?: '回答はありません。' }}</dd></div>
@endforelse</dl></div>
@if($submission->attachments->isNotEmpty())
<div class="panel stack"><h2>添付ファイル</h2>
@foreach($submission->attachments as $file)<div><a class="text-link" href="{{ route('admin.attachment',[$submission,$file]) }}">{{ $file->name }}</a> <small>{{ number_format($file->size) }} B</small></div>@endforeach
</div>@endif
<div class="panel stack"><h2>配信状況</h2>
@forelse($submission->deliveries as $delivery)<div>@include('admin.status', compact('delivery')) <small>試行 {{ $delivery->attempts }} 回</small></div>
@empty<p>{{ $submission->legacy_id !== null ? '移行した履歴からはメールを配信しません。' : '配信記録はありません。' }}</p>@endforelse
</div>
@if(auth()->user()->role === 'admin')
<details class="panel"><summary>この受信履歴を削除する</summary>
<form class="stack" method="post" action="{{ route('admin.history.destroy', $submission) }}">@csrf @method('delete')
    <p>受付 #{{ $submission->id }} の回答と添付ファイルを削除します。この操作は取り消せません。受付番号と配信の記録は残ります。</p>
    <label class="check"><input type="checkbox" name="confirmed" value="1" required>この受付の回答と添付を削除することを確認しました</label>
    <div><button class="btn danger">回答と添付を削除</button></div>
</form></details>
@endif
@endsection
