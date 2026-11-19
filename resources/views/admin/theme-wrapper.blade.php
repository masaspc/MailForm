<fieldset class="field stack"><legend>囲む要素 {{ $i + 1 }}</legend>
<label>外側からの順番<input type="number" min="1" max="{{ $wrapperLimit }}" name="wrappers[{{ $i }}][position]" value="{{ is_numeric($node['position'] ?? null) ? $node['position'] : $i + 1 }}"></label>
@include('admin.theme-node', ['prefix' => 'wrappers['.$i.']', 'node' => $node, 'tags' => \App\Services\ThemeStructure::WRAPPERS])
</fieldset>
