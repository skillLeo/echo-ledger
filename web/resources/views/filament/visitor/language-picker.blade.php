{{--
    Signed out (since v2.1): the sign-in page's language, each name written in its own language. Picking one keeps it in
    a cookie for the signed-out pages only (VisitorLanguage); once signed in, the account's console language applies.
--}}
<nav aria-label="{{ __('Language') }}" data-visitor-language
    style="display:flex;justify-content:center;gap:16px;padding:16px 0 24px;font-size:.875rem;color:var(--text-muted)">
    @foreach (\App\Http\Middleware\VisitorLanguage::NAMES as $code => $name)
        @if (app()->isLocale($code))
            <span lang="{{ $code }}" aria-current="true" style="font-weight:600;color:var(--text)">{{ $name }}</span>
        @else
            <a href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}" lang="{{ $code }}" hreflang="{{ $code }}" style="text-decoration:underline">{{ $name }}</a>
        @endif
    @endforeach
</nav>
