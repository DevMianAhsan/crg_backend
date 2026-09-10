<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'recruiter',
            'status' => 'active',
        ]);

        return response()->json($this->tokenResponse($user), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['This account is inactive.'],
            ]);
        }

        return response()->json($this->tokenResponse($user));
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refreshToken' => ['required', 'string'],
        ]);

        $token = PersonalAccessToken::findToken($data['refreshToken']);

        if (!$token || !str_starts_with($token->name, 'refresh-')) {
            return response()->json(['message' => 'Invalid refresh token.'], 401);
        }

        $user = $token->tokenable;
        $token->delete();

        return response()->json($this->tokenResponse($user));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    private function tokenResponse(User $user): array
    {
        $user->tokens()->where('name', 'like', 'refresh-%')->delete();

        return [
            'user' => $user,
            'accessToken' => $user->createToken('access-' . $user->id)->plainTextToken,
            'refreshToken' => $user->createToken('refresh-' . $user->id)->plainTextToken,
        ];
    }
}