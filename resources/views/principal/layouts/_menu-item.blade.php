@php
    $label = $item['label'];
    $href = $item['href'];
@endphp
{{--
    One recursive level of a dynamically composed sidebar entry (Menu Dinamis).

    A node without an href is a plain grouping label; a node with children
    renders its subtree indented beneath it. Visibility was already re-derived
    server-side by `Menu::treeFor()`, so this only paints what it is given.
--}}
@if ($href)
    <a class="principal-link" href="{{ $href }}" title="{{ $label }}">
        <span class="principal-link__icon size-5 place-items-center text-center text-xs" aria-hidden="true">•</span>
        <span class="principal-link__label">{{ $label }}</span>
    </a>
@else
    <span class="principal-link" title="{{ $label }}">
        <span class="principal-link__icon size-5 place-items-center text-center text-xs" aria-hidden="true">•</span>
        <span class="principal-link__label">{{ $label }}</span>
    </span>
@endif
@foreach ($item['children'] as $child)
    <div class="ms-4 border-s border-white/10 ps-2">
        @include('principal.layouts._menu-item', ['item' => $child])
    </div>
@endforeach
