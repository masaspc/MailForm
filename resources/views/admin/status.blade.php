@php($states = ['pending' => '送信待ち', 'sending' => '送信中', 'sent' => '送信済み', 'failed' => '送信失敗', 'unknown' => '結果不明', 'cancelled' => '取消済み', 'discarded'=>'破棄'])
<span @class(['pill', 'good' => $delivery->status === 'sent', 'bad' => in_array($delivery->status,['failed','unknown'])])>{{ $delivery->kind === 'admin' ? '管理者通知' : '自動返信' }} · {{ $states[$delivery->status] ?? $delivery->status }}</span>
