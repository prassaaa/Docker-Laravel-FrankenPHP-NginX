<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Carbon\Carbon;

class ApiCache
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $tag
     * @param  int  $ttl
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $tag = 'api', $ttl = 60)
    {
        // Only cache GET requests
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return $next($request);
        }

        // Skip cache if no-cache header is present
        if ($request->header('Cache-Control') === 'no-cache') {
            return $this->handleUncachedRequest($request, $next);
        }

        // Generate cache key
        $cacheKey = $this->getCacheKey($request);
        $tags = $this->getCacheTags($request, $tag);

        // Check ETag
        if ($etag = $request->header('If-None-Match')) {
            if ($this->isEtagValid($cacheKey, $etag)) {
                return $this->notModifiedResponse($etag);
            }
        }

        // Check for cached response
        $cached = Cache::tags($tags)->get($cacheKey);
        
        if ($cached && !$this->isStale($cached)) {
            return $this->buildCachedResponse($cached, $request);
        }

        // Get fresh response
        $response = $next($request);

        // Cache successful responses
        if ($this->shouldCache($response)) {
            $this->cacheResponse($cacheKey, $response, $tags, $ttl);
        }

        return $response;
    }

    /**
     * Handle uncached request
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return mixed
     */
    protected function handleUncachedRequest(Request $request, Closure $next)
    {
        $response = $next($request);
        $response->header('X-Cache', 'BYPASS');
        return $response;
    }

    /**
     * Generate cache key for API request
     *
     * @param  Request  $request
     * @return string
     */
    protected function getCacheKey(Request $request)
    {
        $route = $request->route();
        $params = $route ? $route->parameters() : [];
        $query = $request->query();
        
        // Include API version if present
        $version = $this->getApiVersion($request);
        
        // Sort arrays for consistent keys
        ksort($params);
        ksort($query);
        
        $keyParts = [
            'api',
            $version,
            $request->method(),
            $request->path(),
            md5(serialize($params)),
            md5(serialize($query)),
        ];

        // Add user context for user-specific endpoints
        if ($this->isUserSpecific($request) && $request->user()) {
            $keyParts[] = 'user:' . $request->user()->id;
        }

        return implode(':', array_filter($keyParts));
    }

    /**
     * Get cache tags for request
     *
     * @param  Request  $request
     * @param  string  $baseTag
     * @return array
     */
    protected function getCacheTags(Request $request, $baseTag)
    {
        $tags = ['api', $baseTag];
        
        // Add version tag
        $version = $this->getApiVersion($request);
        if ($version) {
            $tags[] = 'api:' . $version;
        }
        
        // Add route-based tags
        if ($route = $request->route()) {
            $tags[] = 'route:' . $route->getName();
            
            // Add controller tag
            if ($action = $route->getAction('controller')) {
                $controller = Str::before($action, '@');
                $tags[] = 'controller:' . class_basename($controller);
            }
        }
        
        // Add resource tags
        if ($resourceTags = $this->getResourceTags($request)) {
            $tags = array_merge($tags, $resourceTags);
        }
        
        return array_unique($tags);
    }

    /**
     * Cache the API response
     *
     * @param  string  $key
     * @param  \Illuminate\Http\Response  $response
     * @param  array  $tags
     * @param  int  $ttl
     * @return void
     */
    protected function cacheResponse($key, $response, $tags, $ttl)
    {
        $content = $response->getContent();
        $etag = $this->generateEtag($content);
        
        $data = [
            'content' => $content,
            'status' => $response->getStatusCode(),
            'headers' => $this->getCacheableHeaders($response),
            'etag' => $etag,
            'cached_at' => Carbon::now()->timestamp,
            'ttl' => $ttl,
            'expires_at' => Carbon::now()->addSeconds($ttl)->timestamp,
        ];

        Cache::tags($tags)->put($key, $data, $ttl);
        Cache::tags($tags)->put($key . ':etag', $etag, $ttl);
        
        // Add cache headers to response
        $this->addCacheHeaders($response, $etag, $ttl);
    }

    /**
     * Build response from cached data
     *
     * @param  array  $cached
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    protected function buildCachedResponse($cached, Request $request)
    {
        $response = response()->json(
            json_decode($cached['content'], true),
            $cached['status']
        );

        // Add cached headers
        foreach ($cached['headers'] as $name => $value) {
            $response->header($name, $value);
        }

        // Add cache metadata
        $age = Carbon::now()->timestamp - $cached['cached_at'];
        $response->header('X-Cache', 'HIT');
        $response->header('X-Cache-Age', $age);
        $response->header('ETag', $cached['etag']);
        $response->header('Cache-Control', 'public, max-age=' . ($cached['ttl'] - $age));
        
        return $response;
    }

    /**
     * Return 304 Not Modified response
     *
     * @param  string  $etag
     * @return \Illuminate\Http\Response
     */
    protected function notModifiedResponse($etag)
    {
        return response('', 304)
            ->header('ETag', $etag)
            ->header('X-Cache', 'HIT');
    }

    /**
     * Check if ETag is still valid
     *
     * @param  string  $key
     * @param  string  $etag
     * @return bool
     */
    protected function isEtagValid($key, $etag)
    {
        $cachedEtag = Cache::tags(['api'])->get($key . ':etag');
        return $cachedEtag && $cachedEtag === str_replace('"', '', $etag);
    }

    /**
     * Check if cached data is stale
     *
     * @param  array  $cached
     * @return bool
     */
    protected function isStale($cached)
    {
        return Carbon::now()->timestamp > $cached['expires_at'];
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
     * Add cache headers to response
     *
     * @param  \Illuminate\Http\Response  $response
     * @param  string  $etag
     * @param  int  $ttl
     * @return void
     */
    protected function addCacheHeaders($response, $etag, $ttl)
    {
        $response->header('ETag', $etag);
        $response->header('Cache-Control', 'public, max-age=' . $ttl);
        $response->header('X-Cache', 'MISS');
        $response->header('X-RateLimit-Limit', config('api.rate_limit', 60));
        $response->header('X-RateLimit-Remaining', $this->getRateLimitRemaining());
    }

    /**
     * Get cacheable headers
     *
     * @param  \Illuminate\Http\Response  $response
     * @return array
     */
    protected function getCacheableHeaders($response)
    {
        $headers = [];
        $cacheableHeaders = [
            'Content-Type',
            'X-RateLimit-Limit',
            'X-RateLimit-Remaining',
        ];

        foreach ($cacheableHeaders as $header) {
            if ($response->headers->has($header)) {
                $headers[$header] = $response->headers->get($header);
            }
        }

        return $headers;
    }

    /**
     * Determine if response should be cached
     *
     * @param  \Illuminate\Http\Response  $response
     * @return bool
     */
    protected function shouldCache($response)
    {
        // Only cache successful responses
        if (!$response->isSuccessful()) {
            return false;
        }

        // Don't cache if explicitly told not to
        if ($response->headers->get('Cache-Control') === 'no-cache') {
            return false;
        }

        return true;
    }

    /**
     * Get API version from request
     *
     * @param  Request  $request
     * @return string|null
     */
    protected function getApiVersion(Request $request)
    {
        // Check URL path
        if (preg_match('/api\/v(\d+)/', $request->path(), $matches)) {
            return 'v' . $matches[1];
        }
        
        // Check Accept header
        if ($accept = $request->header('Accept')) {
            if (preg_match('/version=(\d+)/', $accept, $matches)) {
                return 'v' . $matches[1];
            }
        }
        
        return config('api.default_version', 'v1');
    }

    /**
     * Check if endpoint is user-specific
     *
     * @param  Request  $request
     * @return bool
     */
    protected function isUserSpecific(Request $request)
    {
        $userSpecificPatterns = [
            'api/*/user',
            'api/*/profile',
            'api/*/me',
            'api/*/dashboard',
        ];

        foreach ($userSpecificPatterns as $pattern) {
            if (Str::is($pattern, $request->path())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get resource tags based on route
     *
     * @param  Request  $request
     * @return array
     */
    protected function getResourceTags(Request $request)
    {
        $tags = [];
        $route = $request->route();
        
        if (!$route) {
            return $tags;
        }

        // Extract resource from route parameters
        foreach ($route->parameters() as $key => $value) {
            if (Str::endsWith($key, '_id') || $key === 'id') {
                $resource = Str::before($key, '_id');
                $tags[] = $resource . ':' . $value;
            }
        }

        return $tags;
    }

    /**
     * Get rate limit remaining
     *
     * @return int
     */
    protected function getRateLimitRemaining()
    {
        // This is a placeholder - integrate with your rate limiting logic
        return 59;
    }
}