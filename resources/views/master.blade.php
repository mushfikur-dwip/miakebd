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
        // This page's own address on the canonical host. getPathInfo() leaves
        // out the /public prefix a request can arrive with, so those
        // duplicates point back at the real URL instead of at themselves.
        $currentUrl      = $siteUrl . request()->getPathInfo();

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
            // name unconditionally produced "... — Suglow | Suglow". The check
            // ignores case: the company name is stored as "SUGLOW", so a
            // case-sensitive match missed "Suglow" and appended it anyway.
            $seoTitle       = mb_stripos($controllerSeo['title'], (string) $companyName) !== false
                ? $controllerSeo['title']
                : $controllerSeo['title'] . ' | ' . $companyName;
            $seoSocialTitle = $seoTitle;
            $seoDescription = $controllerSeo['description'];
            $seoImage       = $controllerSeo['image'] ?: $brandImage;
            $seoType        = $controllerSeo['type'] ?? 'product';
            $seoRobots      = $controllerSeo['robots'] ?? 'index, follow, max-image-preview:large';
            // An explicit null means "no canonical" (404s, private screens),
            // which ?? would silently turn back into this page's URL.
            $seoCanonical   = array_key_exists('canonical', $controllerSeo) ? $controllerSeo['canonical'] : $currentUrl;
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
            $seoCanonical   = $currentUrl;
            $seoKeywords    = null;
        } elseif ($isHomepage) {
            // ---- HOMEPAGE ----
            $seoTitle       = 'Suglow — Buy Authentic Cosmetics & Skincare Online in Bangladesh';
            $seoSocialTitle = $seoTitle;
            $seoDescription = "Bangladesh's largest authentic cosmetics store. Imported from Malaysia, Thailand & Indonesia. Cash on delivery nationwide. Call {$suglowPhoneText}. Open 24/7.";
            $seoImage       = $brandImage;
            $seoType        = 'website';
            $seoRobots      = 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
            $seoCanonical   = $currentUrl;
            $seoKeywords    = 'cosmetics bangladesh, skincare bangladesh, buy cosmetics online bd, authentic cosmetics bangladesh, imported cosmetics bd, online cosmetics shop bangladesh, cosmetics home delivery bangladesh, cosmetics dhaka, cosmetics rangpur, original cosmetics bd, malaysia cosmetics bangladesh, thailand cosmetics bd, korean skincare bangladesh, suglow, skin care product bd, cash on delivery cosmetics';
        } else {
            // ---- EVERY OTHER PAGE ----
            $seoTitle       = $companyName . ' — Authentic Cosmetics & Skincare in Bangladesh';
            $seoSocialTitle = $seoTitle;
            $seoDescription = "Shop authentic cosmetics & skincare at Suglow. Imported from Malaysia, Thailand & Indonesia. Cash on delivery across Bangladesh. Call {$suglowPhoneText}.";
            $seoImage       = $brandImage;
            $seoType        = 'website';
            $seoRobots      = 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
            $seoCanonical   = $currentUrl;
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
    {{-- Google reads this one too. A separate googlebot tag used to say
         "index" on every page, contradicting noindex where it was set. --}}
    <meta name="robots" content="{{ $seoRobots }}">
    <meta name="author" content="Suglow">
    @if ($seoCanonical)
        <link rel="canonical" href="{{ $seoCanonical }}">
    @endif

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
    @if ($seoCanonical)
        <meta property="og:url" content="{{ $seoCanonical }}">
    @endif
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

    {{-- ==================== META PIXEL ====================
         Meta's base code, with one change: fbevents.js is fetched once the
         page has finished loading (or after 3.5s, whichever is first), not
         while it is still drawing. `fbq` exists from this line on and queues
         every call - the init and PageView below included - so nothing is
         lost; the script replays the queue when it arrives. What this buys is
         that a 100 KB third-party script never competes with the product
         photos and the app for the first paint, which is what Google's
         real-user speed measurements (and so rankings) are taken from.

         Initialised exactly once. Customer matching is done by the
         Conversions API on the server (hashed there), not by calling
         fbq('init') a second time, which Meta flags as a duplicate pixel.
         No <noscript> image: the shop cannot be used without JavaScript, and
         an <img> inside <head> is invalid HTML that ends the head early for
         any parser that does not run scripts.

         The rest of the funnel - PageView on later screens, ViewContent,
         AddToCart, InitiateCheckout, Purchase - is fired from
         resources/js/services/pixelService.js. Nothing is printed when the id
         is unknown, and `render_base` is false when the snippet is already
         pasted in Admin -> Analytics, so the pixel is never initialised twice
         (which would double every number). --}}
    @if (!blank($metaPixel['id'] ?? null))
        <script>window.__BOOT_PIXEL__ = {!! json_encode([
            'id'         => $metaPixel['id'],
            'currency'   => $metaPixel['currency'] ?? 'BDT',
            'content_id' => $metaPixel['content_id'] ?? 'id',
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};</script>

        @if ($metaPixel['render_base'])
            <script>
                !function(f,b,e,v,n,t,s)
                {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
                n.callMethod.apply(n,arguments):n.queue.push(arguments)};
                if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
                n.queue=[];var d=0,l=function(){if(d)return;d=1;
                t=b.createElement(e);t.async=!0;
                t.src=v;s=b.getElementsByTagName(e)[0];
                s.parentNode.insertBefore(t,s)};
                if(b.readyState==='complete'){l()}else{f.addEventListener('load',l);setTimeout(l,3500)}
                }(window, document,'script',
                'https://connect.facebook.net/en_US/fbevents.js');
                fbq('init', "{{ $metaPixel['id'] }}");
                fbq('track', 'PageView');
            </script>
        @endif
    @endif
    {{-- ==================== END META PIXEL ==================== --}}

    {{-- ==================== TIKTOK PIXEL ====================
         TikTok's base code with the same one change as Meta's above: events.js
         is fetched once the page has loaded (or after 3.5s), not while it is
         still drawing. `ttq` queues every call until the script arrives.

         ttq.page() counts the landing screen only. TikTok's pixel counts the
         later screens of a single-page app by itself (it watches the URL), so
         pixelService never sends it a page view - that would count every
         screen twice. The rest of the funnel is sent from pixelService,
         beside Meta's. Nothing is printed when no id is set (TIKTOK_PIXEL_ID),
         or when the snippet is already pasted in Admin -> Analytics. See
         App\Support\TikTokPixel. --}}
    @if ($tiktokPixel['render_base'] ?? false)
        <script>
            !function (w, d, t) {
              w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie","holdConsent","revokeConsent","grantConsent"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(
              var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var r="https://analytics.tiktok.com/i18n/pixel/events.js",o=n&&n.partner;ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=r,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};
              var u=r+"?sdkid="+e+"&lib="+t,f=0,l=function(){if(f)return;f=1;var s=d.createElement("script");s.type="text/javascript",s.async=!0,s.src=u;var x=d.getElementsByTagName("script")[0];x.parentNode.insertBefore(s,x)};
              if(d.readyState==='complete'){l()}else{w.addEventListener('load',l);setTimeout(l,3500)}};
              ttq.load("{{ $tiktokPixel['id'] }}");
              ttq.page();
            }(window, document, 'ttq');
        </script>
    @endif
    {{-- ==================== END TIKTOK PIXEL ==================== --}}

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
            @elseif (!empty($productPage))
                {{-- The product as the app shows it: what an AI assistant or a
                     crawler that does not run JavaScript reads, quotes and
                     links to. Built by RootController::productFacts() from the
                     same data as the page, the JSON-LD and the feed. --}}
                <p><a href="{{ $siteUrl }}/">Home</a>
                    @if ($productPage['category_url'])
                        › <a href="{{ $productPage['category_url'] }}">{{ $productPage['category'] }}</a>
                    @endif
                    › {{ $productPage['name'] }}</p>
                <h1>{{ $productPage['name'] }}</h1>
                @if ($productPage['image'])
                    <img src="{{ $productPage['image'] }}" alt="{{ $productPage['name'] }}" width="600" height="600" style="max-width:100%;height:auto">
                @endif
                <p><strong>Price in Bangladesh: ৳{{ number_format($productPage['price'], 0) }}</strong>
                    @if ($productPage['regular_price'])
                        <del>৳{{ number_format($productPage['regular_price'], 0) }}</del>
                    @endif
                    — {{ $productPage['in_stock'] ? 'In stock' : 'Out of stock' }}</p>
                <ul>
                    @if ($productPage['brand'])
                        <li>Brand: <a href="{{ $productPage['brand_url'] }}">{{ $productPage['brand'] }}</a></li>
                    @endif
                    @if ($productPage['category'])
                        <li>Category: <a href="{{ $productPage['category_url'] }}">{{ $productPage['category'] }}</a></li>
                    @endif
                    @if ($productPage['gtin'])
                        <li>Barcode (GTIN): {{ $productPage['gtin'] }}</li>
                    @endif
                    <li>100% authentic, sold by Suglow</li>
                    <li>Cash on delivery across Bangladesh, delivered in 1-3 days</li>
                </ul>
                <h2>About {{ $productPage['name'] }}</h2>
                @foreach ($productPage['paragraphs'] as $paragraph)
                    <p>{{ $paragraph }}</p>
                @endforeach
                @if (!empty($productPage['related']))
                    <h2>More {{ $productPage['category'] ?: 'products' }} at Suglow</h2>
                    <ul>
                        @foreach ($productPage['related'] as $relatedProduct)
                            <li><a href="{{ $relatedProduct['url'] }}">{{ $relatedProduct['name'] }}</a></li>
                        @endforeach
                    </ul>
                @endif
                <p>Order online or call <a href="tel:{{ $suglowPhone }}">{{ $suglowPhoneText }}</a> — open 24/7.</p>
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
            @elseif (!empty($brandPage))
                {{-- A brand's products as plain links, with prices - what an AI
                     assistant quotes for "CeraVe price in Bangladesh". --}}
                <p><a href="{{ $siteUrl }}/">Home</a> › <a href="{{ $siteUrl }}/product">All products</a> › {{ $brandPage['name'] }}</p>
                <h1>{{ $brandPage['name'] }} Price in Bangladesh</h1>
                <p>{{ $brandPage['description'] }}</p>
                @if (!empty($brandPage['products']))
                    <h2>{{ $brandPage['name'] }} products at Suglow</h2>
                    <ul>
                        @foreach ($brandPage['products'] as $brandProduct)
                            <li><a href="{{ $brandProduct['url'] }}">{{ $brandProduct['name'] }}</a>
                                @if ($brandProduct['price'] > 0) — ৳{{ number_format($brandProduct['price'], 0) }} @endif</li>
                        @endforeach
                    </ul>
                @endif
                <p>100% authentic {{ $brandPage['name'] }}, sold by Suglow. Cash on delivery across Bangladesh.
                   Call <a href="tel:{{ $suglowPhone }}">{{ $suglowPhoneText }}</a> — open 24/7.</p>
            @elseif (!empty($listingPage))
                <h1>All Products — Authentic Cosmetics &amp; Skincare in Bangladesh</h1>
                <p>{{ $controllerSeo['description'] ?? '' }}</p>
                <h2>Shop by category</h2>
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
                @if (!empty($listingPage['brands']))
                    <h2>Shop by brand</h2>
                    <ul>
                        @foreach ($listingPage['brands'] as $listingBrand)
                            <li><a href="{{ $listingBrand['url'] }}">{{ $listingBrand['name'] }}</a></li>
                        @endforeach
                    </ul>
                @endif
                <p>Call <a href="tel:{{ $suglowPhone }}">{{ $suglowPhoneText }}</a> — open 24/7.</p>
            @elseif (!empty($category['name']))
                {{-- A category's products as plain links: the SPA draws its
                     listing with JavaScript, so without these a crawler that
                     does not run it could not reach the products at all. --}}
                <h1>{{ $category['name'] }} Price in Bangladesh</h1>
                <p>{{ $category['description'] }}</p>
                @if (!empty($category['products']))
                    <h2>{{ $category['name'] }} products at Suglow</h2>
                    <ul>
                        @foreach ($category['products'] as $categoryProduct)
                            <li><a href="{{ $categoryProduct['url'] }}">{{ $categoryProduct['name'] }}</a></li>
                        @endforeach
                    </ul>
                @endif
                <p><a href="{{ $siteUrl }}/product">All products</a> ·
                   Call <a href="tel:{{ $suglowPhone }}">{{ $suglowPhoneText }}</a> — open 24/7.</p>
            @elseif (!empty($cmsPage))
                {{-- About / Support / Legal as written in the page editor. Raw
                     on purpose: admin-authored, the same trust level as the
                     blog bodies above. --}}
                <p><a href="{{ $siteUrl }}/">Home</a> › {{ $cmsPage['name'] }}</p>
                <h1>{{ $cmsPage['name'] }}</h1>
                {!! $cmsPage['body'] !!}
                <p><a href="{{ $siteUrl }}/product">Shop authentic cosmetics</a> ·
                   Call <a href="tel:{{ $suglowPhone }}">{{ $suglowPhoneText }}</a> — open 24/7.</p>
            @elseif (!empty($notFound))
                {{-- A dead link should still lead somewhere. --}}
                <h1>Page not found</h1>
                <p>This page is not on Suglow any more. These are good places to continue:</p>
                <ul>
                    <li><a href="{{ $siteUrl }}/">Home</a></li>
                    <li><a href="{{ $siteUrl }}/product">All products</a></li>
                    <li><a href="{{ $siteUrl }}/offers">Offers</a></li>
                </ul>
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
