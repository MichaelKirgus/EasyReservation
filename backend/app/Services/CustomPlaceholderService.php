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
        $placeholders = CustomPlaceholder::all();
        
        // Decrypt secret placeholders for processing
        return $placeholders->mapWithKeys(function ($placeholder) {
            $key = $placeholder->key;
            $value = $placeholder->is_encrypted
                ? \Illuminate\Support\Facades\Crypt::decryptString($placeholder->value)
                : $placeholder->value;
            
            return [$key => $value];
        })->toArray();
    }
}
