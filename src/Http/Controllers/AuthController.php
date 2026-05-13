<?php

namespace LaravelAIEngine\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Services\Auth\AuthTokenService;

class AuthController extends Controller
{
    /**
     * Generate authentication token for demo purposes
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function generateToken(Request $request, AuthTokenService $tokens): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|string|max:255',
        ]);

        try {
            $issued = $tokens->issueDemoToken((string) $request->input('user_id'));

            return response()->json([
                'success' => true,
                'token' => $issued['token'],
                'user' => $issued['user'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to generate token: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validate token (optional endpoint)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function validateToken(Request $request, AuthTokenService $tokens): JsonResponse
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
            'user' => $tokens->serializeUser($user),
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
