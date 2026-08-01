<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

final class RegisterService
{
    /**
     * Registra un nuevo usuario en el sistema asociándole por defecto el rol de Invitado.
     *
     * @param array{name: string, email: string, password: string} $data
     * @return array<string, mixed>
     */
    public function register(array $data): array
    {
        Log::debug('RegisterService: Iniciando registro de nuevo usuario', [
            'email' => $data['email'],
        ]);

        /** @var User $user */
        $user = DB::transaction(function () use ($data): User {
            $data['password'] = Hash::make($data['password']);

            /** @var Role|null $invitadoRole */
            $invitadoRole = Role::query()->where('name', 'Invitado')->first();

            if (! $invitadoRole) {
                Log::error('RegisterService: Error, el rol de Invitado no existe en la BD', [
                    'email' => $data['email'],
                ]);
                abort(500, 'El rol de Invitado no existe en la base de datos.');
            }

            Log::debug('RegisterService: Asignando rol de Invitado', [
                'email' => $data['email'],
                'role_id' => $invitadoRole->id,
            ]);

            $data['role_id'] = $invitadoRole->id;

            return User::query()->create($data);
        });

        $user->sendEmailVerificationNotification();
        $token = $user->createToken('auth-token')->plainTextToken;

        Log::debug('RegisterService: Registro de nuevo usuario completado exitosamente', [
            'email' => $user->email,
            'user_id' => $user->id,
        ]);

        return [
            'success' => true,
            'message' => 'Usuario registrado exitosamente. Por favor verifica tu correo electrónico.',
            'user' => $user->load('role'),
            'token' => $token,
            'code' => 201,
        ];
    }
}
