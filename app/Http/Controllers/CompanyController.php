<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'companies.view');
        return response()->json([
            'companies' => Company::query()
                ->latest()
                ->get()
                ->map(fn (Company $company): array => $this->present($company))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'companies.create');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'contactPerson' => ['required', 'string', 'max:255'],
            'contactEmail' => ['nullable', 'email', 'max:255'],
            'contactPhone' => ['nullable', 'string', 'max:50'],
            'country' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive,pending_verification'],
        ]);

        $company = Company::create([
            'name' => $data['name'],
            'industry' => $data['industry'] ?? null,
            'contact_person' => $data['contactPerson'],
            'contact_email' => $data['contactEmail'] ?? null,
            'contact_phone' => $data['contactPhone'] ?? null,
            'country' => $data['country'],
            'city' => $data['city'],
            'status' => $data['status'] ?? 'active',
        ]);

        return response()->json([
            'company' => $this->present($company),
        ], 201);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $this->requirePermission($request, 'companies.update');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'contactPerson' => ['required', 'string', 'max:255'],
            'contactEmail' => ['nullable', 'email', 'max:255'],
            'contactPhone' => ['nullable', 'string', 'max:50'],
            'country' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'status' => ['required', 'in:active,inactive,pending_verification'],
        ]);

        $company->update([
            'name' => $data['name'],
            'industry' => $data['industry'] ?? null,
            'contact_person' => $data['contactPerson'],
            'contact_email' => $data['contactEmail'] ?? null,
            'contact_phone' => $data['contactPhone'] ?? null,
            'country' => $data['country'],
            'city' => $data['city'],
            'status' => $data['status'],
        ]);

        return response()->json([
            'company' => $this->present($company->fresh()),
        ]);
    }

    public function destroy(Request $request, Company $company): JsonResponse
    {
        $this->requirePermission($request, 'companies.delete');
        $company->delete();

        return response()->json([
            'message' => 'Company deleted successfully.',
        ]);
    }

    private function present(Company $company): array
    {
        return [
            'id' => (string) $company->id,
            'name' => $company->name,
            'code' => 'CMP-' . str_pad((string) $company->id, 2, '0', STR_PAD_LEFT),
            'industry' => $company->industry ?? '',
            'contactPerson' => $company->contact_person,
            'contactEmail' => $company->contact_email ?? '',
            'contactPhone' => $company->contact_phone ?? '',
            'country' => $company->country,
            'city' => $company->city,
            'status' => $company->status,
            'totalPlacedCandidates' => 0,
            'activeCandidatesCount' => 0,
            'joinedDate' => $company->created_at?->toDateString(),
        ];
    }
}