<?php

namespace App;

class Validator
{
    public function validate(array $url): array
    {
        $errors = [];
        if (mb_strlen($url['name']) > 100) {
            $errors['maxLength'] = "Слишком длинный адрес сайта. Введите не более 100 символов.";
        }
        $parsedUrl = parse_url($url['name']);
        if (!isset($parsedUrl['scheme']) || ($parsedUrl['scheme'] != "http" && $parsedUrl['scheme'] != "https")) {
            $errors['scheme'] = "Некорректный URL. Добавте http:// или https:// протокол в адрес сайта.";
            $errors['url'] = $url['name'];
        }
        return $errors;
    }
}
