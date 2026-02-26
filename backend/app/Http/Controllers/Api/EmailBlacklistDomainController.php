<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailBlacklistDomain;
use Illuminate\Http\Request;

class EmailBlacklistDomainController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = EmailBlacklistDomain::query();

        // Search functionality
        if ($search = $request->get('search')) {
            $query->where('domain', 'like', '%' . $search . '%');
        }

        // Filter by active status
        if ($active = $request->get('active')) {
            $query->where('active', $active);
        }

        $domains = $query->orderBy(
            $request->get('sort_key', 'domain'),
            $request->get('sort_dir', 'asc')
        )->paginate($request->get('page_size', 20));

        return response()->json($domains);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'domain' => ['required', 'string', function ($attribute, $value, $fail) {
                // Allow empty values (handled by required)
                if (empty($value)) {
                    return;
                }
                
                $value = trim($value);
                
                // Check if it's a valid email
                if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return;
                }
                
                // Check if it's a valid domain format (no @ symbol)
                if (!str_contains($value, '@') && preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $value)) {
                    return;
                }
                
                $fail('The ' . $attribute . ' must be a valid email address or domain.');
            }],
            'active' => 'nullable|boolean',
        ]);

        // Extract domain from email if provided as full email
        $domain = $validated['domain'];
        if (!str_contains($domain, '@')) {
            // Validate it's a valid domain format
            if (!filter_var('test@' . $domain, FILTER_VALIDATE_EMAIL)) {
                return response()->json(['error' => 'Invalid domain format'], 422);
            }
        } else {
            // Extract domain from email
            $domain = substr(strrchr($domain, '@'), 1);
        }

        $domain = strtolower(trim($domain));

        // Check if domain already exists
        $existing = EmailBlacklistDomain::where('domain', $domain)->first();
        if ($existing) {
            return response()->json(['error' => 'Domain already in blacklist'], 422);
        }

        $blacklistDomain = EmailBlacklistDomain::create([
            'domain' => $domain,
            'active' => $validated['active'] ?? true,
        ]);

        return response()->json($blacklistDomain, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $domain = EmailBlacklistDomain::find($id);

        if (!$domain) {
            return response()->json(['error' => 'Domain not found'], 404);
        }

        return response()->json($domain);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $domain = EmailBlacklistDomain::find($id);

        if (!$domain) {
            return response()->json(['error' => 'Domain not found'], 404);
        }

        $validated = $request->validate([
            'domain' => ['required', 'string', function ($attribute, $value, $fail) {
                if (empty($value)) {
                    return;
                }
                
                $value = trim($value);
                
                if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return;
                }
                
                if (!str_contains($value, '@') && preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $value)) {
                    return;
                }
                
                $fail('The ' . $attribute . ' must be a valid email address or domain.');
            }],
            'active' => 'nullable|boolean',
        ]);

        // Extract domain from email if provided as full email
        $newDomain = $validated['domain'];
        if (!str_contains($newDomain, '@')) {
            if (!filter_var('test@' . $newDomain, FILTER_VALIDATE_EMAIL)) {
                return response()->json(['error' => 'Invalid domain format'], 422);
            }
        } else {
            $newDomain = substr(strrchr($newDomain, '@'), 1);
        }

        $newDomain = strtolower(trim($newDomain));

        // Check if new domain already exists for another record
        $existing = EmailBlacklistDomain::where('domain', $newDomain)->where('id', '!=', $id)->first();
        if ($existing) {
            return response()->json(['error' => 'Domain already in blacklist'], 422);
        }

        $domain->update([
            'domain' => $newDomain,
            'active' => $validated['active'] ?? $domain->active,
        ]);

        return response()->json($domain);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $domain = EmailBlacklistDomain::find($id);

        if (!$domain) {
            return response()->json(['error' => 'Domain not found'], 404);
        }

        $domain->delete();

        return response()->json(['message' => 'Domain removed from blacklist']);
    }
}
