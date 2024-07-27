<?php

namespace App;

class Validator
{
    public function validate(array $url): array
    {
        $errors = [];
        if (mb_strlen($url['name']) > 255) {
            $errors['maxLength'] = "Url length must be less than 255 characters";
        }

        return $errors;
    }
}