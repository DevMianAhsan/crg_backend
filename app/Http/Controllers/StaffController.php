<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        return response()->json([
            'users' => User::query()->latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'in:recruiter,compliance_officer,admin,super_admin'],
            'status' => ['required', 'in:active,inactive'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create($data);

        return response()->json([
            'user' => $user,
        ], 201);
    }

    public function approve(Request $request, User $user): JsonResponse
    {
        $this->ensureAdmin($request);

        $user->update(['status' => 'active']);

        return response()->json(['user' => $user->fresh()]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'role' => ['required', 'in:recruiter,compliance_officer,admin,super_admin,super-admin'],
            'status' => ['required', 'in:active,inactive,pending'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return response()->json(['user' => $user->fresh()]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->ensureAdmin($request);

        abort_if($request->user()->is($user), 422, 'You cannot delete your own account.');

        $user->delete();

        return response()->json(['message' => 'Staff member deleted successfully.']);
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin', 'super-admin', 'superAdmin', 'staff'], true), 403);
    }
}