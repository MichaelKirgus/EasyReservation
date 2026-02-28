<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Support\Facades\File;

class FlagController extends Controller
{
    public function show(string $lang): Response
    {
        // Check if translation file exists for the language
        $file = resource_path("lang/{$lang}.json");
        if (! file_exists($file)) {
            throw new NotFoundHttpException('Language not supported');
        }

        // Return actual SVG flag from backend resources
        $svg = $this->getFlagSvg($lang);
        
        return response($svg, 200)->header('Content-Type', 'image/svg+xml');
    }

    private function getFlagSvg(string $lang): string
    {
        // Define mapping of language codes to SVG files in backend resources
        $flagFiles = [
            'de' => 'flag-de.svg',
            'en' => 'flag-us.svg',  // Using US flag for English as default
        ];

        // Check if we have a specific flag file for this language
        if (isset($flagFiles[$lang])) {
            $svgPath = resource_path("assets/icons/{$flagFiles[$lang]}");
            if (File::exists($svgPath)) {
                return File::get($svgPath);
            }
        }

        $fallbackPath = resource_path('assets/icons/flag-us.svg');
        if (File::exists($fallbackPath)) {
            return File::get($fallbackPath);
        }
        
        // If no SVG files are found, return a basic placeholder
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 480"><rect width="640" height="480" fill="#ff00f2"/><rect width="640" height="320" y="160" fill="#1eff00"/><rect width="640" height="160" y="320" fill="rgb(0, 68, 255)"/></svg>';
    }
}