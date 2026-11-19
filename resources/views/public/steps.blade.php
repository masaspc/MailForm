{{ $skin->open('steps', ['aria-label' => __('受付手順'), 'role' => $skin->containsTag('steps', 'ol') || $skin->containsTag('steps', 'ul') ? null : 'list', 'class' => $skin->accessibleSteps() ? 'mailform-visually-hidden' : '']) }}
@foreach(['input' => '入力', 'confirm' => '確認', 'thanks' => '完了'] as $key => $label)
@php($slot = $stage === $key ? 'step_current' : 'step')
{{ $skin->open($slot, ['aria-current' => $stage === $key ? 'step' : null, 'role' => $skin->containsTag($slot, 'li') ? null : 'listitem']) }}<span aria-hidden="true">{{ $loop->iteration }}</span>{{ __($label) }}{{ $skin->close($slot) }}
@endforeach
{{ $skin->close('steps') }}
