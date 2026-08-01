<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->role) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado. Se requiere iniciar sesión.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (! in_array($user->role->name, $roles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Acceso Denegado. Tu rol ('.$user->role->name.') no tiene privilegios para esta acción.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
