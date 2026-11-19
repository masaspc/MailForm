@extends("theme.{$data->theme_name}.layout")
@section('content')
<main class="orchard-page">
    <section class="orchard-paper">
        <header class="orchard-heading"><h1>{{ $form_title }}</h1></header>
        <div class="orchard-body">@include("theme.{$data->theme_name}.form")</div>
    </section>
</main>
@endsection
