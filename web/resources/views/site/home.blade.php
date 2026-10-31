@extends('site.layout')

@push('head')
    <script src="/site/site.js?v={{ @filemtime(public_path('site/site.js')) ?: '1' }}" defer></script>
@endpush

@section('main')
    <div class="story" data-story>
        <div class="stage" data-stage data-three="/vendor/three-0.186.1/three.module.min.js" data-scene="/site/scene.js?v={{ @filemtime(public_path('site/scene.js')) ?: '1' }}" data-font="/vendor/fonts/alexandria-{{ $lang === 'ar' ? 'arabic' : 'latin' }}.woff2">
            <canvas aria-hidden="true"></canvas>
        </div>
        <section class="hero" data-beat>
            <div class="hero__copy">
                <h1><span>{{ __('site.hero.line_1') }}</span> <span class="hero__free">{{ __('site.hero.line_2') }}</span></h1>
                <p class="hero__body">{{ __('site.hero.body') }}</p>
                <div class="hero__actions">
                    @include('site.partials.install')
                    <a class="textlink" href="#rule">{{ __('site.hero.see_rule') }}</a>
                </div>
                <p class="hero__note">{{ __('site.hero.note') }}</p>
            </div>
            <div class="hero__mark" aria-hidden="true"><i></i><i></i></div>
        </section>
        @foreach (['settings', 'test', 'cart', 'analytics'] as $name)
            <section class="chapter" data-beat>
                <div class="chapter__phone">@include('site.partials.screen', ['name' => $name])</div>
                <div class="chapter__copy">
                    <h2>{{ __("site.chapters.$name.title") }}</h2>
                    <p>{{ __("site.chapters.$name.body") }}</p>
                </div>
            </section>
        @endforeach
    </div>

    <section class="band" id="rule">
        <h2 class="band__title">{{ __('site.rule.title') }}</h2>
        <p class="band__lead">{{ __('site.rule.body') }}</p>
        <ul class="legend">
            <li><i class="u u--paid"></i>{{ __('site.rule.legend_paid') }}</li>
            <li><i class="u u--free"></i>{{ __('site.rule.legend_free') }}</li>
            <li><i class="u u--part"></i>{{ __('site.rule.legend_percent') }}</li>
        </ul>
        <ol class="units">
            @foreach ([2 => 'pf', 3 => 'pfh', 4 => 'pfpf', 5 => 'pfpfh', 6 => 'pfpfpf'] as $count => $row)
                <li>
                    <span class="units__count">{{ __('site.rule.pieces', ['count' => $count]) }}</span>
                    <span class="units__row" aria-hidden="true">
                        @foreach (str_split($row) as $unit)
                            <i class="u u--{{ ['p' => 'paid', 'f' => 'free', 'h' => 'part'][$unit] }}"></i>
                        @endforeach
                    </span>
                    <span class="units__say">{{ __("site.rule.row_$count") }}</span>
                </li>
            @endforeach
        </ol>
        <div class="split">
            <div class="split__intro">
                <h3>{{ __('site.rule.split_title') }}</h3>
                <p>{{ __('site.rule.split_body') }}</p>
            </div>
            <div class="split__way">
                <h4>{{ __('site.rule.customer') }}</h4>
                <p>{{ __('site.rule.customer_detail') }}</p>
                <p class="split__pays">{{ __('site.rule.customer_pays') }}</p>
            </div>
            <div class="split__way">
                <h4>{{ __('site.rule.store') }}</h4>
                <p>{{ __('site.rule.store_detail') }}</p>
                <p class="split__pays">{{ __('site.rule.store_pays') }}</p>
            </div>
        </div>
    </section>

    <section class="band band--wash" id="compare">
        <h2 class="band__title">{{ __('site.compare.title') }}</h2>
        <p class="band__lead">{{ __('site.compare.body') }}</p>
        <table class="versus">
            <caption class="sr">{{ __('site.compare.caption') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('site.compare.topic') }}</th>
                    <th scope="col">{{ __('site.compare.by_hand') }}</th>
                    <th scope="col">{{ __('site.compare.pairly') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach (__('site.compare.rows') as $row)
                    <tr>
                        <th scope="row">{{ $row['topic'] }}</th>
                        <td data-label="{{ __('site.compare.by_hand') }}">{{ $row['by_hand'] }}</td>
                        <td data-label="{{ __('site.compare.pairly') }}">{{ $row['pairly'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="versus__verdict">{{ __('site.compare.verdict') }}</p>
    </section>

    <section class="band band--sun priceband" id="pricing">
        <div>
            <h2 class="band__title">{{ __('site.pricing.title') }}</h2>
            <p class="priceband__amount"><strong>{{ __('site.pricing.amount') }}</strong> <span>{{ __('site.pricing.period') }}</span></p>
            <p class="priceband__trial">{{ __('site.pricing.trial') }}</p>
            <p>{{ __('site.pricing.body') }}</p>
            @include('site.partials.install')
        </div>
        <ul class="ticks">
            @foreach (__('site.pricing.items') as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
    </section>

    <section class="band" id="faq">
        <h2 class="band__title">{{ __('site.faq.title') }}</h2>
        @include('site.partials.faq')
    </section>

    <section class="band band--cobalt closing">
        <h2 class="band__title">{{ __('site.cta.title') }}</h2>
        <p class="band__lead">{{ __('site.cta.body') }}</p>
        <div class="hero__actions">
            @include('site.partials.install')
            <a class="textlink" href="{{ $href('/docs#setup') }}">{{ __('site.cta.docs') }}</a>
        </div>
    </section>
@endsection
