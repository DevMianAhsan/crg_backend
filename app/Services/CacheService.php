<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CacheService
{
    /**
     * Cache group definitions mapping to URI path segments.
     */
    public const GROUP_CANDIDATES    = 'candidates';
    public const GROUP_COMPANIES     = 'companies';
    public const GROUP_DOCUMENT_TYPES= 'document_types';
    public const GROUP_COUNTRIES     = 'countries';
    public const GROUP_STAFF         = 'staff';
    public const GROUP_DRIVE         = 'drive';
    public const GROUP_LEDGER        = 'ledger';
    public const GROUP_NOTIFICATIONS = 'notifications';
    public const GROUP_SETTINGS      = 'settings';

    /**
     * Default cache TTL in seconds (24 hours).
     * With group versioning, entries are valid until invalidated.
     */
    public const DEFAULT_TTL = 86400;

    /**
     * Get the current version token for a cache group.
     */
    public static function getGroupVersion(string $group): string
    {
        $key = 'cgv:' . $group;
        $version = Cache::get($key);

        if (!$version) {
            $version = (string) (microtime(true) . '_' . mt_rand(1000, 9999));
            Cache::forever($key, $version);
        }

        return (string) $version;
    }

    /**
     * Invalidate one or more cache groups immediately.
     */
    public static function invalidateGroup(string|array $groups): void
    {
        $groups = (array) $groups;

        foreach ($groups as $group) {
            $key = 'cgv:' . $group;
            $newVersion = (string) (microtime(true) . '_' . mt_rand(1000, 9999));
            Cache::forever($key, $newVersion);
        }
    }

    /**
     * Invalidate all application cache groups.
     */
    public static function invalidateAll(): void
    {
        $allGroups = [
            self::GROUP_CANDIDATES,
            self::GROUP_COMPANIES,
            self::GROUP_DOCUMENT_TYPES,
            self::GROUP_COUNTRIES,
            self::GROUP_STAFF,
            self::GROUP_DRIVE,
            self::GROUP_LEDGER,
            self::GROUP_NOTIFICATIONS,
            self::GROUP_SETTINGS,
        ];

        self::invalidateGroup($allGroups);
    }

    /**
     * Determine which cache groups a request depends on.
     */
    public static function getRouteGroups(Request $request): array
    {
        $path = trim($request->path(), '/');

        // Strip 'api/' prefix if present
        if (str_starts_with($path, 'api/')) {
            $path = substr($path, 4);
        }

        if (str_starts_with($path, 'candidates')) {
            // Candidates list/counts depend on mandatory document types and companies as well
            return [self::GROUP_CANDIDATES, self::GROUP_DOCUMENT_TYPES, self::GROUP_COMPANIES];
        }

        if (str_starts_with($path, 'companies')) {
            return [self::GROUP_COMPANIES];
        }

        if (str_starts_with($path, 'document-types')) {
            return [self::GROUP_DOCUMENT_TYPES];
        }

        if (str_starts_with($path, 'countries')) {
            return [self::GROUP_COUNTRIES];
        }

        if (str_starts_with($path, 'staff')) {
            return [self::GROUP_STAFF];
        }

        if (str_starts_with($path, 'drive')) {
            return [self::GROUP_DRIVE];
        }

        if (str_starts_with($path, 'ledger')) {
            return [self::GROUP_LEDGER, self::GROUP_CANDIDATES, self::GROUP_COMPANIES];
        }

        if (str_starts_with($path, 'notifications')) {
            return [self::GROUP_NOTIFICATIONS];
        }

        if (str_starts_with($path, 'settings')) {
            return [self::GROUP_SETTINGS];
        }

        return ['general'];
    }

    /**
     * Determine if a request should be cached.
     */
    public static function shouldCache(Request $request): bool
    {
        // Only GET requests are cached
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return false;
        }

        $path = trim($request->path(), '/');

        // Exclude file downloads / streams / OCR endpoints
        $excludedPatterns = [
            '*/export',
            '*/download',
            'api/ocr/*',
            'ocr/*',
            'up',
            'health',
        ];

        foreach ($excludedPatterns as $pattern) {
            if ($request->is($pattern)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Generate a unique, version-aware cache key for the given request.
     */
    public static function generateKey(Request $request, array $groups): string
    {
        $uri = $request->getRequestUri(); // Includes path and query string
        $user = $request->user();

        // Include user ID if route is user-scoped (like notifications or staff me),
        // or user role for permission-scoped endpoints.
        $userScope = 'public';
        if ($user) {
            if (str_contains($uri, 'notifications') || str_contains($uri, 'staff/me')) {
                $userScope = 'user:' . $user->id;
            } else {
                $userScope = 'role:' . ($user->role ?? 'user') . ':user:' . $user->id;
            }
        }

        // Collect versions of all dependent groups
        $versions = [];
        foreach ($groups as $group) {
            $versions[$group] = self::getGroupVersion($group);
        }
        ksort($versions);
        $versionString = http_build_query($versions);

        return 'api_cache:' . md5("{$uri}|{$userScope}|{$versionString}");
    }
}
