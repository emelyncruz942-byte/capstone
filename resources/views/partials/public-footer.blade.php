<footer class="mt-6 text-center text-[10px] leading-5 text-slate-500">
    <nav class="flex flex-wrap items-center justify-center gap-x-4 gap-y-1" aria-label="Public pages">
        @unless(request()->routeIs('login'))
            <a href="{{ route('login') }}" class="transition-colors hover:text-cyan-300">Home</a>
        @endunless
        <a href="{{ route('privacy-policy') }}"
           @if(request()->routeIs('privacy-policy')) aria-current="page" @endif
           class="transition-colors hover:text-cyan-300 {{ request()->routeIs('privacy-policy') ? 'text-cyan-300' : '' }}">
            Privacy Policy
        </a>
        <a href="{{ route('terms-and-conditions') }}"
           @if(request()->routeIs('terms-and-conditions')) aria-current="page" @endif
           class="transition-colors hover:text-cyan-300 {{ request()->routeIs('terms-and-conditions') ? 'text-cyan-300' : '' }}">
            Terms and Conditions
        </a>
    </nav>
    <p class="mt-2">&copy; {{ now()->year }} MathVerse. Educational use.</p>
</footer>
