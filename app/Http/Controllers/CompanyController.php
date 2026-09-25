<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Company;
use App\Models\CompanyLog;
use App\Support\Notifier;
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

    public function show(Request $request, Company $company): JsonResponse
    {
        $this->requirePermission($request, 'companies.view');

        return response()->json([
            'company' => $this->present($company),
        ]);
    }

    public function logs(Request $request, Company $company): JsonResponse
    {
        $this->requirePermission($request, 'companies.view');
        $perPage = min(100, max(1, (int) $request->input('per_page', 30)));
        $page = $company->logs()->paginate($perPage);

        return response()->json([
            'logs' => collect($page->items())->map(fn (CompanyLog $log): array => [
                'id' => (string) $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'meta' => $log->meta ?? (object) [],
                'userName' => $log->user_name ?? 'System',
                'createdAt' => $log->created_at?->toIso8601String(),
            ])->values(),
            'pagination' => [
                'page' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                'lastPage' => $page->lastPage(),
            ],
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
            'permitIssued' => ['nullable', 'integer', 'min:0'],
            'rejected' => ['nullable', 'integer', 'min:0'],
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
            'permit_issued' => $data['permitIssued'] ?? 0,
            'rejected' => $data['rejected'] ?? 0,
        ]);

        CompanyLog::record($company->id, 'created', "Company \"{$company->name}\" registered", [], $request);
        Notifier::staff(
            'COMPANY_ADDED',
            'New client company',
            "{$company->name} ({$company->city}, {$company->country}) was registered.",
            ['companyId' => $company->id],
            "/dashboard/companies/{$company->id}",
            $request
        );

        return response()->json([
            'company' => $this->present($company),
        ], 201);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $this->requirePermission($request, 'companies.update');
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'contactPerson' => ['sometimes', 'required', 'string', 'max:255'],
            'contactEmail' => ['nullable', 'email', 'max:255'],
            'contactPhone' => ['nullable', 'string', 'max:50'],
            'country' => ['sometimes', 'required', 'string', 'max:100'],
            'city' => ['sometimes', 'required', 'string', 'max:100'],
            'status' => ['sometimes', 'required', 'in:active,inactive,pending_verification'],
            'permitIssued' => ['nullable', 'integer', 'min:0'],
            'rejected' => ['nullable', 'integer', 'min:0'],
            'permitPhases' => ['nullable'],
            'permit_phases' => ['nullable'],
        ]);

        $updates = [];
        if (array_key_exists('name', $data)) $updates['name'] = $data['name'];
        if (array_key_exists('industry', $data)) $updates['industry'] = $data['industry'];
        if (array_key_exists('contactPerson', $data)) $updates['contact_person'] = $data['contactPerson'];
        if (array_key_exists('contactEmail', $data)) $updates['contact_email'] = $data['contactEmail'];
        if (array_key_exists('contactPhone', $data)) $updates['contact_phone'] = $data['contactPhone'];
        if (array_key_exists('country', $data)) $updates['country'] = $data['country'];
        if (array_key_exists('city', $data)) $updates['city'] = $data['city'];
        if (array_key_exists('status', $data)) $updates['status'] = $data['status'];

        $hasPhases = $request->has('permitPhases') || $request->has('permit_phases');
        if ($hasPhases) {
            $rawPhases = $request->input('permitPhases', $request->input('permit_phases', []));
            $phases = is_array($rawPhases) ? array_values($rawPhases) : [];
            $cleanPhases = [];
            $totalAccepted = 0;
            $totalRejected = 0;
            foreach ($phases as $p) {
                if (!is_array($p)) continue;
                $acc = max(0, (int) ($p['accepted'] ?? 0));
                $rej = max(0, (int) ($p['rejected'] ?? 0));
                $cleanPhases[] = [
                    'id' => (string) ($p['id'] ?? ('phase-' . uniqid())),
                    'date' => (string) ($p['date'] ?? now()->toDateString()),
                    'accepted' => $acc,
                    'rejected' => $rej,
                    'notes' => !empty($p['notes']) ? (string) $p['notes'] : null,
                ];
                $totalAccepted += $acc;
                $totalRejected += $rej;
            }
            // Enforce that processed permits cannot exceed placed candidates pool
            $placedCount = \App\Models\Candidate::where('current_company_id', $company->id)
                ->where('status', 'placed')
                ->count();

            if ($placedCount > 0 && ($totalAccepted + $totalRejected) > $placedCount) {
                return response()->json([
                    'message' => "Total accepted and rejected permits (" . ($totalAccepted + $totalRejected) . ") cannot exceed the {$placedCount} placed candidates for this company.",
                ], 422);
            }

            $updates['permit_phases'] = $cleanPhases;
            $updates['permit_issued'] = $totalAccepted;
            $updates['rejected'] = $totalRejected;
        } else {
            if (array_key_exists('permitIssued', $data)) $updates['permit_issued'] = (int) ($data['permitIssued'] ?? 0);
            if (array_key_exists('rejected', $data)) $updates['rejected'] = (int) ($data['rejected'] ?? 0);
        }

        $before = $company->only(array_keys($updates));
        $company->update($updates);
        $this->logUpdate($company, $before, $updates, $request);

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

    private const FIELD_LABELS = [
        'name' => 'Name',
        'industry' => 'Industry',
        'contact_person' => 'Contact person',
        'contact_email' => 'Email',
        'contact_phone' => 'Phone',
        'country' => 'Country',
        'city' => 'City',
        'status' => 'Status',
    ];

    /** Logs detail edits and permit phase changes as separate entries. */
    private function logUpdate(Company $company, array $before, array $updates, Request $request): void
    {
        $changes = [];
        foreach (self::FIELD_LABELS as $field => $label) {
            if (!array_key_exists($field, $updates)) continue;
            $old = $before[$field] ?? null;
            $new = $updates[$field];
            if ((string) $old === (string) $new) continue;
            $changes[] = ['field' => $label, 'from' => $old, 'to' => $new];
        }
        if ($changes) {
            CompanyLog::record(
                $company->id,
                'updated',
                'Updated ' . implode(', ', array_map(fn ($c) => strtolower($c['field']), $changes)),
                ['changes' => $changes],
                $request
            );
        }

        if (array_key_exists('permit_phases', $updates)) {
            $oldPhases = $before['permit_phases'] ?? [];
            if (!is_array($oldPhases)) $oldPhases = json_decode((string) $oldPhases, true) ?? [];
            $newPhases = $updates['permit_phases'];
            if (json_encode(array_values($oldPhases)) === json_encode(array_values($newPhases))) return;

            $sum = fn (array $phases, string $key) => array_sum(array_map(fn ($p) => (int) ($p[$key] ?? 0), $phases));
            CompanyLog::record(
                $company->id,
                'permits_updated',
                sprintf(
                    'Permit phases updated: %d accepted, %d rejected across %d phase(s)',
                    $updates['permit_issued'],
                    $updates['rejected'],
                    count($newPhases)
                ),
                [
                    'phases' => count($newPhases),
                    'previousPhases' => count($oldPhases),
                    'accepted' => $updates['permit_issued'],
                    'rejected' => $updates['rejected'],
                    'previousAccepted' => $sum($oldPhases, 'accepted'),
                    'previousRejected' => $sum($oldPhases, 'rejected'),
                ],
                $request
            );
            Notifier::staff(
                'PERMITS_UPDATED',
                "Permits updated for {$company->name}",
                sprintf('%d accepted, %d rejected across %d phase(s).', $updates['permit_issued'], $updates['rejected'], count($newPhases)),
                ['companyId' => $company->id],
                "/dashboard/companies/{$company->id}",
                $request
            );
        }
    }

    private function present(Company $company): array
    {
        $phases = $company->permit_phases ?? [];
        if (!is_array($phases)) {
            $phases = json_decode($phases, true) ?? [];
        }

        $permitIssued = (int) ($company->permit_issued ?? 0);
        $rejected = (int) ($company->rejected ?? 0);
        if (!empty($phases)) {
            $permitIssued = array_sum(array_column($phases, 'accepted'));
            $rejected = array_sum(array_column($phases, 'rejected'));
        }

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
            'permitIssued' => $permitIssued,
            'rejected' => $rejected,
            'permitPhases' => $phases,
            'sharedCandidatesCount' => (int) ($company->shared_candidates_count ?? 0),
            'totalPlacedCandidates' => Candidate::where('current_company_id', $company->id)->where('status', 'placed')->count(),
            'activeCandidatesCount' => 0,
            'joinedDate' => $company->created_at?->toDateString(),
        ];
    }
}