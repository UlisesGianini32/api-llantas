<?php

namespace App\Services\Telegram;

class TelegramText
{
    public function fromMeli(mixed $value): string
    {
        $html = trim((string) $value);
        if ($html === '') {
            return 'Mensaje sin texto visible';
        }

        if (! preg_match('/<\/?[a-z][^>]*>/i', $html)) {
            return $this->clean(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // The result is always sent as Telegram plain text (never parse_mode HTML).
        $html = preg_replace('#<(script|style|iframe|object|embed|svg|form|button)\b[^>]*>.*?</\1\s*>#isu', '', $html) ?? $html;
        $html = preg_replace('#<\s*li\b[^>]*>#iu', "\n• ", $html) ?? $html;
        $html = preg_replace('#<\s*br\s*/?\s*>#iu', "\n", $html) ?? $html;
        $html = preg_replace('#</\s*(p|div|li|ul|ol)\s*>#iu', "\n", $html) ?? $html;

        return $this->clean(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function clean(string $text): string
    {
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/[ \t]+\n/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        $text = trim($text);

        return $text !== '' ? $text : 'Mensaje sin texto visible';
    }
}
