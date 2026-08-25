<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use Closure;
use Illuminate\Http\Request;

final class EnsureEmailVerified
{
    /**
     * Ensure the user's email is verified before allowing access.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'No autenticado. Inicia sesión para continuar.',
                'error_code' => ApiErrorCode::UNAUTHENTICATED->value,
            ], 401);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Tu correo electrónico no está verificado. Verifícalo para continuar.',
                'error_code' => ApiErrorCode::EMAIL_NOT_VERIFIED->value,
            ], 403);
        }

        return $next($request);
    }
}
