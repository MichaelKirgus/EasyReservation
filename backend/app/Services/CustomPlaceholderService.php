<?php

namespace App\Services;

use App\Models\CustomPlaceholder;

class CustomPlaceholderService
{
    /**
     * Gibt alle benutzerdefinierten Platzhalter als key=>value Array zurück.
     */
    public function getAll(): array
    {
        return CustomPlaceholder::all()->pluck('value', 'key')->toArray();
    }
}
