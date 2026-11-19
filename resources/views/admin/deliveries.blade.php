@extends('admin.layout')
@section('title','配信状況')
@section('main')
<header class="head"><div><div class="kicker">DELIVERY LOG</div><h1>配信状況</h1></div></header>
<div class="section-head"><h2>通知メール</h2><span class="count">{{ number_format($deliveries->total()) }} 件</span></div>
<div class="table-wrap"><table><thead><tr><th>配信ID</th><th>フォーム</th><th>配信状況</th><th>試行回数</th><th>最終更新</th></tr></thead><tbody>
@forelse($deliveries as $delivery)<tr><td>#{{ $delivery->id }}</td><td><strong>{{ $delivery->submission->form->name }}</strong>
@if($delivery->submission->retain)<a class="text-link" href="{{ route('admin.submission', $delivery->submission_id) }}">受付 #{{ $delivery->submission_id }}</a>@else<small>履歴保存なし</small>@endif
</td><td>@include('admin.status', compact('delivery'))</td><td>{{ $delivery->attempts }}</td><td>{{ $delivery->updated_at?->format('Y.m.d H:i') }}</td></tr>
@empty<tr><td colspan="5" class="empty">配信記録はまだありません。</td></tr>@endforelse</tbody></table></div>
@include('admin.pagination',['page'=>$deliveries])
@endsection
