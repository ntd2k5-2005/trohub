<?php

namespace App\Support;

final class FormIdentity
{
    public static function key(array $form): string
    {
        $key = $form['action'].'-'.($form['id'] ?? 'new');
        if (in_array($form['action'], ['rental_request.decide', 'reservation.action'], true)) {
            $decision = collect($form['fields'])->firstWhere('name', 'decision')['value'] ?? '';
            $key .= '-'.$decision;
        }

        return $key;
    }
}
