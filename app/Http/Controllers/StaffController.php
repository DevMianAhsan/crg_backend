<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StaffController extends Controller
{
    private const PERMISSIONS = [
        'dashboard.view',
        'staff.view',
        'staff.create',
        'staff.update',
        'staff.delete',
        'candidates.view', 'candidates.create', 'candidates.update', 'candidates.delete', 'candidates.shift',
        'documents.view', 'documents.create', 'documents.update', 'documents.delete', 'documents.verify',
        'companies.view', 'companies.create', 'companies.update', 'companies.delete',
        'ledger.view', 'ledger.create', 'ledger.update', 'ledger.delete',
        'drive.view', 'drive.create', 'drive.update', 'drive.delete',
        'document-types.view', 'document-types.create', 'document-types.update',
    ];

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'role' => $user->role,
                'isSuperAdmin' => $this->isSuperAdmin($user),
                'permissions' => $user->permissions ?? [],
            ],
        ]);
    }

    public function permissions(Request $request): JsonResponse
    {
        $this->ensurePermission($request, 'staff.update');

        return response()->json([
            'success' => true,
            'data' => [
                $this->catalogueGroup('Dashboard', '/dashboard', ['dashboard.view' => 'View dashboard']),
                $this->catalogueGroup('Candidates', '/dashboard/candidates', [
                    'candidates.view' => 'View candidates', 'candidates.create' => 'Add candidates',
                    'candidates.update' => 'Edit candidates', 'candidates.delete' => 'Delete candidates',
                    'candidates.shift' => 'Move candidates',
                ]),
                $this->catalogueGroup('Documents', '/dashboard/documents', [
                    'documents.view' => 'View documents', 'documents.create' => 'Upload documents',
                    'documents.update' => 'Edit documents', 'documents.delete' => 'Delete documents',
                    'documents.verify' => 'Verify documents',
                ]),
                $this->catalogueGroup('Companies', '/dashboard/companies', [
                    'companies.view' => 'View companies', 'companies.create' => 'Add companies',
                    'companies.update' => 'Edit companies', 'companies.delete' => 'Delete companies',
                ]),
                $this->catalogueGroup('Ledger', '/dashboard/ledger', [
                    'ledger.view' => 'View ledger', 'ledger.create' => 'Record payments',
                    'ledger.update' => 'Edit ledger entries', 'ledger.delete' => 'Delete ledger entries',
                ]),
                $this->catalogueGroup('CRG Drive', '/dashboard/drive', [
                    'drive.view' => 'View drive', 'drive.create' => 'Upload documents',
                    'drive.update' => 'Edit documents', 'drive.delete' => 'Delete documents',
                ]),
                $this->catalogueGroup('Document Types', '/dashboard/settings/document-types', [
                    'document-types.view' => 'View document types', 'document-types.create' => 'Add document types',
                    'document-types.update' => 'Edit document types',
                ]),
                $this->catalogueGroup('Staff', '/dashboard/staff', [
                    'staff.view' => 'View staff page', 'staff.create' => 'Add staff members',
                    'staff.update' => 'Edit staff members', 'staff.delete' => 'Delete staff members',
                ]),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensurePermission($request, 'staff.view');

        return response()->json([
            'users' => User::query()->latest()->get(),
        ]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->ensurePermission($request, 'staff.update');

        return response()->json(['success' => true, 'data' => $user]);
    }

    public function updatePermissions(Request $request, User $user): JsonResponse
    {
        $this->ensurePermission($request, 'staff.update');

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'in:' . implode(',', self::PERMISSIONS)],
        ]);

        $user->update(['permissions' => array_values(array_unique($data['permissions']))]);

        return response()->json([
            'success' => true,
            'message' => 'User privileges saved successfully.',
            'user' => $user->fresh(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensurePermission($request, 'staff.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'in:recruiter,compliance_officer,admin,super_admin'],
            'status' => ['required', 'in:active,inactive'],
            'password' => ['required', 'string', 'min:8'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'in:' . implode(',', self::PERMISSIONS)],
        ]);

        $user = User::create($data);

        return response()->json([
            'user' => $user,
        ], 201);
    }

    public function approve(Request $request, User $user): JsonResponse
    {
        $this->ensurePermission($request, 'staff.update');

        $user->update(['status' => 'active']);

        return response()->json(['user' => $user->fresh()]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->ensurePermission($request, 'staff.update');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'role' => ['required', 'in:recruiter,compliance_officer,admin,super_admin,super-admin'],
            'status' => ['required', 'in:active,inactive,pending'],
            'password' => ['nullable', 'string', 'min:8'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'in:' . implode(',', self::PERMISSIONS)],
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
        $this->ensurePermission($request, 'staff.delete');

        abort_if($request->user()->is($user), 422, 'You cannot delete your own account.');

        $user->delete();

        return response()->json(['message' => 'Staff member deleted successfully.']);
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless($this->isSuperAdmin($request->user()), 403);
    }

    private function catalogueGroup(string $page, string $route, array $permissions): array
    {
        return [
            'page' => $page,
            'route' => $route,
            'permissions' => collect($permissions)
                ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])
                ->values()
                ->all(),
        ];
    }

    private function ensurePermission(Request $request, string $permission): void
    {
        $user = $request->user();
        abort_unless($this->isSuperAdmin($user) || in_array($permission, $user->permissions ?? [], true), 403);
    }

    protected function isSuperAdmin(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'super-admin', 'superAdmin'], true);
    }
}