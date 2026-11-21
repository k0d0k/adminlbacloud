{{--
    Shared website CMS media panel.

    Renders an upload form plus the gallery of existing assets for either the
    platform or a tenant editor. Assets are streamed through the published-gated
    media endpoint, so the current editor (who holds the manage permission) can
    preview a DRAFT asset while an anonymous visitor cannot.

    Expected variables:
      - $media            Collection of SiteMedia rows
      - $uploadRouteName  route name for the upload endpoint
      - $destroyRouteName route name for the delete endpoint
      - $langPrefix       'principal.site' or 'clinic.site'
--}}
<section class="principal-card grid gap-4 p-5">
    <h2 class="font-semibold text-navy-900">{{ __($langPrefix.'.media.title') }}</h2>

    <form method="post" action="{{ route($uploadRouteName) }}" enctype="multipart/form-data" class="grid gap-3 sm:grid-cols-2">
        @csrf
        <label class="grid gap-1 text-sm">
            {{ __($langPrefix.'.media.choose') }}
            <input class="principal-input" type="file" name="media" required
                accept="image/jpeg,image/png,image/webp,image/gif,image/svg+xml">
        </label>
        <label class="grid gap-1 text-sm">
            {{ __($langPrefix.'.media.alt_text') }}
            <input class="principal-input" name="alt_text" maxlength="255">
        </label>
        @error('media')
            <p class="text-sm text-red-600 sm:col-span-2">{{ $message }}</p>
        @enderror
        <p class="text-xs text-slate-500 sm:col-span-2">
            {{ __($langPrefix.'.media.hint', ['size' => config('lba.site.media_max_kilobytes')]) }}
        </p>
        <button type="submit" class="principal-button principal-button-primary justify-self-start sm:col-span-2">
            {{ __($langPrefix.'.media.submit') }}
        </button>
    </form>

    @if ($media->isEmpty())
        <p class="text-sm text-slate-500">{{ __($langPrefix.'.media.empty') }}</p>
    @else
        <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($media as $asset)
                <figure class="grid gap-2 rounded-lg border border-slate-200 p-2">
                    <img src="{{ $asset->url() }}" alt="{{ $asset->alt_text ?? '' }}"
                        class="h-28 w-full rounded object-cover">
                    <form method="post" action="{{ route($destroyRouteName, $asset) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-sm text-red-600 hover:underline"
                            onclick="return confirm('{{ __($langPrefix.'.media.delete_prompt') }}')">
                            {{ __($langPrefix.'.media.delete') }}
                        </button>
                    </form>
                </figure>
            @endforeach
        </div>
    @endif
</section>
