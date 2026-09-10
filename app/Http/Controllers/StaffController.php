<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StaffController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'in:recruiter,compliance_officer,admin,super_admin'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $temporaryPassword = Str::password(16);
        $user = User::create([
            ...$data,
            'password' => $temporaryPassword,
        ]);

        return response()->json([
            'user' => $user,
            'temporaryPassword' => $temporaryPassword,
        ], 201);
    }
}