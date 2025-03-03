<?php

namespace App;

use Illuminate\Support\Arr;

class Normalizer
{
    public function normalizeUrl(string $url): string|null
    {
        $url = mb_strtolower(trim($url));

        // Добавляем схему, если её нет
        if (!parse_url($url, PHP_URL_SCHEME)) {
            $url = "http://{$url}";
        }

        $parts = parse_url($url);

        if (!$parts || empty($parts['host'])) {
            return null;
        }
        // По умолчанию 'http', если схема не указана
        $scheme = Arr::get($parts, 'scheme', 'http');

        return "{$scheme}://{$parts['host']}";
    }
}
