<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MfaType;
use Illuminate\Database\Seeder;

final class MfaSeeder extends Seeder
{
    public function run(): void
    {
        MfaType::query()->firstOrCreate(
            ['type' => 'email'],
            ['name' => 'Email OTP', 'is_active' => true]
        );

        MfaType::query()->firstOrCreate(
            ['type' => 'totp'],
            ['name' => 'Google Authenticator', 'is_active' => true]
        );
    }
}
