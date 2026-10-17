{{-- Every live category page, from the database. This list used to be typed
     out by hand, so it went stale: it linked /product-category/men-skin-care
     (404) and skin-care6 (a redirect) and never learnt about Make-up or Mask. --}}
<ul>
    @foreach (\App\Support\CategoryMetaResolver::all() as $liveCategory)
        <li><a href="{{ $liveCategory['url'] }}">{{ $liveCategory['name'] }}</a></li>
    @endforeach
</ul>
