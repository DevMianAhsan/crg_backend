<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Services\DocumentCompressionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LedgerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'ledger.view');

        $query = LedgerEntry::query()
            ->with(['candidate:id,first_name,last_name,code', 'company:id,name'])
            ->where(function ($q): void {
                $q->whereNull('candidate_id')
                  ->orWhereHas('candidate');
            })
            ->latest('date')
            ->latest('id');

        if ($request->filled('candidate_id')) {
            $query->where('candidate_id', $request->input('candidate_id'));
        }

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->input('company_id'));
        }

        if ($request->filled('type') && $request->input('type') !== 'all') {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('transaction_no', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%")
                    ->orWhere('payment_method', 'ilike', "%{$search}%")
                    ->orWhereHas('candidate', function ($cq) use ($search) {
                        $cq->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%")
                            ->orWhere('code', 'ilike', "%{$search}%");
                    })
                    ->orWhereHas('company', function ($compQ) use ($search) {
                        $compQ->where('name', 'ilike', "%{$search}%");
                    });
            });
        }

        $entries = $query->get();

        return response()->json([
            'ledger' => $entries->map(fn (LedgerEntry $e): array => $this->present($e))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'ledger.create');

        $data = $request->validate([
            'candidateId' => ['nullable', 'exists:candidates,id'],
            'candidate_id' => ['nullable', 'exists:candidates,id'],
            'companyId' => ['nullable', 'exists:companies,id'],
            'company_id' => ['nullable', 'exists:companies,id'],
            'type' => ['required', 'string', 'in:placement_fee,processing_fee,deposit,refund,payment,cancellation_fee'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'max:10'],
            'status' => ['nullable', 'string', 'in:paid,pending,overdue,cancelled'],
            'description' => ['nullable', 'string'],
            'paymentMethod' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'date' => ['nullable', 'date'],
            'chequeNumber' => ['nullable', 'string', 'max:100'],
            'cheque_number' => ['nullable', 'string', 'max:100'],
            'chequeDate' => ['nullable', 'date'],
            'cheque_date' => ['nullable', 'date'],
            'slip' => ['nullable'],
            'slip_file' => ['nullable'],
        ]);

        $candidateId = $data['candidateId'] ?? $data['candidate_id'] ?? null;
        $companyId = $data['companyId'] ?? $data['company_id'] ?? null;
        $paymentMethod = $data['paymentMethod'] ?? $data['payment_method'] ?? 'Bank Transfer';
        $chequeNumber = $data['chequeNumber'] ?? $data['cheque_number'] ?? null;
        $chequeDate = $data['chequeDate'] ?? $data['cheque_date'] ?? null;

        // Store slip inside candidate's dedicated folder: candidates/{candidateId}/slips
        $slipFolder = $candidateId ? "candidates/{$candidateId}/slips" : 'ledger/slips';

        // Process and compress payment slip image (matching standard document compression: 1600px max, 80% quality)
        $slipPath = null;
        $compressionOptions = [
            'max_dimension' => 1600,
            'jpeg_quality' => 80,
            'webp_quality' => 80,
        ];

        if ($request->hasFile('slip')) {
            $comp = app(DocumentCompressionService::class)->storeAndCompress(
                $request->file('slip'),
                $slipFolder,
                'public',
                $compressionOptions
            );
            $slipPath = $comp['path'];
        } elseif ($request->hasFile('slip_file')) {
            $comp = app(DocumentCompressionService::class)->storeAndCompress(
                $request->file('slip_file'),
                $slipFolder,
                'public',
                $compressionOptions
            );
            $slipPath = $comp['path'];
        } elseif ($request->filled('slip') && is_string($request->input('slip')) && str_starts_with($request->input('slip'), 'data:image')) {
            $slipPath = app(DocumentCompressionService::class)->storeBase64AndCompress(
                $request->input('slip'),
                $slipFolder,
                'public',
                'slip_',
                $compressionOptions
            );
        }

        $entry = LedgerEntry::create([
            'date' => $data['date'] ?? now()->toDateString(),
            'candidate_id' => $candidateId,
            'company_id' => $companyId,
            'type' => $data['type'],
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'PKR',
            'status' => $data['status'] ?? 'paid',
            'description' => $data['description'] ?? null,
            'payment_method' => $paymentMethod,
            'slip_path' => $slipPath,
            'cheque_number' => $chequeNumber,
            'cheque_date' => $chequeDate,
            'created_by' => $request->user()?->id,
        ]);

        $entry->load(['candidate:id,first_name,last_name,code', 'company:id,name']);

        $party = $entry->candidate
            ? trim("{$entry->candidate->first_name} {$entry->candidate->last_name}")
            : ($entry->company?->name ?? null);
        \App\Support\Notifier::staff(
            'PAYMENT_RECORDED',
            'Payment recorded',
            sprintf(
                '%s %s %s recorded%s.',
                $entry->currency,
                number_format((float) $entry->amount, 0),
                str_replace('_', ' ', strtolower((string) $entry->type)),
                $party ? " for {$party}" : ''
            ),
            ['entryId' => $entry->id, 'amount' => (string) $entry->amount, 'currency' => $entry->currency],
            $entry->candidate_id ? "/dashboard/candidates/{$entry->candidate_id}" : '/dashboard/ledger',
            $request
        );

        return response()->json([
            'entry' => $this->present($entry),
        ], 201);
    }

    public function update(Request $request, LedgerEntry $ledgerEntry): JsonResponse
    {
        $this->requirePermission($request, 'ledger.update');

        $data = $request->validate([
            'candidateId' => ['nullable', 'exists:candidates,id'],
            'candidate_id' => ['nullable', 'exists:candidates,id'],
            'companyId' => ['nullable', 'exists:companies,id'],
            'company_id' => ['nullable', 'exists:companies,id'],
            'type' => ['sometimes', 'required', 'string', 'in:placement_fee,processing_fee,deposit,refund,payment,cancellation_fee'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'max:10'],
            'status' => ['sometimes', 'required', 'string', 'in:paid,pending,overdue,cancelled'],
            'description' => ['nullable', 'string'],
            'paymentMethod' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'date' => ['nullable', 'date'],
            'chequeNumber' => ['nullable', 'string', 'max:100'],
            'cheque_number' => ['nullable', 'string', 'max:100'],
            'chequeDate' => ['nullable', 'date'],
            'cheque_date' => ['nullable', 'date'],
            'slip' => ['nullable'],
            'slip_file' => ['nullable'],
            'removeSlip' => ['nullable', 'boolean'],
            'delete_slip' => ['nullable', 'boolean'],
        ]);

        $updates = [];
        if (array_key_exists('candidateId', $data) || array_key_exists('candidate_id', $data)) {
            $updates['candidate_id'] = $data['candidateId'] ?? $data['candidate_id'] ?? null;
        }
        if (array_key_exists('companyId', $data) || array_key_exists('company_id', $data)) {
            $updates['company_id'] = $data['companyId'] ?? $data['company_id'] ?? null;
        }
        if (isset($data['type'])) $updates['type'] = $data['type'];
        if (isset($data['amount'])) $updates['amount'] = $data['amount'];
        if (isset($data['currency'])) $updates['currency'] = $data['currency'];
        if (isset($data['status'])) $updates['status'] = $data['status'];
        if (array_key_exists('description', $data)) $updates['description'] = $data['description'];
        if (isset($data['paymentMethod']) || isset($data['payment_method'])) {
            $updates['payment_method'] = $data['paymentMethod'] ?? $data['payment_method'];
        }
        if (isset($data['date'])) $updates['date'] = $data['date'];
        if (array_key_exists('chequeNumber', $data) || array_key_exists('cheque_number', $data)) {
            $updates['cheque_number'] = $data['chequeNumber'] ?? $data['cheque_number'];
        }
        if (array_key_exists('chequeDate', $data) || array_key_exists('cheque_date', $data)) {
            $updates['cheque_date'] = $data['chequeDate'] ?? $data['cheque_date'];
        }

        // Target candidate folder for slips
        $targetCandidateId = $updates['candidate_id'] ?? $ledgerEntry->candidate_id;
        $slipFolder = $targetCandidateId ? "candidates/{$targetCandidateId}/slips" : 'ledger/slips';
        $compressionOptions = [
            'max_dimension' => 1600,
            'jpeg_quality' => 80,
            'webp_quality' => 80,
        ];

        // Handle slip file updates
        if ($request->hasFile('slip') || $request->hasFile('slip_file')) {
            $file = $request->file('slip') ?? $request->file('slip_file');
            if ($ledgerEntry->slip_path) {
                Storage::disk('public')->delete($ledgerEntry->slip_path);
            }
            $comp = app(DocumentCompressionService::class)->storeAndCompress(
                $file,
                $slipFolder,
                'public',
                $compressionOptions
            );
            $updates['slip_path'] = $comp['path'];
        } elseif ($request->filled('slip') && is_string($request->input('slip')) && str_starts_with($request->input('slip'), 'data:image')) {
            if ($ledgerEntry->slip_path) {
                Storage::disk('public')->delete($ledgerEntry->slip_path);
            }
            $updates['slip_path'] = app(DocumentCompressionService::class)->storeBase64AndCompress(
                $request->input('slip'),
                $slipFolder,
                'public',
                'slip_',
                $compressionOptions
            );
        } elseif ($request->boolean('removeSlip') || $request->boolean('delete_slip')) {
            if ($ledgerEntry->slip_path) {
                Storage::disk('public')->delete($ledgerEntry->slip_path);
            }
            $updates['slip_path'] = null;
        }

        $ledgerEntry->update($updates);
        $ledgerEntry->load(['candidate:id,first_name,last_name,code', 'company:id,name']);

        return response()->json([
            'entry' => $this->present($ledgerEntry),
        ]);
    }

    public function destroy(Request $request, LedgerEntry $ledgerEntry): JsonResponse
    {
        $this->requirePermission($request, 'ledger.delete');
        $ledgerEntry->delete();

        return response()->json([
            'message' => 'Ledger entry deleted successfully.',
        ]);
    }

    private function present(LedgerEntry $entry): array
    {
        return [
            'id' => (string) $entry->id,
            'transactionNo' => $entry->transaction_no,
            'date' => $entry->date ? $entry->date->toDateString() : $entry->created_at?->toDateString(),
            'candidateId' => $entry->candidate_id ? (string) $entry->candidate_id : null,
            'candidateName' => $entry->candidate ? trim($entry->candidate->first_name . ' ' . $entry->candidate->last_name) : null,
            'candidateCode' => $entry->candidate?->code,
            'companyId' => $entry->company_id ? (string) $entry->company_id : null,
            'companyName' => $entry->company?->name,
            'type' => $entry->type,
            'amount' => (float) $entry->amount,
            'currency' => $entry->currency ?? 'PKR',
            'status' => $entry->status,
            'description' => $entry->description ?? '',
            'paymentMethod' => $entry->payment_method ?? 'Bank Transfer',
            'slipPath' => $entry->slip_path,
            'slipUrl' => $entry->slip_url,
            'chequeNumber' => $entry->cheque_number,
            'chequeDate' => $entry->cheque_date ? $entry->cheque_date->toDateString() : null,
            'createdAt' => $entry->created_at?->toIso8601String(),
        ];
    }
}
