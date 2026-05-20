<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthJwt
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = (string) $request->header('Authorization', '');

        if ($authHeader === '' || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['message' => 'Token tidak ditemukan.'], 401);
        }

        try {
            $user = JWTAuth::parseToken()->authenticate();
        } catch (TokenExpiredException) {
            return response()->json(['message' => 'Token sudah kadaluarsa.'], 401);
        } catch (TokenInvalidException) {
            return response()->json(['message' => 'Token tidak valid.'], 401);
        } catch (\Throwable) {
            return response()->json(['message' => 'Token tidak valid.'], 401);
        }

        if (!$user) {
            return response()->json(['message' => 'Token tidak valid.'], 401);
        }

        auth()->setUser($user);

        return $next($request);
    }
}
