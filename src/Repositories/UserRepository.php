<?php

declare(strict_types=1);

namespace LaravelAIEngine\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class UserRepository
{
    public function findByIdentifier(string $identifier): ?Model
    {
        $model = $this->modelClass();

        $user = $model::query()->find($identifier);
        if ($user instanceof Model) {
            return $user;
        }

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $user = $model::query()->where('email', $identifier)->first();

            return $user instanceof Model ? $user : null;
        }

        return null;
    }

    public function firstOrCreateDemo(string $identifier): Model
    {
        $model = $this->modelClass();
        $email = filter_var($identifier, FILTER_VALIDATE_EMAIL)
            ? $identifier
            : "demo_{$identifier}@example.com";

        try {
            return $model::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => "Demo User {$identifier}",
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );
        } catch (\Throwable $e) {
            $user = $model::query()->first();
            if ($user instanceof Model) {
                return $user;
            }

            throw new \RuntimeException('No users available and cannot create demo user', 0, $e);
        }
    }

    protected function modelClass(): string
    {
        $model = (string) config('auth.providers.users.model', config('ai-engine.user_model', 'App\\Models\\User'));

        if (!class_exists($model)) {
            throw new \RuntimeException("Configured user model [{$model}] does not exist.");
        }

        return $model;
    }
}
