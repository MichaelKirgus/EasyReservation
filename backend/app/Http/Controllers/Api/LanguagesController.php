<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;

class LanguagesController extends Controller
{
    public function index(): JsonResponse
    {
        // Get all available language files from resources/lang directory
        $langDirectory = resource_path('lang');
        $languageFiles = File::glob("{$langDirectory}/*.json");
        
        // Extract language codes from filenames (e.g., de.json -> de)
        $languages = [];
        foreach ($languageFiles as $file) {
            $filename = basename($file, '.json');
            $languages[] = $filename;
        }
        
        return response()->json($languages);
    }
    
    public function names(): JsonResponse
    {
        // Get all available language files from resources/lang directory
        $langDirectory = resource_path('lang');
        $languageFiles = File::glob("{$langDirectory}/*.json");
        
        // Extract language codes and their display names
        $names = [];
        foreach ($languageFiles as $file) {
            $filename = basename($file, '.json');
            
            // For now, we'll use basic language names
            // In a real implementation, you might want to load these from a translation file or have a mapping
            switch ($filename) {
                case 'de':
                    $names[$filename] = 'Deutsch';
                    break;
                case 'en':
                    $names[$filename] = 'English';
                    break;
                default:
                    $names[$filename] = ucfirst($filename);
            }
        }
        
        return response()->json($names);
    }
}