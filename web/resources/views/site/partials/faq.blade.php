<div class="faq">
    @foreach (__('site.faq.items') as $item)
        <details>
            <summary>{{ $item['q'] }}</summary>
            <p>{{ $item['a'] }}</p>
        </details>
    @endforeach
</div>
