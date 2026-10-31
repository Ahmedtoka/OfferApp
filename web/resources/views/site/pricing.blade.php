@extends('site.layout')

@section('main')
    <section class="band band--sun pricehead">
        <h1>{{ __('site.pricing.page_title') }}</h1>
        <p class="band__lead">{{ __('site.pricing.page_body') }}</p>
        @include('site.partials.install')
    </section>
    <section class="band twocol">
        <div>
            <h2>{{ __('site.pricing.title') }}</h2>
            <ul class="ticks">
                @foreach (__('site.pricing.items') as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        </div>
        <div>
            <h2>{{ __('site.pricing.details_title') }}</h2>
            <ul class="ticks">
                @foreach (__('site.pricing.details') as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
            <p><a class="textlink" href="{{ $lang === 'ar' ? $href('') : $href('/') }}#compare">{{ __('site.pricing.compare_link') }}</a></p>
        </div>
    </section>
    <section class="band band--wash" id="faq">
        <h2>{{ __('site.faq.title') }}</h2>
        @include('site.partials.faq')
    </section>
@endsection
