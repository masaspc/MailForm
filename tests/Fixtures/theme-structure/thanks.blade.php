@extends("theme.{$data->theme_name}.layout")
@section('content')
<section class="orchard-receipt"><p class="orchard-finished">{{ $form->thanks_message }}</p></section>
@endsection
