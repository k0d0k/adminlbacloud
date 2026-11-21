@extends('principal.layouts.app', ['title' => __('principal.site.title')])

@section('content')
    <div class="grid gap-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-navy-900">{{ __('principal.site.title') }}</h1>
            <div class="flex gap-2">
                @foreach ($locales as $code)
                    <a class="principal-button {{ $locale === $code ? 'principal-button-primary' : '' }}"
                        href="{{ route('principal.tailadmin.site.edit', ['locale' => $code]) }}"
                        @if ($locale === $code) aria-current="page" @endif>{{ strtoupper($code) }}</a>
                @endforeach
            </div>
        </div>

        @if (session('success'))
            <div class="principal-card border-green-200 bg-green-50 p-3 text-sm text-green-800">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="principal-card border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        @php
            $content = $draft['content'] ?? $published['content'] ?? [];
            $sections = collect($content);
            $hero = $sections->firstWhere('type', 'hero') ?? ['type' => 'hero', 'data' => ['title' => '', 'subtitle' => '', 'cta_text' => '', 'cta_url' => '']];
            $services = $sections->firstWhere('type', 'services') ?? ['type' => 'services', 'data' => ['items' => []]];
            $features = $sections->firstWhere('type', 'features') ?? ['type' => 'features', 'data' => ['items' => []]];
            $faq = $sections->firstWhere('type', 'faq') ?? ['type' => 'faq', 'data' => ['items' => []]];
            $contact = $sections->firstWhere('type', 'contact') ?? ['type' => 'contact', 'data' => ['email' => '', 'phone' => '', 'address' => '']];
            $seo = $draft['seo'] ?? $published['seo'] ?? [];
        @endphp

        <form method="post" action="{{ route('principal.tailadmin.site.draft') }}" class="grid gap-5" id="site-form">
            @csrf
            <input type="hidden" name="locale" value="{{ $locale }}">

            {{-- Hero --}}
            <section class="principal-card grid gap-4 p-5">
                <h2 class="font-semibold text-navy-900">{{ __('principal.site.sections.hero') }}</h2>
                <label class="grid gap-1 text-sm">
                    {{ __('principal.site.hero.title') }}
                    <input class="principal-input" name="content[0][type]" type="hidden" value="hero">
                    <input class="principal-input" name="content[0][data][title]" value="{{ $hero['data']['title'] ?? '' }}" maxlength="200">
                </label>
                <label class="grid gap-1 text-sm">
                    {{ __('principal.site.hero.subtitle') }}
                    <textarea class="principal-input" name="content[0][data][subtitle]" rows="3" maxlength="1000">{{ $hero['data']['subtitle'] ?? '' }}</textarea>
                </label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm">
                        {{ __('principal.site.hero.cta_text') }}
                        <input class="principal-input" name="content[0][data][cta_text]" value="{{ $hero['data']['cta_text'] ?? '' }}" maxlength="100">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('principal.site.hero.cta_url') }}
                        <input class="principal-input" name="content[0][data][cta_url]" value="{{ $hero['data']['cta_url'] ?? '' }}" maxlength="500">
                    </label>
                </div>
            </section>

            {{-- Services --}}
            <section class="principal-card grid gap-4 p-5">
                <h2 class="font-semibold text-navy-900">{{ __('principal.site.sections.services') }}</h2>
                <input type="hidden" name="content[1][type]" value="services">
                <div id="services-list" class="grid gap-3">
                    @foreach (($services['data']['items'] ?? []) as $i => $item)
                        <div class="grid gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-2">
                            <input class="principal-input" name="content[1][data][items][{{ $i }}][title]" value="{{ $item['title'] ?? '' }}" placeholder="{{ __('principal.site.services.title') }}">
                            <textarea class="principal-input" name="content[1][data][items][{{ $i }}][description]" rows="2" placeholder="{{ __('principal.site.services.description') }}">{{ $item['description'] ?? '' }}</textarea>
                        </div>
                    @endforeach
                </div>
                <button type="button" class="principal-button justify-self-start" onclick="addService()">{{ __('principal.site.services.add') }}</button>
            </section>

            {{-- Features --}}
            <section class="principal-card grid gap-4 p-5">
                <h2 class="font-semibold text-navy-900">{{ __('principal.site.sections.features') }}</h2>
                <input type="hidden" name="content[2][type]" value="features">
                <div id="features-list" class="grid gap-3 sm:grid-cols-2">
                    @foreach (($features['data']['items'] ?? []) as $i => $item)
                        <div class="grid gap-2 rounded-lg border border-slate-200 p-3">
                            <input class="principal-input" name="content[2][data][items][{{ $i }}][title]" value="{{ $item['title'] ?? '' }}" placeholder="{{ __('principal.site.features.title') }}">
                            <textarea class="principal-input" name="content[2][data][items][{{ $i }}][description]" rows="2" placeholder="{{ __('principal.site.features.description') }}">{{ $item['description'] ?? '' }}</textarea>
                        </div>
                    @endforeach
                </div>
                <button type="button" class="principal-button justify-self-start" onclick="addFeature()">{{ __('principal.site.features.add') }}</button>
            </section>

            {{-- FAQ --}}
            <section class="principal-card grid gap-4 p-5">
                <h2 class="font-semibold text-navy-900">{{ __('principal.site.sections.faq') }}</h2>
                <input type="hidden" name="content[3][type]" value="faq">
                <div id="faq-list" class="grid gap-3">
                    @foreach (($faq['data']['items'] ?? []) as $i => $item)
                        <div class="grid gap-2 rounded-lg border border-slate-200 p-3">
                            <input class="principal-input" name="content[3][data][items][{{ $i }}][question]" value="{{ $item['question'] ?? '' }}" placeholder="{{ __('principal.site.faq.question') }}">
                            <textarea class="principal-input" name="content[3][data][items][{{ $i }}][answer]" rows="2" placeholder="{{ __('principal.site.faq.answer') }}">{{ $item['answer'] ?? '' }}</textarea>
                        </div>
                    @endforeach
                </div>
                <button type="button" class="principal-button justify-self-start" onclick="addFaq()">{{ __('principal.site.faq.add') }}</button>
            </section>

            {{-- Contact --}}
            <section class="principal-card grid gap-4 p-5">
                <h2 class="font-semibold text-navy-900">{{ __('principal.site.sections.contact') }}</h2>
                <input type="hidden" name="content[4][type]" value="contact">
                <div class="grid gap-4 sm:grid-cols-3">
                    <label class="grid gap-1 text-sm">
                        {{ __('principal.site.contact.email') }}
                        <input class="principal-input" name="content[4][data][email]" value="{{ $contact['data']['email'] ?? '' }}" maxlength="255">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('principal.site.contact.phone') }}
                        <input class="principal-input" name="content[4][data][phone]" value="{{ $contact['data']['phone'] ?? '' }}" maxlength="50">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('principal.site.contact.address') }}
                        <input class="principal-input" name="content[4][data][address]" value="{{ $contact['data']['address'] ?? '' }}" maxlength="500">
                    </label>
                </div>
            </section>

            {{-- SEO --}}
            <section class="principal-card grid gap-4 p-5">
                <h2 class="font-semibold text-navy-900">{{ __('principal.site.sections.seo') }}</h2>
                <label class="grid gap-1 text-sm">
                    {{ __('principal.site.seo.title') }}
                    <input class="principal-input" name="seo[title]" value="{{ $seo['title'] ?? '' }}" maxlength="255">
                </label>
                <label class="grid gap-1 text-sm">
                    {{ __('principal.site.seo.description') }}
                    <textarea class="principal-input" name="seo[description]" rows="2" maxlength="500">{{ $seo['description'] ?? '' }}</textarea>
                </label>
                <label class="grid gap-1 text-sm">
                    {{ __('principal.site.seo.og_image') }}
                    <input class="principal-input" name="seo[og_image]" value="{{ $seo['og_image'] ?? '' }}" maxlength="1000">
                </label>
            </section>

            <div class="flex flex-wrap gap-2">
                <button type="submit" class="principal-button principal-button-primary">{{ __('principal.site.save_draft') }}</button>
                <button type="submit" formaction="{{ route('principal.tailadmin.site.publish') }}" class="principal-button">{{ __('principal.site.publish') }}</button>
                <a href="{{ url('/') }}" target="_blank" class="principal-button">{{ __('principal.site.preview') }}</a>
            </div>
        </form>

        {{-- Revisions --}}
        @if ($revisions->count() > 0)
            <section class="principal-card grid gap-3 p-5">
                <h2 class="font-semibold text-navy-900">{{ __('principal.site.revisions') }}</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-200">
                                <th class="py-2 pr-4">{{ __('principal.site.revision.locale') }}</th>
                                <th class="py-2 pr-4">{{ __('principal.site.revision.status') }}</th>
                                <th class="py-2 pr-4">{{ __('principal.site.revision.date') }}</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($revisions as $rev)
                                <tr class="border-b border-slate-100">
                                    <td class="py-2 pr-4">{{ strtoupper($rev->locale) }}</td>
                                    <td class="py-2 pr-4">{{ $rev->status }}</td>
                                    <td class="py-2 pr-4">{{ $rev->created_at?->format('Y-m-d H:i') }}</td>
                                    <td class="py-2">
                                        <form method="post" action="{{ route('principal.tailadmin.site.restore') }}" class="inline">
                                            @csrf
                                            <input type="hidden" name="revision_id" value="{{ $rev->id }}">
                                            <button type="submit" class="text-sm text-blue-600 hover:underline">{{ __('principal.site.restore') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @include('site.partials.media-panel', [
            'media' => $media,
            'uploadRouteName' => 'principal.tailadmin.site.media.store',
            'destroyRouteName' => 'principal.tailadmin.site.media.destroy',
            'langPrefix' => 'principal.site',
        ])
    </div>

    <script>
        let serviceCount = {{ count($services['data']['items'] ?? []) }};
        let featureCount = {{ count($features['data']['items'] ?? []) }};
        let faqCount = {{ count($faq['data']['items'] ?? []) }};

        function addService() {
            const list = document.getElementById('services-list');
            const div = document.createElement('div');
            div.className = 'grid gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-2';
            div.innerHTML = '<input class="principal-input" name="content[1][data][items][' + serviceCount + '][title]" placeholder="{{ __('principal.site.services.title') }}"><textarea class="principal-input" name="content[1][data][items][' + serviceCount + '][description]" rows="2" placeholder="{{ __('principal.site.services.description') }}"></textarea>';
            list.appendChild(div);
            serviceCount++;
        }

        function addFeature() {
            const list = document.getElementById('features-list');
            const div = document.createElement('div');
            div.className = 'grid gap-2 rounded-lg border border-slate-200 p-3';
            div.innerHTML = '<input class="principal-input" name="content[2][data][items][' + featureCount + '][title]" placeholder="{{ __('principal.site.features.title') }}"><textarea class="principal-input" name="content[2][data][items][' + featureCount + '][description]" rows="2" placeholder="{{ __('principal.site.features.description') }}"></textarea>';
            list.appendChild(div);
            featureCount++;
        }

        function addFaq() {
            const list = document.getElementById('faq-list');
            const div = document.createElement('div');
            div.className = 'grid gap-2 rounded-lg border border-slate-200 p-3';
            div.innerHTML = '<input class="principal-input" name="content[3][data][items][' + faqCount + '][question]" placeholder="{{ __('principal.site.faq.question') }}"><textarea class="principal-input" name="content[3][data][items][' + faqCount + '][answer]" rows="2" placeholder="{{ __('principal.site.faq.answer') }}"></textarea>';
            list.appendChild(div);
            faqCount++;
        }
    </script>
@endsection
