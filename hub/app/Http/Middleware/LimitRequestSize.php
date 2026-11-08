<?php

namespace App\Http\Middleware;

use App\Http\Requests\StoreSummaryRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects oversized bodies before anything parses them.
 */
class LimitRequestSize
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $declared = (int) $request->header('Content-Length', '0');

        if ($declared > StoreSummaryRequest::MAX_BYTES || strlen($request->getContent()) > StoreSummaryRequest::MAX_BYTES) {
            return response()->json(['message' => 'Payload too large.'], 413);
        }

        return $next($request);
    }
}
