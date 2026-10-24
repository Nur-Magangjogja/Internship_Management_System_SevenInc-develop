@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Navigasi Halaman" class="flex items-center justify-between px-3 py-3 sm:px-4 border-t border-gray-100 bg-white rounded-b-xl select-none">
        {{-- Mobile Compact View --}}
        <div class="flex flex-1 justify-between sm:hidden items-center gap-2">
            @if ($paginator->onFirstPage())
                <span class="inline-flex items-center px-3 py-1.5 text-xs font-semibold text-gray-400 bg-gray-50 border border-gray-200 rounded-lg cursor-not-allowed">
                    &larr; Prev
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" class="inline-flex items-center px-3 py-1.5 text-xs font-semibold text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition active:bg-gray-100">
                    &larr; Prev
                </a>
            @endif

            <span class="text-xs font-medium text-gray-600">
                Hal. <strong class="text-gray-900">{{ $paginator->currentPage() }}</strong> / {{ $paginator->lastPage() }}
            </span>

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" class="inline-flex items-center px-3 py-1.5 text-xs font-semibold text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition active:bg-gray-100">
                    Next &rarr;
                </a>
            @else
                <span class="inline-flex items-center px-3 py-1.5 text-xs font-semibold text-gray-400 bg-gray-50 border border-gray-200 rounded-lg cursor-not-allowed">
                    Next &rarr;
                </span>
            @endif
        </div>

        {{-- Desktop & Tablet View --}}
        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
            <div>
                <p class="text-xs text-gray-600">
                    Menampilkan
                    <span class="font-semibold text-gray-800">{{ $paginator->firstItem() ?? 0 }}</span>
                    sampai
                    <span class="font-semibold text-gray-800">{{ $paginator->lastItem() ?? 0 }}</span>
                    dari total
                    <span class="font-semibold text-gray-800">{{ $paginator->total() }}</span>
                    {{ $itemLabel ?? 'data' }}
                </p>
            </div>

            <div>
                <span class="relative z-0 inline-flex rounded-lg shadow-sm -space-x-px">
                    {{-- Previous Page Link --}}
                    @if ($paginator->onFirstPage())
                        <span aria-disabled="true" aria-label="Sebelumnya" class="relative inline-flex items-center px-2.5 py-1.5 rounded-l-lg border border-gray-200 bg-gray-50 text-xs font-medium text-gray-400 cursor-not-allowed">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        </span>
                    @else
                        <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Sebelumnya" class="relative inline-flex items-center px-2.5 py-1.5 rounded-l-lg border border-gray-300 bg-white text-xs font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        </a>
                    @endif

                    {{-- Pagination Elements --}}
                    @foreach ($elements as $element)
                        {{-- Three Dots Separator --}}
                        @if (is_string($element))
                            <span aria-disabled="true" class="relative inline-flex items-center px-3 py-1.5 border border-gray-300 bg-gray-50 text-xs font-medium text-gray-500">
                                {{ $element }}
                            </span>
                        @endif

                        {{-- Array Of Links --}}
                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                @if ($page == $paginator->currentPage())
                                    <span aria-current="page" class="z-10 relative inline-flex items-center px-3.5 py-1.5 border border-[#1a5c38] bg-[#1a5c38] text-xs font-bold text-white shadow-sm">
                                        {{ $page }}
                                    </span>
                                @else
                                    <a href="{{ $url }}" class="relative inline-flex items-center px-3.5 py-1.5 border border-gray-300 bg-white text-xs font-medium text-gray-700 hover:bg-gray-50 hover:text-gray-900 transition">
                                        {{ $page }}
                                    </a>
                                @endif
                            @endforeach
                        @endif
                    @endforeach

                    {{-- Next Page Link --}}
                    @if ($paginator->hasMorePages())
                        <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Berikutnya" class="relative inline-flex items-center px-2.5 py-1.5 rounded-r-lg border border-gray-300 bg-white text-xs font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </a>
                    @else
                        <span aria-disabled="true" aria-label="Berikutnya" class="relative inline-flex items-center px-2.5 py-1.5 rounded-r-lg border border-gray-200 bg-gray-50 text-xs font-medium text-gray-400 cursor-not-allowed">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    @endif
                </span>
            </div>
        </div>
    </nav>
@endif
