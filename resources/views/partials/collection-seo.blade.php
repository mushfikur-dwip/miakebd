{{-- A brand or category page's intro, related pages and questions, for the
     crawlers that do not run JavaScript. Built by App\Support\CollectionSeo -
     the same words the shop page shows and the FAQPage markup carries. --}}
@if (!empty($content))
    @if (!empty($content['intro']))
        <h2>{{ $content['heading'] }}</h2>
        @foreach ($content['intro'] as $paragraph)
            <p>{{ $paragraph }}</p>
        @endforeach
    @endif

    @if (!empty($content['related']))
        <h2>{{ $content['related_heading'] }}</h2>
        <ul>
            @foreach ($content['related'] as $link)
                <li><a href="{{ $link['url'] }}">{{ $link['name'] }}</a> ({{ $link['count'] }})</li>
            @endforeach
        </ul>
    @endif

    @if (!empty($content['faqs']))
        <h2>Frequently asked questions</h2>
        @foreach ($content['faqs'] as $faq)
            <h3>{{ $faq['question'] }}</h3>
            <p>{{ $faq['answer'] }}</p>
        @endforeach
    @endif
@endif
