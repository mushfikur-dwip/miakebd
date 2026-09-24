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

        // Central constants — change these in ONE place (SiteSchema), so the
        // JSON-LD graph and the noscript block can never disagree.
        $suglowPhone     = \App\Support\SiteSchema::PHONE;
        $suglowPhoneText = \App\Support\SiteSchema::PHONE_TEXT;
        // Config-derived, not url(): article:publisher and the noscript links
        // below must not vary with the host a request happened to arrive on.
        $siteUrl         = rtrim((string) config('app.url'), '/');

        $companyName = Settings::group('company')->get('company_name') ?: 'Suglow';

        // Social preview card. This must NOT be images/required/theme-favicon-logo.png:
        // that file is the stock ShopKing template mark at 120x120, so sharing a
        // link showed someone else's orange crown logo, and at 120px it is under
        // the ~200x200 floor WhatsApp, Messenger and RCS need before they render
        // a preview image at all. og-suglow.jpg is the real Suglow badge on a
        // 1200x630 canvas — the ratio every one of those clients crops to —
        // kept as a ~58KB JPEG because WhatsApp silently skips heavy images.
        $brandImage  = asset('images/required/og-suglow.jpg');
        $brandImageW = 1200;
        $brandImageH = 630;

        // The admin-uploaded logo, for the JSON-LD entity. Google wants the true
        // brand logo here; $favicon is set from theme_favicon_logo by
        // RootController::shell() and may be null on a fresh install.
        $brandLogo = ($favicon ?? null) ?: $brandImage;

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
            $seoImage       = $controllerSeo['image'] ?: $brandImage;
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
            $seoImage       = $brandImage;
            $seoType        = 'website';
            $seoRobots      = 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
            $seoCanonical   = url()->current();
            $seoKeywords    = 'cosmetics bangladesh, skincare bangladesh, buy cosmetics online bd, authentic cosmetics bangladesh, imported cosmetics bd, online cosmetics shop bangladesh, cosmetics home delivery bangladesh, cosmetics dhaka, cosmetics rangpur, original cosmetics bd, malaysia cosmetics bangladesh, thailand cosmetics bd, korean skincare bangladesh, suglow, skin care product bd, cash on delivery cosmetics';
        } else {
            // ---- EVERY OTHER PAGE ----
            $seoTitle       = $companyName . ' — Authentic Cosmetics & Skincare in Bangladesh';
            $seoSocialTitle = $seoTitle;
            $seoDescription = "Shop authentic cosmetics & skincare at Suglow. Imported from Malaysia, Thailand & Indonesia. Cash on delivery across Bangladesh. Call {$suglowPhoneText}.";
            $seoImage       = $brandImage;
            $seoType        = 'website';
            $seoRobots      = 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
            $seoCanonical   = url()->current();
            $seoKeywords    = null;
        }

        // og:image:width/height let a crawler lay out the card before it has
        // finished downloading the image — WhatsApp in particular will drop the
        // preview rather than wait. Only emitted for the brand card, whose size
        // is known here; a product photo is whatever the admin uploaded, and
        // declaring the wrong size is worse than declaring none.
        $seoImageIsBrand = $seoImage === $brandImage;

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

    @php
        // Only the controller path measures its image. Every other branch falls
        // back to the brand card, whose size is the constant above.
        $seoImageW = ($controllerSeo && $seoImage !== $brandImage) ? ($controllerSeo['image_width'] ?? null) : null;
        $seoImageH = ($controllerSeo && $seoImage !== $brandImage) ? ($controllerSeo['image_height'] ?? null) : null;
    @endphp

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
    {{-- WhatsApp will not download an image to find out how big it is before
         laying out the card, so a preview with no declared size renders as
         title and description with no picture. The brand card's size is a
         constant here; a product photo's is measured off the generated file by
         MediaUrl::dimensions(), so what is declared is always the real size. --}}
    @if ($seoImageIsBrand)
        <meta property="og:image:type" content="image/jpeg">
        <meta property="og:image:width" content="{{ $brandImageW }}">
        <meta property="og:image:height" content="{{ $brandImageH }}">
    @elseif (!empty($seoImageW) && !empty($seoImageH))
        <meta property="og:image:width" content="{{ $seoImageW }}">
        <meta property="og:image:height" content="{{ $seoImageH }}">
    @endif
    <meta property="og:image:alt" content="{{ $product['name'] ?? ($controllerSeo['title'] ?? 'Suglow — authentic cosmetics and skincare in Bangladesh') }}">
    <meta property="og:url" content="{{ $seoCanonical }}">
    <meta property="og:site_name" content="Suglow">
    <meta property="og:locale" content="en_US">
    <meta property="og:locale:alternate" content="bn_BD">

    {{-- article:* is what makes a blog post render as a dated article in a
         Facebook/LinkedIn preview rather than an undated page. Only emitted
         when the controller resolved a real post. --}}
    @if (!empty($controllerSeo['article']))
        @if (!empty($controllerSeo['article']['published_time']))
            <meta property="article:published_time" content="{{ $controllerSeo['article']['published_time'] }}">
        @endif
        @if (!empty($controllerSeo['article']['modified_time']))
            <meta property="article:modified_time" content="{{ $controllerSeo['article']['modified_time'] }}">
        @endif
        @if (!empty($controllerSeo['article']['section']))
            <meta property="article:section" content="{{ $controllerSeo['article']['section'] }}">
        @endif
        <meta property="article:author" content="{{ $controllerSeo['article']['author'] ?? 'Suglow' }}">
        <meta property="article:publisher" content="{{ $siteUrl }}/">
    @endif

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

    {{-- ============ SITE-WIDE STRUCTURED DATA ============
         Emitted on EVERY page, not just the homepage: the WebSite entity
         carries the SearchAction (sitelinks search box) and the Organization
         entity establishes the brand — both keyed by stable @ids, so Google
         merges them with the richer homepage declaration rather than seeing
         duplicates. On the homepage SiteSchema::graph() additionally returns
         the two physical Store entities and the FAQPage graph.

         Built in App\Support\SiteSchema with json_encode() so no apostrophe
         can break the JSON, and the HEX flags make it impossible to escape
         the <script> block. --}}
    <script type="application/ld+json">{!! json_encode(\App\Support\SiteSchema::graph($isHomepage, $brandImage, $brandLogo), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    {{-- ==================== END SEO BLOCK ==================== --}}

    <!-- FAV ICON -->
    <link rel="icon" href="{{ $favicon }}">

    {{-- The header logo is the first thing a visitor sees, and until now the
         browser only learned its URL after the bundle had parsed and the
         settings had arrived. Starting it here overlaps the download with the
         JavaScript. --}}
    @if (!blank($bootSetting['theme_logo'] ?? null))
        <link rel="preload" as="image" href="{{ $bootSetting['theme_logo'] }}" fetchpriority="high">
    @endif

    {{-- The shop's settings, handed to the SPA in the page itself. Without this
         the app boots, then waits for GET /api/frontend/setting before it can
         draw the header, logo, currency or menus - an extra round trip on the
         first visit, when nothing is cached. The store falls back to fetching
         the endpoint if this is missing or empty. HEX flags for the same reason
         the structured data above uses them: this sits inside a <script>. --}}
    <script>window.__BOOT_SETTING__ = {!! json_encode($bootSetting ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};</script>

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
            @elseif (!empty($blogPost))
                {{-- The full article, for crawlers that do not run JS. Rendered
                     as markup rather than stripped text so headings, lists and
                     internal links survive — those are the structure Google
                     reads an article by.

                     {!! !!} is deliberate: this is admin-authored content from
                     the editor, the same trust level as the CMS page bodies and
                     analytics blocks already rendered raw in this file. --}}
                <h1>{{ $blogPost['name'] }}</h1>
                @if (!empty($blogPost['article']['published_time']))
                    <p><time datetime="{{ $blogPost['article']['published_time'] }}">{{ \Illuminate\Support\Carbon::parse($blogPost['article']['published_time'])->format('d M, Y') }}</time>
                       @if (!empty($blogPost['article']['author'])) — {{ $blogPost['article']['author'] }} @endif
                    </p>
                @endif
                @if (!empty($blogPost['image']))
                    <img src="{{ $blogPost['image'] }}" alt="{{ $blogPost['name'] }}" width="1200" height="630" style="max-width:100%;height:auto">
                @endif
                {!! $blogPost['body'] !!}
                <p><a href="{{ $siteUrl }}/blog">More articles on the Suglow blog</a> ·
                   <a href="{{ $siteUrl }}/product">Shop authentic cosmetics</a></p>
            @elseif (!empty($blogCategory))
                <h1>{{ $blogCategory['name'] }}</h1>
                <p>{{ $blogCategory['description'] }}</p>
                @if (!empty($blogCategory['posts']))
                    <ul>
                        @foreach ($blogCategory['posts'] as $blogCategoryPost)
                            <li><a href="{{ $blogCategoryPost['url'] }}">{{ $blogCategoryPost['name'] }}</a></li>
                        @endforeach
                    </ul>
                @endif
                <p><a href="{{ $siteUrl }}/blog">All Suglow blog articles</a></p>
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
