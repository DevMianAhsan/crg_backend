<?php

namespace App\Http\Middleware;

use App\Services\CacheService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CacheResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if this request is eligible for caching
        if (!CacheService::shouldCache($request)) {
            return $next($request);
        }

        $groups = CacheService::getRouteGroups($request);
        $cacheKey = CacheService::generateKey($request, $groups);

        // Check if response is cached
        $cached = Cache::get($cacheKey);
        if ($cached !== null && is_array($cached)) {
            $response = response()->json(
                $cached['data'],
                $cached['status'] ?? 200,
                $cached['headers'] ?? []
            );
            $response->headers->set('X-Cache', 'HIT');
            $response->headers->set('X-Cache-Key', $cacheKey);
            return $response;
        }

        /** @var Response $response */
        $response = $next($request);

        // Only cache successful JSON responses
        if ($response->isSuccessful() && $response->getStatusCode() === 200) {
            $contentType = $response->headers->get('Content-Type', '');
            
            // Check if JSON response
            if ($response instanceof JsonResponse || str_contains($contentType, 'application/json')) {
                $content = $response->getContent();
                $decoded = json_decode($content, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $cachePayload = [
                        'data' => $decoded,
                        'status' => $response->getStatusCode(),
                        'headers' => [
                            'Content-Type' => 'application/json',
                        ],
                    ];

                    Cache::put($cacheKey, $cachePayload, CacheService::DEFAULT_TTL);
                    $response->headers->set('X-Cache', 'MISS');
                    $response->headers->set('X-Cache-Key', $cacheKey);
                }
            }
        }

        return $response;
    }
}
