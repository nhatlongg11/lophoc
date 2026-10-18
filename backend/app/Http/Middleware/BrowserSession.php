<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BrowserSession
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && ! ($request->bearerToken() && app()->environment('testing'))) {
            $token = $request->header('X-CSRF-TOKEN');
            abort_unless(is_string($token) && hash_equals($request->session()->token(), $token), 419, 'Phiên bảo mật đã hết hạn. Vui lòng tải lại trang.');
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
