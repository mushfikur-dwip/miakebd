<!DOCTYPE html>
<html dir="ltr" lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <!-- REQUIRED META TAGS -->
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- CUSTOM STYLE -->
    <link rel="stylesheet" href="{{ asset('themes/default/css/custom.css') }}">

    {{--
    ============================================================================
      SEO BLOCK — suglow.com
      ----------------------------------------------------------------------
      WHY THIS EXISTS: Vue's useHead() writes meta tags only AFTER JavaScript
      runs. Google's first crawl pass, and every social/AI crawler — WhatsApp,
      Facebook, GPTBot, ClaudeBot, PerplexityBot — read only the raw HTML the
      server returns. Without this block that raw HTML has nothing usable.

      THREE MODES:
        1. Product page    -> real product title, description, image and price.
        2. Homepage        -> full block + Organization/Store/WebSite/FAQ graph.
        3. Everything else -> safe site-wide fallback, which Vue then overrides
                              client-side with page-specific values.

      DATA SOURCE, IN PRIORITY ORDER:
        1. $seo, passed by RootController::product(). This reads the real
           product_seos record plus live price and stock, so it is preferred
           whenever present.
        2. ProductMetaResolver, if that class is installed. Kept as a fallback
           for routes that render this view without going through the
           controller.
      Both are optional; the page renders correctly with neither.
    ============================================================================
    --}}
    @php
        $isHomepage = request()->is('/');

        // Central constants — change these in ONE place.
        $suglowPhone     = '+8801709786330';
        $suglowPhoneText = '01709786330';
        $siteUrl         = rtrim(url('/'), '/');

        $companyName = Settings::group('company')->get('company_name') ?: 'Suglow';

        // Controller-supplied SEO wins. It is built from product_seos, the live
        // selling/variation price and real stock counts.
        $controllerSeo = $seo ?? null;

        // Fallback resolver, only consulted when the controller passed nothing.
        $product = null;
        if (!$controllerSeo && class_exists(\App\Support\ProductMetaResolver::class)) {
            $product = \App\Support\ProductMetaResolver::resolve();
        }

        if ($controllerSeo) {
            // ---- CONTROLLER-RESOLVED PAGE (product or category) ----
            // Category titles already end in "— Suglow"; appending the company
            // name unconditionally produced "... — Suglow | Suglow".
            $seoTitle       = str_contains($controllerSeo['title'], $companyName)
                ? $controllerSeo['title']
                : $controllerSeo['title'] . ' | ' . $companyName;
            $seoSocialTitle = $seoTitle;
            $seoDescription = $controllerSeo['description'];
            $seoImage       = $controllerSeo['image'] ?: asset('images/required/theme-favicon-logo.png');
            $seoType        = $controllerSeo['type'] ?? 'product';
            $seoRobots      = $controllerSeo['robots'] ?? 'index, follow, max-image-preview:large';
            $seoCanonical   = $controllerSeo['canonical'] ?? url()->current();
            $seoKeywords    = $controllerSeo['keywords'] ?? null;
        } elseif ($product) {
            // ---- PRODUCT PAGE (resolver fallback) ----
            // <title> gets the short form (Google truncates past ~65 chars);
            // og:title gets the longer form because WhatsApp wraps it.
            $seoTitle       = $product['title'];
            $seoSocialTitle = $product['social_title'] ?? $product['title'];
            $seoDescription = $product['description'];
            $seoImage       = $product['image'];
            $seoType        = 'product';
            $seoRobots      = 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
            $seoCanonical   = url()->current();
            $seoKeywords    = null;
        } elseif ($isHomepage) {
            // ---- HOMEPAGE ----
            $seoTitle       = 'Suglow — Buy Authentic Cosmetics & Skincare Online in Bangladesh';
            $seoSocialTitle = $seoTitle;
            $seoDescription = "Bangladesh's largest authentic cosmetics store. Imported from Malaysia, Thailand & Indonesia. Cash on delivery nationwide. Call {$suglowPhoneText}. Open 24/7.";
            $seoImage       = asset('images/required/theme-favicon-logo.png');
            $seoType        = 'website';
            $seoRobots      = 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
            $seoCanonical   = url()->current();
            $seoKeywords    = 'cosmetics bangladesh, skincare bangladesh, buy cosmetics online bd, authentic cosmetics bangladesh, imported cosmetics bd, online cosmetics shop bangladesh, cosmetics home delivery bangladesh, cosmetics dhaka, cosmetics rangpur, original cosmetics bd, malaysia cosmetics bangladesh, thailand cosmetics bd, korean skincare bangladesh, suglow, skin care product bd, cash on delivery cosmetics';
        } else {
            // ---- EVERY OTHER PAGE ----
            $seoTitle       = $companyName . ' — Authentic Cosmetics & Skincare in Bangladesh';
            $seoSocialTitle = $seoTitle;
            $seoDescription = "Shop authentic cosmetics & skincare at Suglow. Imported from Malaysia, Thailand & Indonesia. Cash on delivery across Bangladesh. Call {$suglowPhoneText}.";
            $seoImage       = asset('images/required/theme-favicon-logo.png');
            $seoType        = 'website';
            $seoRobots      = 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
            $seoCanonical   = url()->current();
            $seoKeywords    = null;
        }

        // Commerce facts for the product:* tags Facebook renders beneath a link
        // preview, from whichever source supplied them. The controller path is
        // the live one; the resolver path only covers routes that bypass it.
        $commerce = null;

        if ($controllerSeo && !empty($controllerSeo['commerce']['price'])) {
            $commerce = $controllerSeo['commerce'];
        } elseif ($product && !empty($product['price'])) {
            $commerce = [
                'price'        => $product['price'],
                'currency'     => $product['currency'] ?? 'BDT',
                'availability' => $product['availability'] ?? null,
                'brand'        => $product['brand'] ?? null,
            ];
        }
    @endphp

    <!-- PAGE TITLE -->
    <title>{{ $seoTitle }}</title>

    <!-- SEO META -->
    <meta name="description" content="{{ $seoDescription }}">
    @if ($seoKeywords)
        <meta name="keywords" content="{{ $seoKeywords }}">
    @endif
    <meta name="robots" content="{{ $seoRobots }}">
    <meta name="googlebot" content="index, follow, max-image-preview:large, max-snippet:-1">
    <meta name="author" content="Suglow">
    <link rel="canonical" href="{{ $seoCanonical }}">

    {{-- geo.region is BD (whole country), NOT BD-55 (Rangpur division).
         Suglow delivers nationwide; restricting the geo signal to Rangpur would
         tell Google this is a Rangpur-only business and suppress the site in
         Dhaka, Chittagong and Sylhet searches. The two physical outlets are
         still declared in the Store schema below, where local data belongs. --}}
    <meta name="geo.region" content="BD">
    <meta name="geo.placename" content="Bangladesh">
    <meta name="theme-color" content="#ffffff">

    {{-- ============ OPEN GRAPH — WhatsApp & Facebook link previews ============
         og:image MUST be an absolute https URL that returns the image directly.
         WhatsApp will not follow redirects and ignores relative paths.
         Recommended size 1200x630; WhatsApp needs at least 300x200 to show a
         large preview instead of a small thumbnail. --}}
    <meta property="og:type" content="{{ $seoType }}">
    <meta property="og:title" content="{{ $seoSocialTitle }}">
    <meta property="og:description" content="{{ $seoDescription }}">
    <meta property="og:image" content="{{ $seoImage }}">
    <meta property="og:image:secure_url" content="{{ $seoImage }}">
    <meta property="og:image:alt" content="{{ $product['name'] ?? ($controllerSeo['title'] ?? 'Suglow — authentic cosmetics and skincare in Bangladesh') }}">
    <meta property="og:url" content="{{ $seoCanonical }}">
    <meta property="og:site_name" content="Suglow">
    <meta property="og:locale" content="en_US">
    <meta property="og:locale:alternate" content="bn_BD">

    @if ($commerce)
        {{-- Facebook shows price directly under the product preview --}}
        <meta property="product:price:amount" content="{{ $commerce['price'] }}">
        <meta property="product:price:currency" content="{{ $commerce['currency'] ?? 'BDT' }}">
        <meta property="product:availability" content="{{ ($commerce['availability'] ?? null) === 'https://schema.org/InStock' ? 'in stock' : 'out of stock' }}">
        @if (!empty($commerce['brand']))
            <meta property="product:brand" content="{{ $commerce['brand'] }}">
        @endif
    @endif

    <!-- TWITTER CARD -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seoTitle }}">
    <meta name="twitter:description" content="{{ $seoDescription }}">
    <meta name="twitter:image" content="{{ $seoImage }}">

    {{-- Product structured data. $structuredData comes from SeoSchema::product(),
         which reads live price, real stock status and only emits an
         aggregateRating when genuine reviews exist. --}}
    @isset($structuredData)
        <script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    @endisset

    @if ($isHomepage)
        {{-- ============ HOMEPAGE STRUCTURED DATA ============
             Homepage only — emitting Organization/Store schema on every page
             would create duplicate entity declarations across the whole site.

             Built with json_encode() rather than a hand-typed string so no
             apostrophe can break the JSON, and so the HEX flags below make it
             impossible to escape the <script> block. --}}
        <script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@graph' => [

        // ---------- The business itself ----------
        [
            '@type' => ['Organization', 'OnlineStore'],
            '@id' => $siteUrl . '/#organization',
            'name' => 'Suglow',
            'alternateName' => ['Suglow BD', 'Suglow Bangladesh'],
            'url' => $siteUrl . '/',
            'logo' => [
                '@type' => 'ImageObject',
                'url' => asset('images/required/theme-favicon-logo.png'),
            ],
            'image' => asset('images/required/theme-favicon-logo.png'),
            'description' => "Bangladesh's largest authentic cosmetics and skincare retailer. Products imported directly from Malaysia, Thailand and Indonesia. Online delivery nationwide across Bangladesh, plus two physical outlets in Rangpur. Customer service available 24/7.",
            'slogan' => 'Authentic cosmetics, delivered anywhere in Bangladesh',
            'telephone' => $suglowPhone,
            'currenciesAccepted' => 'BDT',
            'paymentAccepted' => 'Cash on Delivery, Online Payment',
            'areaServed' => ['@type' => 'Country', 'name' => 'Bangladesh'],
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'telephone' => $suglowPhone,
                'contactType' => 'customer service',
                'areaServed' => 'BD',
                'availableLanguage' => ['Bengali', 'English'],
                'hoursAvailable' => [
                    '@type' => 'OpeningHoursSpecification',
                    'dayOfWeek' => ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'],
                    'opens' => '00:00',
                    'closes' => '23:59',
                ],
            ],
            'hasOfferCatalog' => [
                '@type' => 'OfferCatalog',
                'name' => 'Cosmetics and Skincare',
                'itemListElement' => [
                    ['@type' => 'OfferCatalog', 'name' => 'Skin Care'],
                    ['@type' => 'OfferCatalog', 'name' => 'Personal Care'],
                    ['@type' => 'OfferCatalog', 'name' => 'Fragrance'],
                    ['@type' => 'OfferCatalog', 'name' => 'Hair Care'],
                    ['@type' => 'OfferCatalog', 'name' => 'Sunscreen'],
                    ['@type' => 'OfferCatalog', 'name' => 'Baby Care'],
                    ['@type' => 'OfferCatalog', 'name' => 'Moisturizer'],
                    ['@type' => 'OfferCatalog', 'name' => "Men's Skin Care"],
                    ['@type' => 'OfferCatalog', 'name' => 'Accessories'],
                ],
            ],
        ],

        // ---------- Physical outlet 1 ----------
        [
            '@type' => ['Store', 'HealthAndBeautyBusiness'],
            '@id' => $siteUrl . '/#store-ramc',
            'name' => 'Suglow — RAMC Shopping Complex Outlet',
            'parentOrganization' => ['@id' => $siteUrl . '/#organization'],
            'url' => $siteUrl . '/',
            'image' => asset('images/required/theme-favicon-logo.png'),
            'telephone' => $suglowPhone,
            'currenciesAccepted' => 'BDT',
            'priceRange' => 'BDT 100 - BDT 5000',
            'paymentAccepted' => 'Cash, Cash on Delivery, Online Payment',
            'areaServed' => ['@type' => 'Country', 'name' => 'Bangladesh'],
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => 'Shop No. 52, Level 1, RAMC Shopping Complex',
                'addressLocality' => 'Rangpur',
                'addressRegion' => 'Rangpur Division',
                'addressCountry' => 'BD',
            ],
            'openingHoursSpecification' => [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'],
                'opens' => '00:00',
                'closes' => '23:59',
            ],
        ],

        // ---------- Physical outlet 2 ----------
        [
            '@type' => ['Store', 'HealthAndBeautyBusiness'],
            '@id' => $siteUrl . '/#store-prime',
            'name' => 'Suglow — Prime Medical College Gate Outlet',
            'parentOrganization' => ['@id' => $siteUrl . '/#organization'],
            'url' => $siteUrl . '/',
            'image' => asset('images/required/theme-favicon-logo.png'),
            'telephone' => $suglowPhone,
            'currenciesAccepted' => 'BDT',
            'priceRange' => 'BDT 100 - BDT 5000',
            'paymentAccepted' => 'Cash, Cash on Delivery, Online Payment',
            'areaServed' => ['@type' => 'Country', 'name' => 'Bangladesh'],
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => 'Prime Medical College Gate, Badarganj Road',
                'addressLocality' => 'Rangpur',
                'addressRegion' => 'Rangpur Division',
                'addressCountry' => 'BD',
            ],
            'openingHoursSpecification' => [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'],
                'opens' => '00:00',
                'closes' => '23:59',
            ],
        ],

        // ---------- The website ----------
        [
            '@type' => 'WebSite',
            '@id' => $siteUrl . '/#website',
            'url' => $siteUrl . '/',
            'name' => 'Suglow',
            'inLanguage' => 'en',
            'publisher' => ['@id' => $siteUrl . '/#organization'],
        ],

        // ---------- FAQ ----------
        // Every answer below is also rendered as visible text in the <noscript>
        // block and on the site itself. Google requires FAQ markup to match
        // content the visitor can actually see.
        [
            '@type' => 'FAQPage',
            '@id' => $siteUrl . '/#faq',
            'mainEntity' => [
                [
                    '@type' => 'Question',
                    'name' => 'Where does Suglow import its cosmetics from?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'Suglow imports cosmetics and skincare products directly from Malaysia, Thailand and Indonesia, operating its own warehouse in Kuala Lumpur. Direct importing is how Suglow keeps products authentic and prices competitive in Bangladesh.',
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => 'Does Suglow deliver across all of Bangladesh?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'Yes. Suglow delivers nationwide across Bangladesh, including Dhaka, Chittagong, Rangpur, Sylhet, Khulna, Rajshahi and Barisal. Cash on delivery is available on orders anywhere in the country.',
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => 'Does Suglow have physical stores?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'Yes, Suglow has two outlets in Rangpur City: Shop No. 52, Level 1, RAMC Shopping Complex, and one at Prime Medical College Gate on Badarganj Road. Customers anywhere else in Bangladesh can order online at suglow.com.',
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => 'Is cash on delivery available at Suglow?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'Yes. Suglow accepts cash on delivery on orders across Bangladesh, so customers pay only when the product reaches them. Online payment is also accepted.',
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => 'How do I contact Suglow?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'Suglow customer service is available 24/7 on ' . $suglowPhoneText . '. Support is offered in both Bengali and English.',
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => 'What products does Suglow sell?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'Suglow stocks over 440 products across skin care, personal care, fragrance, hair care, sunscreen, baby care, moisturizers, mens skin care and beauty accessories, from brands including Nivea, Dove, Garnier, Vaseline, CeraVe, Fogg, Lotus, Enchanteur, Bioaqua and Sadoer.',
                    ],
                ],
            ],
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
        </script>
    @endif
    {{-- ==================== END SEO BLOCK ==================== --}}

    <!-- FAV ICON -->
    <link rel="icon" href="{{ $favicon }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @if (!blank($analytics))
        @foreach ($analytics as $analytic)
            @if (!blank($analytic->analyticSections))
                @foreach ($analytic->analyticSections as $section)
                    @if ($section->section == \App\Enums\AnalyticSection::HEAD)
                        {!! $section->data !!}
                    @endif
                @endforeach
            @endif
        @endforeach
    @endif
