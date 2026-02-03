<?php

namespace LaravelAIEngine\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    /**
     * Generate authentication token for demo purposes
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function generateToken(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|string|max:255',
        ]);

        $userId = $request->input('user_id');

        try {
            // Try to find user by ID
            $user = User::find($userId);

            // If user doesn't exist, try to find by email or create a demo user
            if (!$user) {
                // Check if it's an email
                if (filter_var($userId, FILTER_VALIDATE_EMAIL)) {
                    $user = User::where('email', $userId)->first();
                }

                // If still no user, create a demo/guest user
                if (!$user) {
                    $user = $this->createDemoUser($userId);
                }
            }

            // Check if Sanctum is available
            if (method_exists($user, 'createToken')) {
                // Use Sanctum to create token
                $token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($user);
            } else {
                // Fallback: Generate a simple token
                $token = base64_encode($user->id . ':' . now()->timestamp . ':' . Hash::make($user->id));
            }

            return response()->json([
                'success' => true,
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name ?? 'Demo User',
                    'email' => $user->email ?? null,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to generate token: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a demo user for testing
     *
     * @param string $userId
     * @return User
     */
    protected function createDemoUser(string $userId): User
    {
        // Check if User model has required fields
        $email = filter_var($userId, FILTER_VALIDATE_EMAIL)
            ? $userId
            : "demo_{$userId}@example.com";

        try {
            return User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => "Demo User {$userId}",
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );
        } catch (\Exception $e) {
            // If creation fails, try to find any user
            $user = User::first();

            if (!$user) {
                throw new \Exception('No users available and cannot create demo user');
            }

            return $user;
        }
    }

    /**
     * Validate token (optional endpoint)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function validateToken(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid token',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name ?? 'Demo User',
                'email' => $user->email ?? null,
            ],
        ]);
    }

    /**
     * Logout and revoke token
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user && method_exists($user, 'currentAccessToken')) {
            // Revoke current token if using Sanctum
            $user->currentAccessToken()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }
}
