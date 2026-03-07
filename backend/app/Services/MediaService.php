<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class MediaService
{
    public function listImages(string $category): array
    {
        $config = config("media.categories.{$category}");
        if (! $config) {
            return [];
        }

        $basePath = rtrim($config['path'] ?? '', '/');
        $allowedExtensions = config('media.allowed_extensions', []);

        $files = Storage::disk('public')->files($basePath);

        $filtered = array_values(array_filter($files, function ($file) use ($allowedExtensions) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            return in_array($ext, $allowedExtensions, true);
        }));

        return array_map(function ($file) {
            $filename = basename($file);
            $url = Storage::url($file);
            return [
                'filename' => $filename,
                'path' => $url,
                'url' => $url,
            ];
        }, $filtered);
    }

    public function allowedValuesForCategory(string $category): array
    {
        return Arr::pluck($this->listImages($category), 'path');
    }

    public function isAllowedSetting(string $settingName, string $value): bool
    {
        if ($value === '') {
            return true;
        }

        $map = config('media.setting_category_map', []);
        $category = $map[$settingName] ?? null;
        if (! $category) {
            return true;
        }

        $allowed = $this->allowedValuesForCategory($category);
        // allow with or without leading slash
        $normalized = ltrim($value, '/');
        foreach ($allowed as $item) {
            if ($value === $item || $normalized === ltrim($item, '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * List files in a storage directory.
     */
    public function listFiles(string $directory, array $allowedExtensions = []): array
    {
        $basePath = rtrim($directory, '/');
        
        $files = Storage::disk('public')->files($basePath);
        
        $filtered = array_values(array_filter($files, function ($file) use ($allowedExtensions) {
            if (empty($allowedExtensions)) {
                return true;
            }
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            return in_array($ext, $allowedExtensions, true);
        }));

        return array_map(function ($file) {
            $filename = basename($file);
            $url = Storage::url($file);
            return [
                'filename' => $filename,
                'path' => $url,
                'url' => $url,
            ];
        }, $filtered);
    }
}
