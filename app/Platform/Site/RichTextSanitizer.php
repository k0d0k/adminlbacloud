<?php

namespace App\Platform\Site;

/**
 * Sanitizes rich text input from the CMS editor.
 *
 * Allows only a safe subset of inline/block tags and strips everything else —
 * including <script>, <style>, <iframe>, <object>, event handlers (on*), and
 * javascript: URLs. Used before storing CMS content so arbitrary HTML/PHP/JS
 * can never be persisted or rendered on the public frontpage.
 */
final class RichTextSanitizer
{
    private const ALLOWED_TAGS = '<p><br><strong><em><b><i><u><ul><ol><li><a><h1><h2><h3><h4><h5><h6><blockquote><span><div>';

    /**
     * Sanitize an HTML string, returning only safe markup.
     */
    public function sanitize(string $html): string
    {
        // Strip PHP and script/style/iframe/object tags entirely (with content).
        $html = preg_replace('/<\?.*?\?>/is', '', $html) ?? $html;
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        $html = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/is', '', $html) ?? $html;
        $html = preg_replace('/<object\b[^>]*>.*?<\/object>/is', '', $html) ?? $html;

        // Allow only the safe tag subset.
        $html = strip_tags($html, self::ALLOWED_TAGS);

        // Remove all event handler attributes (onclick, onload, etc.).
        $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;

        // Remove javascript: / data: URLs in href/src attributes.
        $html = preg_replace(
            '/(href|src)\s*=\s*("|\')\s*(javascript|data|vbscript):/i',
            '$1=$2#',
            $html
        ) ?? $html;

        // Remove style attributes (could inject CSS-based attacks).
        $html = preg_replace('/\sstyle\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $html) ?? $html;

        return trim($html);
    }

    /**
     * Sanitize an array of section data, walking nested arrays/strings.
     *
     * @param  array<string, mixed>  $sections
     * @return array<string, mixed>
     */
    public function sanitizeSections(array $sections): array
    {
        return array_map(function ($section) {
            if (! is_array($section)) {
                return $section;
            }

            foreach ($section as $key => $value) {
                if (is_string($value)) {
                    $section[$key] = $this->sanitize($value);
                } elseif (is_array($value)) {
                    $section[$key] = $this->sanitizeSections($value);
                }
            }

            return $section;
        }, $sections);
    }
}
