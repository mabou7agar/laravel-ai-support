<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Default authentication for the package API routes.
 *
 * Behaves like Laravel's `auth:<guard>` but always answers an unauthenticated request with
 * a JSON 401. The framework middleware redirects non-JSON requests (e.g. an EventSource
 * stream) to a `login` route, which API-only hosts do not define, turning a 401 into a 500.
 */
class AuthenticateApiRequest
{
    public function __construct(
        protected AuthFactory $auth
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        foreach ($guards === [] ? [null] : $guards as $guard) {
            if ($this->auth->guard($guard)->check()) {
                $this->auth->shouldUse($guard);

                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated.',
            'data' => null,
            'error' => ['message' => 'Unauthenticated.'],
            'meta' => ['schema' => 'ai-engine.v1'],
        ], 401);
    }
}
