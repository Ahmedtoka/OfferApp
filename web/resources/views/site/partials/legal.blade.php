@php
    $business = $legalName !== ''
        ? '<strong>'.e($legalName).'</strong>'
        : '<span class="placeholder">'.e(__('site.legal.placeholder_name')).'</span>';
    $email = $supportEmail !== ''
        ? '<a href="mailto:'.e($supportEmail).'">'.e($supportEmail).'</a>'
        : '<span class="placeholder">'.e(__('site.legal.placeholder_email')).'</span>';
    // Copy is escaped first; only the two identity tokens become markup.
    $fill = fn (string $line) => str_replace(['{business}', '{email}'], [$business, $email], e($line));
@endphp
<article class="prose">
    <h1>{{ __("site.$doc.title") }}</h1>
    <p class="draft">{{ __('site.legal.draft') }}</p>
    <dl class="identity">
        <div><dt>{{ __('site.legal.identity') }}</dt><dd>{!! $business !!}</dd></div>
        <div><dt>{{ __('site.legal.contact') }}</dt><dd>{!! $email !!}</dd></div>
    </dl>
    <p class="prose__lead">{!! $fill(__("site.$doc.intro")) !!}</p>
    @foreach (__("site.$doc.sections") as $section)
        <h2>{{ $section['title'] }}</h2>
        <ul>
            @foreach ($section['items'] as $line)
                <li>{!! $fill($line) !!}</li>
            @endforeach
        </ul>
    @endforeach
    <p class="prose__updated">{{ __('site.legal.updated') }}</p>
</article>
