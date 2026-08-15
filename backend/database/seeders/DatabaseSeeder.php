<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! User::query()->where('role', 'admin')->exists()) {
            $defaultName = env('ADMIN_DEFAULT_NAME', 'Default Admin');
            $defaultEmail = env('ADMIN_DEFAULT_EMAIL', 'admin@example.com');
            $defaultPassword = env('ADMIN_DEFAULT_PASSWORD');

            // Never fall back to a guessable password; generate and surface a random one instead.
            if (empty($defaultPassword)) {
                $defaultPassword = Str::password(24);
                \Log::warning('DatabaseSeeder: No ADMIN_DEFAULT_PASSWORD set, generated a random admin password. Change it immediately after first login.', [
                    'email' => $defaultEmail,
                    'generated_password' => $defaultPassword,
                ]);
            }

            User::create([
                'name' => $defaultName,
                'email' => $defaultEmail,
                'password' => Hash::make($defaultPassword),
                'role' => 'admin',
                'api_token' => Str::random(40),
                'api_token_is_hashed' => false,
                'active' => true,
            ]);
        }
    }
}
