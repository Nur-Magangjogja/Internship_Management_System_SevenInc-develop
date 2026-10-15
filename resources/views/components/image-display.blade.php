@props([
    'src' => null,
    'alt' => 'Gambar',
    'class' => 'w-10 h-10 rounded-full object-cover',
    'fallbackInitial' => null,
    'type' => 'avatar', // avatar, brand, webinar, general
])

@php
    $resolvedSrc = null;
    $initial = strtoupper(substr((string) ($fallbackInitial ?? $alt ?? 'U'), 0, 1));

    if (!empty($src)) {
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://') || str_starts_with($src, 'data:')) {
            $resolvedSrc = $src;
        } elseif (\Illuminate\Support\Facades\Storage::disk('public')->exists($src)) {
            $resolvedSrc = asset('storage/' . ltrim($src, '/'));
        } elseif (file_exists(public_path($src))) {
            $resolvedSrc = asset(ltrim($src, '/'));
        } elseif (\Illuminate\Support\Facades\Storage::disk('local')->exists($src)) {
            $resolvedSrc = route('media.private.serve', ['path' => base64_encode($src)]);
        }
    }
@endphp

@if ($resolvedSrc)
    <img src="{{ $resolvedSrc }}"
         alt="{{ $alt }}"
         class="{{ $class }}"
         loading="lazy"
         onerror="this.onerror=null; this.classList.add('hidden'); this.nextElementSibling?.classList.remove('hidden');" />
@endif

<div class="{{ $resolvedSrc ? 'hidden ' : '' }}{{ $class }} flex items-center justify-center font-bold select-none {{ $type === 'avatar' ? 'bg-[#E8F5E9] text-[#1a5c38] border border-[#C8E6C9]' : 'bg-gray-100 text-gray-500 border border-gray-200' }}"
     title="{{ $alt }}">
    @if ($type === 'avatar')
        <span class="text-xs uppercase">{{ $initial }}</span>
    @elseif ($type === 'brand' || $type === 'webinar')
        <svg class="w-1/2 h-1/2 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect width="18" height="18" x="3" y="3" rx="2" ry="2"/>
            <circle cx="9" cy="9" r="2"/>
            <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>
        </svg>
    @else
        <span class="text-xs font-semibold">{{ $initial }}</span>
    @endif
</div>
