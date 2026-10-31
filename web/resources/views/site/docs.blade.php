@extends('site.layout')

@section('main')
    <div class="docs">
        <nav class="docs__toc" aria-label="{{ __('site.docs.contents') }}">
            <p>{{ __('site.docs.contents') }}</p>
            <ul>
                @foreach (__('site.docs.sections') as $id => $section)
                    <li><a href="#{{ $id }}">{{ $section['title'] }}</a></li>
                @endforeach
                <li><a href="#faq">{{ __('site.docs.faq_title') }}</a></li>
            </ul>
        </nav>
        <article class="prose">
            <h1>{{ __('site.docs.title') }}</h1>
            <p class="prose__lead">{{ __('site.docs.intro') }}</p>
            @foreach (__('site.docs.sections') as $id => $section)
                <section id="{{ $id }}">
                    <h2>{{ $section['title'] }}</h2>
                    @foreach ($section['paragraphs'] as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach
                </section>
            @endforeach
            <section id="faq">
                <h2>{{ __('site.docs.faq_title') }}</h2>
                @include('site.partials.faq')
            </section>
        </article>
    </div>
@endsection
