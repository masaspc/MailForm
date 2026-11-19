@extends('admin.layout')
@section('title', $form ? 'フォームを編集' : 'フォームを作成')
@section('main')
@php($settings = $definition['settings'])
@php($themes = \App\Services\ThemeCatalog::choices())
<a class="back" href="{{ route('admin.forms') }}">← フォーム一覧</a>
<header class="head"><div><div class="kicker">FORM EDITOR</div><h1>{{ $form ? ($settings['name'] ?? $form->name) : 'フォームを作成' }}</h1></div>
@if($form)<span @class(['pill', 'good' => $form->published])>{{ $form->published ? '公開中 · 第'.$form->revision.'版' : '下書き' }}</span>@endif</header>
@if($definition['issues'] ?? [])<div class="alert"><strong>公開前に確認が必要な移行項目</strong><p>編集できる設定は、下書き保存時に再検査します。設置ファイル固有の問題は移行元で修正し、再度取り込んでください。</p><ul>@foreach($definition['issues'] as $issue)<li>{{ $issue }}</li>@endforeach</ul></div>@endif
@unless($form)
<nav class="presets" aria-label="ひな形"><strong>ひな形</strong>
@foreach(['contact'=>'お問い合わせ','event'=>'イベント申し込み','questionnaire'=>'アンケート'] as $value => $label)
<a class="btn compact" href="{{ route('admin.create', ['preset'=>$value]) }}" @if($preset === $value) aria-current="page" @endif>{{ $label }}</a>@endforeach
</nav>@endunless
<nav class="anchors" aria-label="編集セクション"><a href="#basic">基本設定</a><a href="#fields">入力項目</a><a href="#mail">通知メール</a><a href="#messages">画面の文言</a>@if($form)<a href="#publish">公開設定</a>@endif</nav>
<form class="editor" method="post" data-editor action="{{ $form ? route('admin.update',$form) : route('admin.store') }}">
@csrf @unless($form)<input type="hidden" name="preset" value="{{ $preset }}">@endunless @if($form) @method('put')<input type="hidden" name="version" value="{{ old('version', $version) }}">@endif
<section class="panel stack" id="basic">
    <h2>基本設定</h2>
    <label>フォーム名<input name="name" value="{{ old('name', $settings['name'] ?? '') }}" required maxlength="190" placeholder="例：お問い合わせ"></label>
    <div class="field-grid">
        <label>公開パス<input name="slug" value="{{ old('slug', $settings['url'] ?? $form?->slug ?? '') }}" placeholder="contact">
            <small>設置URLに続く名前です。空欄にすると設置先のトップを使用します。</small></label>
        <label>テーマ<select name="theme_name">
            @php($theme = old('theme_name', $settings['theme_name'] ?? 'gray'))
            @if(!isset($themes[$theme]))<option value="{{ $theme }}">{{ $theme }}（移行テーマ）</option>@endif
            @foreach( $themes as $key => $label)<option value="{{ $key }}" @selected($key === $theme)>{{ $label }}</option>@endforeach
        </select></label>
        <label>公開フォームの表示言語<select name="language">
            @foreach(['ja'=>'日本語','en'=>'English','cn'=>'中文'] as $key => $label)<option value="{{ $key }}" @selected(old('language', $settings['language'] ?? 'ja') === $key)>{{ $label }}</option>@endforeach
        </select></label>
    </div>
    <label class="check"><input type="checkbox" name="save_data" value="1" @checked(old('editor_complete') ? old('save_data') : ($settings['save_data'] ?? 1))>回答を受信履歴に保存する</label>
    <details class="details"><summary>受付と操作の設定</summary><div class="stack">
        @foreach(\App\Services\Admin\FormEditor::BEHAVIOR as $key => $label)
        <label class="check"><input type="checkbox" name="{{ $key }}" value="1" @checked(old('editor_complete') ? old($key) : ($settings[$key] ?? ($key !== 'recaptcha_enabled')))>{{ $label }}</label>@endforeach
        <label>受付を拒否するIPアドレス<textarea name="denied_ip" rows="3">{{ old('denied_ip', $settings['denied_ip'] ?? '') }}</textarea><small>1行に1件。IPv4末尾のワイルドカード（例 192.0.2.*）を指定できます。</small></label>
        <label>拒否時のメッセージ<input name="denied_ip_host_error_msg" value="{{ old('denied_ip_host_error_msg', $settings['denied_ip_host_error_msg'] ?? '') }}"></label>
    </div></details>
