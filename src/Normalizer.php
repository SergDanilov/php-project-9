<?php

namespace App;

class Normalizer
{
    public function normalizeUrl(string $url): ?string
    {
        $url = mb_strtolower(trim($url));
        if (!parse_url($url, PHP_URL_SCHEME)) {
                $url = "http://{$url}";
        }
        $parts = parse_url($url);
        return ($parts && !empty($parts['host']))
                ? "{$parts['scheme']}://{$parts['host']}"
                : null;
    }
}
