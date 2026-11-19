<section class="orchard-panel">
    <p class="orchard-intro">{{ $form->input_form_header_message }}</p>
    <form method="post" class="orchard-fields js_previous">
        @foreach($form_items as $form_item)
        <dl class="orchard-pair">
            <dt class="orchard-key">@if($form_item->required)<abbr class="orchard-star">必須</abbr>@endif {{ $form_item->title }}</dt>
            <dd class="orchard-value">
                <small class="orchard-hint">{!! $form_item->description_above !!}</small>
                @switch($form_item->form_type)
                @case('text')
                <input type="text" name="{{ $form_item->name }}" class="orchard-entry {{ $form_item->html_class }}" value="{{ old($form_item->name) }}">
                @break
                @case('email')
                <input type="email" name="{{ $form_item->name }}" class="orchard-mail {{ $form_item->html_class }}">
                @break
                @case('select')
                <div class="orchard-picker"><select name="{{ $form_item->name }}" class="orchard-select">
                    @foreach($choices as $choice)<option>{{ $choice->label_text }}</option>@endforeach
                </select></div>
                @break
                @case('zp_address')
                <input type="text" name="zip" class="orchard-postal">
                <div class="orchard-region"><select name="pref" class="orchard-prefecture"></select></div>
                <input type="text" name="address" class="orchard-street">
                <input type="text" name="building" class="orchard-building">
                @break
                @endswitch
                <span class="orchard-answer js_conf_control">{{ $answers[$form_item->name] }}</span>
                <small class="orchard-note">{{ $form_item->description_below }}</small>
            </dd>
        </dl>
        @endforeach
    </form>
</section>
<div class="orchard-actions">
    <div class="orchard-next"><button class="orchard-next-key">{{ __('validation.attributes.conf_btn') }}</button></div>
    <div class="orchard-back"><button class="orchard-back-key js_orchard_previous">{{ __('validation.attributes.back_btn') }}</button></div>
    <div class="orchard-send"><input type="submit" class="orchard-send-key" value="{{ __('validation.attributes.send_btn') }}"></div>
</div>
