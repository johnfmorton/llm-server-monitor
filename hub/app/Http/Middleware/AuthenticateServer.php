<?php

namespace App\Http\Middleware;

use App\Models\Server;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a collector by its per-server bearer token.
 *
 * The matched server is placed on the request as the "server" attribute.
 */
class AuthenticateServer
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $server = $token !== null && $token !== '' ? Server::findByToken($token) : null;

        if ($server === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->attributes->set('server', $server);

        return $next($request);
    }
}
