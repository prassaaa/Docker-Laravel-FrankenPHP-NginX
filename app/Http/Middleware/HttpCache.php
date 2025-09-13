<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Carbon\Carbon;

class HttpCache
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  int  $ttl
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $ttl = 3600)
    {
        // Only cache GET requests
        if (!$request->isMethod('GET')) {
            return $next($request);
        }

        // Skip cache for authenticated users (optional)
        if ($request->user() && !$this->shouldCacheForAuthenticatedUsers()) {
            return $next($request);
        }

        // Generate cache key
        $cacheKey = $this->getCacheKey($request);

        // Check for cached response
        if ($cachedResponse = $this->getCachedResponse($cacheKey)) {
            return $this->buildCachedResponse($cachedResponse, $request);
        }

        // Get fresh response
        $response = $next($request);

        // Only cache successful responses
        if ($response->isSuccessful()) {
            $this->cacheResponse($cacheKey, $response, $ttl);
            $this->addCacheHeaders($response, $ttl);
        }

        return $response;
    }

    /**
     * Generate cache key for request
     *
     * @param  Request  $request
     * @return string
     */
    protected function getCacheKey(Request $request)
    {
        $url = $request->url();
        $queryParams = $request->query();
        $user = $request->user();

        // Sort query parameters for consistent cache keys
        ksort($queryParams);

        $key = 'http:' . md5($url . '?' . http_build_query($queryParams));

        // Add user context if needed
        if ($user && $this->shouldCachePerUser()) {
            $key .= ':user:' . $user->id;
        }

        return $key;
    }

    /**
     * Get cached response
     *
     * @param  string  $key
     * @return array|null
     */
    protected function getCachedResponse($key)
    {
        return Cache::tags(['http', 'responses'])->get($key);
    }

    /**
     * Cache the response
     *
     * @param  string  $key
     * @param  \Illuminate\Http\Response  $response
     * @param  int  $ttl
     * @return void
     */
    protected function cacheResponse($key, $response, $ttl)
    {
        $data = [
            'content' => $response->getContent(),
            'status' => $response->getStatusCode(),
            'headers' => $this->getCacheableHeaders($response),
            'etag' => $this->generateEtag($response->getContent()),
            'last_modified' => Carbon::now()->timestamp,
            'cached_at' => Carbon::now()->toIso8601String(),
        ];

        Cache::tags(['http', 'responses'])->put($key, $data, $ttl);
    }

    /**
     * Build response from cached data
     *
     * @param  array  $cached
     * @param  Request  $request
     * @return \Illuminate\Http\Response
     */
    protected function buildCachedResponse($cached, Request $request)
    {
        // Check if client has cached version
        if ($this->isNotModified($request, $cached)) {
            return response('', 304)
                ->header('ETag', $cached['etag'])
                ->header('Last-Modified', gmdate('D, d M Y H:i:s', $cached['last_modified']) . ' GMT')
                ->header('X-Cache', 'HIT');
        }

        // Return cached response
        $response = response($cached['content'], $cached['status']);

        // Add cached headers
        foreach ($cached['headers'] as $name => $value) {
            $response->header($name, $value);
        }

        // Add cache headers
        $response->header('ETag', $cached['etag']);
        $response->header('Last-Modified', gmdate('D, d M Y H:i:s', $cached['last_modified']) . ' GMT');
        $response->header('X-Cache', 'HIT');
        $response->header('X-Cache-Date', $cached['cached_at']);

        return $response;
    }

    /**
     * Check if content is not modified
     *
     * @param  Request  $request
     * @param  array  $cached
     * @return bool
     */
    protected function isNotModified(Request $request, $cached)
    {
        $ifNoneMatch = $request->header('If-None-Match');
        $ifModifiedSince = $request->header('If-Modified-Since');

        if ($ifNoneMatch && $ifNoneMatch === $cached['etag']) {
            return true;
        }

        if ($ifModifiedSince) {
            $clientTime = strtotime($ifModifiedSince);
            if ($clientTime >= $cached['last_modified']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add cache headers to response
     *
     * @param  \Illuminate\Http\Response  $response
     * @param  int  $ttl
     * @return void
     */
    protected function addCacheHeaders($response, $ttl)
    {
        $response->header('Cache-Control', 'public, max-age=' . $ttl);
        $response->header('Expires', gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT');
        $response->header('Vary', 'Accept-Encoding');
        $response->header('X-Cache', 'MISS');
        
        // Add ETag
        $etag = $this->generateEtag($response->getContent());
        $response->header('ETag', $etag);
        
        // Add Last-Modified
        $response->header('Last-Modified', gmdate('D, d M Y H:i:s') . ' GMT');
    }

    /**
     * Generate ETag for content
     *
     * @param  string  $content
     * @return string
     */
    protected function generateEtag($content)
    {
        return '"' . md5($content) . '"';
    }

    /**
     * Get cacheable headers from response
     *
     * @param  \Illuminate\Http\Response  $response
     * @return array
     */
    protected function getCacheableHeaders($response)
    {
        $headers = [];
        $cacheableHeaders = [
            'Content-Type',
            'Content-Language',
            'Content-Encoding',
        ];

        foreach ($cacheableHeaders as $header) {
            if ($response->headers->has($header)) {
                $headers[$header] = $response->headers->get($header);
            }
        }

        return $headers;
    }

    /**
     * Should cache for authenticated users
     *
     * @return bool
     */
    protected function shouldCacheForAuthenticatedUsers()
    {
        return config('cache.http.cache_authenticated', false);
    }

    /**
     * Should cache per user
     *
     * @return bool
     */
    protected function shouldCachePerUser()
    {
        return config('cache.http.cache_per_user', false);
    }
}