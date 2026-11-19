@php
    $key = "fields[".$index."]";
    $type = $field['form_type'] ?? 'text';
    $choices = $field['choices_text'] ?? implode("\n", \App\Services\Admin\FormEditor::choiceLabels($field));
@endphp
<article class="field" data-field>
    <input type="hidden" name="{{ $key }}[id]" value="{{ $field['id'] ?? '' }}">
    <div class="field-bar"><strong class="field-no">入力項目</strong>@unless(request()->routeIs('admin.fields','admin.fields.edit'))<div class="actions">
        <button class="tiny" type="button" data-move="up" aria-label="項目を上へ移動">↑</button>
        <button class="tiny" type="button" data-move="down" aria-label="項目を下へ移動">↓</button>
        <button class="plain danger" type="button" data-remove>削除</button>
    </div>@endunless</div>
    <div class="field-grid">
        <label>項目名<input name="{{ $key }}[title]" value="{{ $field['title'] ?? '' }}" required maxlength="255"></label>
        <label>入力形式<select name="{{ $key }}[form_type]" data-type>
            @if(!array_key_exists($type, \App\Services\Admin\FormEditor::TYPES))<option value="{{ $type }}">{{ $type }}（移行項目）</option>@endif
            @foreach(\App\Services\Admin\FormEditor::TYPES as $value => $label)<option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>@endforeach
        </select></label>
    </div>
    <div class="field-options"><label class="check"><input type="checkbox" name="{{ $key }}[required]" value="1" @checked($field['required'] ?? false)>必須項目</label>
    <details class="details"><summary>詳細設定</summary>
        <div class="field-grid">
            <label>項目キー<input name="{{ $key }}[name]" value="{{ $field['name'] ?? '' }}" placeholder="空欄で自動設定">
            <small>回答の識別とメールの #項目キー# に使用します。</small></label>
            <label>入力例<input name="{{ $key }}[placeholder]" value="{{ $field['placeholder'] ?? '' }}"></label>
            <label>最小文字数<input type="number" name="{{ $key }}[min_length]" min="0" value="{{ $field['min_length'] ?? '' }}"></label>
            <label>最大文字数<input type="number" name="{{ $key }}[max_length]" min="0" value="{{ $field['max_length'] ?? '' }}"></label>
            <label>再入力の照合先<input name="{{ $key }}[re_enter_html_id]" value="{{ $field['re_enter_html_id'] ?? '' }}" placeholder="照合する項目のHTML ID"></label>
            <label>入力制限<select name="{{ $key }}[restriction]">
            @foreach(\App\Services\Admin\FormEditor::RESTRICTIONS as $value => $label)<option value="{{ $value }}" @selected(($field['restriction'] ?? '') === $value)>{{ $label }}</option>@endforeach
            </select></label>
            <label>文字の自動変換<select name="{{ $key }}[hankaku_zenkaku_automatic_conversion]">
            @foreach(['default'=>'変換しない','zenkaku'=>'半角を全角へ','hankaku'=>'全角を半角へ'] as $value => $label)<option value="{{ $value }}" @selected(($field['hankaku_zenkaku_automatic_conversion'] ?? '') === $value)>{{ $label }}</option>@endforeach
            </select></label>
            @foreach(\App\Services\Admin\FormEditor::FIELD_TEXT as $attr => $label)
                <label>{{ $label }}@if(in_array($attr, ['description_above','description_below','initial_value']))
                <textarea name="{{ $key }}[{{ $attr }}]" rows="2">{{ $field[$attr] ?? '' }}</textarea>
                @else<input name="{{ $key }}[{{ $attr }}]" value="{{ $field[$attr] ?? '' }}">@endif</label>
            @endforeach
            <label>1行に表示する選択肢数<input type="number" name="{{ $key }}[columns]" value="{{ $field['columns'] ?? '' }}" min="1" max="6"></label>
            <label>添付容量上限（KB）<input type="number" name="{{ $key }}[file_capacity_limit]" value="{{ $field['file_capacity_limit'] ?? '' }}" min="1"></label>
            <label class="check"><input type="checkbox" name="{{ $key }}[real_time_validation]" value="1" @checked($field['real_time_validation'] ?? false)>入力中に検証する</label>
        </div>
    </details></div>
    <label class="choices" data-choices>選択肢<small>1行に1つ入力してください。</small>
        <textarea name="{{ $key }}[choices_text]" rows="4">{{ $choices }}</textarea>
    </label>
</article>
