<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use LaravelAIEngine\Repositories\UserRepository;

class AuthTokenService
{
    public function __construct(protected UserRepository $users)
    {
    }

    public function issueDemoToken(string $identifier): array
    {
        $user = $this->users->findByIdentifier($identifier)
            ?? $this->users->firstOrCreateDemo($identifier);

        return [
            'token' => $this->createToken($user),
            'user' => $this->serializeUser($user),
        ];
    }

    public function serializeUser(mixed $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name ?? 'Demo User',
            'email' => $user->email ?? null,
        ];
    }

    protected function createToken(Model $user): string
    {
        if (class_exists(\Tymon\JWTAuth\Facades\JWTAuth::class)) {
            return (string) \Tymon\JWTAuth\Facades\JWTAuth::fromUser($user);
        }

        if (method_exists($user, 'createToken')) {
            return (string) $user->createToken('ai-engine')->plainTextToken;
        }

        return base64_encode($user->id . ':' . now()->timestamp . ':' . Hash::make((string) $user->id));
    }
}
