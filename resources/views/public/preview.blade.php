<nav class="mailform-preview" aria-label="管理者プレビュー">
<span role="status">管理者プレビューです。回答は送信できません。</span>
@foreach(['input' => '入力', 'confirm' => '確認', 'thanks' => '完了'] as $previewStage => $previewLabel)
<a href="{{ route('admin.preview', array_filter(['form' => $form, 'stage' => $previewStage, 'version' => request()->query('version') === 'current' ? 'current' : null])) }}" @if($stage === $previewStage) aria-current="page" @endif>{{ $previewLabel }}</a>
@endforeach
<a href="{{ route('admin.edit', $form) }}">フォーム編集に戻る</a>
</nav>
