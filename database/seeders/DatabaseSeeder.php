<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(TemplateSeeder::class);

        $email = env('SAFARA_ADMIN_EMAIL');
        $password = env('SAFARA_ADMIN_PASSWORD');
        if ($email && $password && ! User::query()->where('email', $email)->exists()) {
            User::query()->create([
                'name' => env('SAFARA_ADMIN_NAME', 'Operator'),
                'email' => $email,
                'password' => $password,
            ]);
            $this->command?->info("Desk login created for $email.");
        }
    }
}
