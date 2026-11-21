{{--
    Renders CMS section blocks for both platform and tenant frontpages.

    Each section is { type, data, visible, order }. Unknown / hidden sections
    are skipped silently so old content never breaks the public page. Rich text
    is already sanitised at publish time by RichTextSanitizer.
--}}
@php
    $sections = collect($content ?? [])->where('visible', '!==', false);
@endphp

@foreach ($sections as $section)
    @php
        $type = $section['type'] ?? '';
        $data = $section['data'] ?? [];
    @endphp

    @if ($type === 'hero')
        <section class="lba-container pt-12 sm:pt-16 lg:pt-20">
            <div class="lba-hero-panel px-6 py-14 sm:px-10 sm:py-16 lg:px-16 lg:py-24">
                <div class="relative z-10 max-w-3xl">
                    @if (!empty($data['eyebrow']))
                        <p class="lba-eyebrow text-lba-mint">{{ $data['eyebrow'] }}</p>
                    @endif
                    @if (!empty($data['title']))
                        <h1 class="mt-4 text-4xl font-semibold leading-[1.08] tracking-tight text-white sm:text-5xl lg:text-6xl">
                            {{ $data['title'] }}
                        </h1>
                    @endif
                    @if (!empty($data['subtitle']))
                        <p class="mt-5 max-w-2xl text-base leading-relaxed text-white/75 sm:text-lg">
                            {{ $data['subtitle'] }}
                        </p>
                    @endif
                    @if (!empty($data['cta_text']))
                        <div class="mt-8 flex flex-wrap gap-3">
                            <a href="{{ $data['cta_url'] ?? '#' }}" class="lba-btn lba-btn--primary">
                                {{ $data['cta_text'] }}
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </section>

    @elseif ($type === 'about')
        @if (!empty($data['text']) || !empty($data['title']))
            <section class="lba-section">
                <div class="lba-container">
                    <div class="max-w-3xl">
                        @if (!empty($data['eyebrow']))<p class="lba-eyebrow">{{ $data['eyebrow'] }}</p>@endif
                        @if (!empty($data['title']))<h2 class="mt-3 text-3xl font-semibold tracking-tight text-lba-navy sm:text-4xl">{{ $data['title'] }}</h2>@endif
                        @if (!empty($data['text']))<p class="mt-4 text-base leading-relaxed text-lba-ink">{{ $data['text'] }}</p>@endif
                    </div>
                </div>
            </section>
        @endif

    @elseif ($type === 'services')
        @php $items = $data['items'] ?? []; @endphp
        @if (count($items) > 0)
            <section class="lba-section">
                <div class="lba-container">
                    <div class="lba-reveal max-w-2xl">
                        @if (!empty($data['eyebrow']))<p class="lba-eyebrow">{{ $data['eyebrow'] }}</p>@endif
                        @if (!empty($data['title']))<h2 class="mt-3 text-3xl font-semibold tracking-tight text-lba-navy sm:text-4xl">{{ $data['title'] }}</h2>@endif
                        @if (!empty($data['description']))<p class="mt-4 text-base leading-relaxed text-lba-ink">{{ $data['description'] }}</p>@endif
                    </div>
                    <div class="mt-10 grid gap-6 md:grid-cols-3">
                        @foreach ($items as $item)
                            <article class="lba-card lba-reveal">
                                @if (!empty($item['title']))<h3 class="text-lg font-semibold text-lba-navy">{{ $item['title'] }}</h3>@endif
                                @if (!empty($item['description']))<p class="mt-2 text-sm leading-relaxed text-lba-ink">{{ $item['description'] }}</p>@endif
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

    @elseif ($type === 'features')
        @php $items = $data['items'] ?? []; @endphp
        @if (count($items) > 0)
            <section class="lba-section border-t border-lba-line bg-white">
                <div class="lba-container">
                    <div class="lba-reveal max-w-2xl">
                        @if (!empty($data['eyebrow']))<p class="lba-eyebrow">{{ $data['eyebrow'] }}</p>@endif
                        @if (!empty($data['title']))<h2 class="mt-3 text-3xl font-semibold tracking-tight text-lba-navy sm:text-4xl">{{ $data['title'] }}</h2>@endif
                    </div>
                    <ul class="mt-10 grid gap-x-8 gap-y-6 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($items as $item)
                            <li class="lba-reveal flex items-start gap-3">
                                <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-lba-teal/10 text-lba-teal" aria-hidden="true">
                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none"><path d="m5.5 10.5 3 3L14.5 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </span>
                                <div>
                                    @if (!empty($item['title']))<h3 class="text-sm font-semibold text-lba-navy">{{ $item['title'] }}</h3>@endif
                                    @if (!empty($item['description']))<p class="mt-1 text-sm leading-relaxed text-lba-ink">{{ $item['description'] }}</p>@endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>
        @endif

    @elseif ($type === 'faq')
        @php $items = $data['items'] ?? []; @endphp
        @if (count($items) > 0)
            <section class="lba-section">
                <div class="lba-container">
                    <div class="lba-reveal max-w-2xl">
                        @if (!empty($data['eyebrow']))<p class="lba-eyebrow">{{ $data['eyebrow'] }}</p>@endif
                        @if (!empty($data['title']))<h2 class="mt-3 text-3xl font-semibold tracking-tight text-lba-navy sm:text-4xl">{{ $data['title'] }}</h2>@endif
                    </div>
                    <div class="mt-10 grid gap-4">
                        @foreach ($items as $i => $item)
                            <details class="lba-card lba-reveal group" @if ($i === 0) open @endif>
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 [&::-webkit-details-marker]:hidden">
                                    @if (!empty($item['question']))<span class="font-semibold text-lba-navy">{{ $item['question'] }}</span>@endif
                                    <svg class="h-5 w-5 shrink-0 text-lba-mist transition group-open:rotate-180" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M5 8l5 5 5-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </summary>
                                @if (!empty($item['answer']))<p class="mt-3 text-sm leading-relaxed text-lba-ink">{{ $item['answer'] }}</p>@endif
                            </details>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

    @elseif ($type === 'contact')
        <section class="lba-section border-t border-lba-line bg-white">
            <div class="lba-container">
                <div class="lba-reveal max-w-2xl">
                    @if (!empty($data['eyebrow']))<p class="lba-eyebrow">{{ $data['eyebrow'] }}</p>@endif
                    @if (!empty($data['title']))<h2 class="mt-3 text-3xl font-semibold tracking-tight text-lba-navy sm:text-4xl">{{ $data['title'] }}</h2>@endif
                </div>
                <div class="mt-10 grid gap-6 sm:grid-cols-3">
                    @if (!empty($data['email']))
                        <article class="lba-card lba-reveal">
                            <h3 class="text-sm font-semibold text-lba-navy">{{ __('site.contact.email') }}</h3>
                            <a href="mailto:{{ $data['email'] }}" class="mt-2 block text-sm text-lba-ink hover:text-lba-teal">{{ $data['email'] }}</a>
                        </article>
                    @endif
                    @if (!empty($data['phone']))
                        <article class="lba-card lba-reveal">
                            <h3 class="text-sm font-semibold text-lba-navy">{{ __('site.contact.phone') }}</h3>
                            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $data['phone']) }}" class="mt-2 block text-sm text-lba-ink hover:text-lba-teal">{{ $data['phone'] }}</a>
                        </article>
                    @endif
                    @if (!empty($data['whatsapp']))
                        <article class="lba-card lba-reveal">
                            <h3 class="text-sm font-semibold text-lba-navy">WhatsApp</h3>
                            <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $data['whatsapp']) }}" target="_blank" rel="noopener" class="mt-2 block text-sm text-lba-ink hover:text-lba-teal">{{ $data['whatsapp'] }}</a>
                        </article>
                    @endif
                    @if (!empty($data['address']))
                        <article class="lba-card lba-reveal">
                            <h3 class="text-sm font-semibold text-lba-navy">{{ __('site.contact.address') }}</h3>
                            <p class="mt-2 text-sm text-lba-ink">{{ $data['address'] }}</p>
                        </article>
                    @endif
                </div>
            </div>
        </section>
    @endif
@endforeach
