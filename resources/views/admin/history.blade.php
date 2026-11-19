@extends('admin.layout')
@section('title','受信履歴')
@section('main')
<header class="head"><div><div class="kicker">INBOX</div><h1>受信履歴</h1></div></header>
<div class="toolbar">
<form method="get" action="{{ route('admin.history') }}" class="filter">
    <label>フォーム<select name="form_id"><option value="">すべてのフォーム</option>@foreach($forms as $form)<option value="{{ $form->id }}" @selected((string)$formId === (string)$form->id)>{{ $form->name }}</option>@endforeach</select></label>
    <button class="btn">表示</button>
</form>
@if($formId)<a class="btn" href="{{ route('admin.csv', $formId) }}">↓ CSVダウンロード</a>@else<small>フォームを選ぶとCSVをダウンロードできます。</small>@endif
</div>
<div class="section-head"><h2>受付一覧</h2><span class="count">{{ number_format($history->total()) }} 件</span></div>
<div class="table-wrap"><table><thead><tr><th>受付日時</th><th>フォーム</th><th>添付</th><th>配信状況</th><th></th></tr></thead><tbody>
@forelse($history as $entry)
<tr><td><time>{{ $entry->created_at?->format('Y.m.d H:i') }}</time><small>#{{ $entry->id }}</small></td>
<td>{{ data_get($entry->snapshot,'legacy_history.form_name') ?: (data_get($entry->snapshot,'definition.settings.name') ?: $entry->form->name) }}</td>
<td>{{ $entry->attachments_count }} 件</td><td>
@forelse($entry->deliveries as $delivery)@include('admin.status', ['delivery'=>$delivery]) @empty <small>{{ $entry->legacy_id !== null ? '移行履歴' : '配信記録なし' }}</small>@endforelse
</td><td><a class="btn compact" href="{{ route('admin.submission',$entry) }}">詳細</a></td></tr>
@empty<tr><td class="empty" colspan="5">受信履歴はまだありません。</td></tr>@endforelse
</tbody></table></div>
@include('admin.pagination',['page'=>$history])
@endsection