</head>

<body>
    @if (!blank($analytics))
        @foreach ($analytics as $analytic)
            @if (!blank($analytic->analyticSections))
                @foreach ($analytic->analyticSections as $section)
                    @if ($section->section == \App\Enums\AnalyticSection::BODY)
                        {!! $section->data !!}
                    @endif
                @endforeach
            @endif
        @endforeach
    @endif

    {{-- Crawler-visible fallback content.
         Shown only to visitors with JavaScript disabled — and to every AI
         crawler that does not execute JS. Vue replaces #app the instant it
         mounts, so real users never see this. This is legitimate <noscript>
         content describing the actual business, not hidden keyword text. --}}
    <noscript>
        <div style="max-width:760px;margin:0 auto;padding:24px;font-family:system-ui,sans-serif;line-height:1.6">
            @if ($product)
                <h1>{{ $product['name'] }}</h1>
                @if ($product['image'])
                    <img src="{{ $product['image'] }}" alt="{{ $product['name'] }}" width="600" height="600" style="max-width:100%;height:auto">
                @endif
                <p>{{ $product['description'] }}</p>
                @if ($product['price'])
                    <p><strong>Price: BDT {{ $product['price'] }}</strong></p>
                @endif
                <p>Available at Suglow with cash on delivery across Bangladesh.
                   Call <a href="tel:{{ $suglowPhone }}">{{ $suglowPhoneText }}</a> — open 24/7.</p>
            @elseif ($controllerSeo)
                <h1>{{ $controllerSeo['title'] }}</h1>
                @if (!empty($controllerSeo['image']))
                    <img src="{{ $controllerSeo['image'] }}" alt="{{ $controllerSeo['title'] }}" width="600" height="600" style="max-width:100%;height:auto">
                @endif
                <p>{{ $controllerSeo['description'] }}</p>
                <p>Available at Suglow with cash on delivery across Bangladesh.
                   Call <a href="tel:{{ $suglowPhone }}">{{ $suglowPhoneText }}</a> — open 24/7.</p>
            @else
                <h1>Suglow — Authentic Cosmetics &amp; Skincare in Bangladesh</h1>
                <p>
                    Suglow is one of Bangladesh's largest authentic cosmetics and skincare retailers.
                    We import our products directly from Malaysia, Thailand and Indonesia, and operate
                    our own warehouse in Kuala Lumpur. Orders are delivered nationwide across Bangladesh
                    with cash on delivery available.
                </p>

                <h2>Product categories</h2>
                <ul>
                    <li><a href="{{ $siteUrl }}/product-category/skin-care6">Skin Care</a></li>
                    <li><a href="{{ $siteUrl }}/product-category/personal-care">Personal Care</a></li>
                    <li><a href="{{ $siteUrl }}/product-category/fragrance">Fragrance</a></li>
                    <li><a href="{{ $siteUrl }}/product-category/hair-care">Hair Care</a></li>
                    <li><a href="{{ $siteUrl }}/product-category/sunscreen">Sunscreen</a></li>
                    <li><a href="{{ $siteUrl }}/product-category/baby-care">Baby Care</a></li>
                    <li><a href="{{ $siteUrl }}/product-category/moisturizer">Moisturizer</a></li>
                    <li><a href="{{ $siteUrl }}/product-category/men-skin-care">Men's Skin Care</a></li>
                    <li><a href="{{ $siteUrl }}/product-category/accessories">Accessories</a></li>
                </ul>

                <h2>Frequently asked questions</h2>
                <h3>Where does Suglow import its cosmetics from?</h3>
                <p>Suglow imports cosmetics and skincare products directly from Malaysia, Thailand and
                   Indonesia, operating its own warehouse in Kuala Lumpur.</p>

                <h3>Does Suglow deliver across all of Bangladesh?</h3>
                <p>Yes. Suglow delivers nationwide across Bangladesh, including Dhaka, Chittagong,
                   Rangpur, Sylhet, Khulna, Rajshahi and Barisal.</p>

                <h3>Does Suglow have physical stores?</h3>
                <p>Yes, two outlets in Rangpur City: Shop No. 52, Level 1, RAMC Shopping Complex, and
                   one at Prime Medical College Gate on Badarganj Road.</p>

                <h3>Is cash on delivery available at Suglow?</h3>
                <p>Yes. Suglow accepts cash on delivery on orders across Bangladesh. Online payment is
                   also accepted.</p>

                <h3>How do I contact Suglow?</h3>
                <p>Customer service is available 24/7 on
                   <a href="tel:{{ $suglowPhone }}">{{ $suglowPhoneText }}</a>, in Bengali and English.</p>

                <h2>Our outlets in Rangpur</h2>
                <p>Shop No. 52, Level 1, RAMC Shopping Complex, Rangpur City</p>
                <p>Prime Medical College Gate, Badarganj Road, Rangpur City</p>
            @endif

            <p><em>This page works best with JavaScript enabled. Please enable JavaScript to browse and order products.</em></p>
        </div>
    </noscript>

    <div id="app"></div>

    @if (!blank($analytics))
        @foreach ($analytics as $analytic)
            @if (!blank($analytic->analyticSections))
                @foreach ($analytic->analyticSections as $section)
                    @if ($section->section == \App\Enums\AnalyticSection::FOOTER)
                        {!! $section->data !!}
                    @endif
                @endforeach
            @endif
        @endforeach
    @endif

    <script>
        const APP_URL = "{{ env('VITE_HOST') }}";
        const APP_DEMO = "{{ env('VITE_DEMO') }}";
        const APP_KEY = "{{ env('VITE_API_KEY') }}";
    </script>

    <script src="{{ asset('themes/default/js/modal.js') }}"></script>
    <script src="{{ asset('themes/default/js/customScript.js') }}"></script>
    <script src="{{ asset('themes/default/js/tabs.js') }}"></script>

</body>

</html>