</section>
<section class="panel stack" id="fields">
    <h2>入力項目</h2>
    <div id="field-list" class="stack">@foreach(old('fields', $definition['fields']) as $index => $field)@include('admin.field', compact('field','index'))@endforeach</div>
    <div class="preset"><label>追加する項目<select data-library><option value="">新しい入力項目</option>
        @foreach($library as $item)<option value="{{ $loop->iteration }}" data-definition="{{ json_encode($item, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) }}">{{ $item['title'] }}（{{ \App\Services\Admin\FormEditor::TYPES[$item['form_type']] ?? $item['form_type'] }}）</option>@endforeach
    </select></label><button class="add" type="button" data-add>＋ 項目を追加</button></div>
    <small>利用中の項目は ↑ ↓ で表示順を変更できます。追加した項目は、このフォーム専用に編集できます。</small>
    <template id="field-template">@include('admin.field', ['field' => [], 'index' => '__NEW__'])</template>
</section>
<section class="panel stack" id="mail">
    <h2>通知メール</h2>
    <label>管理者通知の宛先<input name="admin_email" value="{{ old('admin_email', $settings['admin_email'] ?? '') }}"><small>複数指定する場合は半角カンマで区切ってください。</small></label>
    <div class="field-grid">@foreach(['cc_email'=>'CC','bcc_email'=>'BCC'] as $key => $label)
        <label>{{ $label }}<input name="{{ $key }}" value="{{ old($key, $settings[$key] ?? '') }}"></label>
    @endforeach</div>
    <label>メール件名<input name="mail_title" value="{{ old('mail_title', $settings['mail_title'] ?? '') }}"></label>
    @foreach(\App\Services\Admin\FormEditor::FLAGS as $key => $label)
        @continue($key === 'save_data')
        <label class="check"><input type="checkbox" name="{{ $key }}" value="1" @checked(old('editor_complete') ? old($key) : ($settings[$key] ?? false))>{{ $label }}</label>
    @endforeach
    <small>自動返信には、項目キーが「email」のメールアドレスを使用します。</small>
    @foreach(['reply_mail_header_message'=>'メールの冒頭','reply_mail_footer_message'=>'メールの末尾'] as $key => $label)
        <label>{{ $label }}<textarea name="{{ $key }}" rows="3">{{ old($key, $settings[$key] ?? '') }}</textarea></label>
    @endforeach
</section>
<section class="panel stack" id="messages">
    <h2>画面の文言</h2>
    @foreach(\App\Services\Admin\FormEditor::MESSAGES as $key => $label)
        @continue(str_starts_with($key, 'reply_mail_'))
        <label>{{ $label }}<textarea name="{{ $key }}" rows="2">{{ old($key, $settings[$key] ?? '') }}</textarea></label>
    @endforeach
</section>
<input type="hidden" name="editor_complete" value="1">
<div class="save-bar"><small data-dirty role="status"></small><button class="primary">下書きを保存</button></div>
</form>
@if($form)
<section class="panel stack" id="publish"><h2>公開設定</h2>
    @if($pendingTheme)
    <p>移行テーマの照合待ちです。@if(auth()->user()->role === 'admin')<a class="text-link" href="{{ route('admin.themes.edit', $settings['theme_name'] ?? 'one_column') }}">テーマを確認</a>@endif</p>
    @else
    <p>保存済みの版を公開します。@if($form->published)現在の公開パスは <a class="text-link" href="{{ $form->publicPath() }}">{{ $form->publicPath() }}</a> です。@endif</p>
    @endif
    <div class="actions">
        @if(!$pendingTheme || auth()->user()->role === 'admin')<a class="btn" href="{{ route('admin.preview', $form) }}">保存済みのプレビュー</a>@endif
        @if(!$pendingTheme && (!$form->published || $form->draft_definition))<form method="post" action="{{ route('admin.publish', $form) }}" data-saved-action>@csrf
        <input type="hidden" name="version" value="{{ $version }}"><button class="primary">保存済みの内容を公開</button></form>@endif
        @if($form->published)<form method="post" action="{{ route('admin.unpublish', $form) }}" data-confirm="公開を停止すると、このURLから回答できなくなります。公開を停止しますか？">@csrf
        <input type="hidden" name="version" value="{{ $version }}"><button class="btn danger">公開を停止</button></form>
        @elseif($form->awaiting_theme_publication)<form method="post" action="{{ route('admin.unpublish', $form) }}">@csrf
        <input type="hidden" name="version" value="{{ $version }}"><button class="btn">テーマ確認後も非公開にする</button></form>@endif
        <form method="post" action="{{ route('admin.copy', $form) }}" data-saved-action>@csrf<button class="btn">フォームを複製</button></form>
    </div>
    @if(auth()->user()->role === 'admin' && !$form->published)
    <details class="details"><summary>フォームを保管する</summary>
        <form class="stack" method="post" action="{{ route('admin.archive', $form) }}">@csrf
            <input type="hidden" name="version" value="{{ $version }}">
            <p>使用中の一覧から取り除きます。受付済みの回答・配信記録を確認でき、後から下書きとして復元できます。</p>
            <label class="check"><input type="checkbox" name="confirmed" value="1" required>「{{ $form->name }}」を保管することを確認しました</label>
            <div><button class="btn">保管する</button></div>
        </form>
    </details>
    @endif
</section>
@endif
@endsection
