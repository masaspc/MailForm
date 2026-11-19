@php
    $tag = is_array($node) && is_string($node['tag'] ?? null) ? $node['tag'] : '';
    $class = is_array($node) && is_string($node['class'] ?? null) ? $node['class'] : '';
    $id = is_array($node) && is_string($node['id'] ?? null) ? $node['id'] : '';
@endphp
<label>タグ<select name="{{ $prefix }}[tag]" @required($prefix === 'element')>
    <option value="">{{ $prefix === 'element' ? 'タグを選択' : '使わない' }}</option>
    @foreach($tags as $option)<option value="{{ $option }}" @selected($tag === $option)>{{ $option }}</option>@endforeach
</select></label>
<div class="field-grid">
<label>CSSクラス（空白区切り）<input name="{{ $prefix }}[class]" maxlength="1024" value="{{ $class }}" spellcheck="false"></label>
<label>ID<input name="{{ $prefix }}[id]" maxlength="64" value="{{ $id }}" spellcheck="false"></label>
</div>
