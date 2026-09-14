<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\CandidateSubmission;
use App\Models\CandidateWithdrawal;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CandidateController extends Controller
{
    // --------------------------------------------------------------------------
    // List — GET /candidates
    // --------------------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $query = Candidate::with(['company', 'documents', 'withdrawal'])
            ->withCount('documents')
            ->orderByDesc('created_at');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search): void {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('passport_number', 'ilike', "%{$search}%")
                    ->orWhere('cnic_number', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%")
                    ->orWhere('trade', 'ilike', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($companyId = $request->query('company_id')) {
            if ($companyId === 'unassigned') {
                $query->whereNull('current_company_id');
            } else {
                $query->where('current_company_id', $companyId);
            }
        }

        $candidates = $query->get();

        return response()->json([
            'candidates' => $candidates->map(fn (Candidate $c): array => $this->presentList($c))->values(),
        ]);
    }

    // --------------------------------------------------------------------------
    // Show — GET /candidates/{candidate}
    // --------------------------------------------------------------------------

    public function show(Candidate $candidate): JsonResponse
    {
        $candidate->load(['company', 'documents', 'submissions.company', 'withdrawal']);

        return response()->json([
            'candidate' => $this->presentDetail($candidate),
        ]);
    }

    // --------------------------------------------------------------------------
    // Store — POST /candidates
    // --------------------------------------------------------------------------

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'firstName'        => ['required', 'string', 'max:100'],
            'lastName'         => ['required', 'string', 'max:100'],
            'email'            => ['nullable', 'email', 'max:255'],
            'phone'            => ['required', 'string', 'max:50'],
            'passportNumber'   => ['required', 'string', 'max:50'],
            'passportExpiry'   => ['required', 'date'],
            'cnicNumber'       => ['required', 'string', 'max:20'],
            'nationality'      => ['nullable', 'string', 'max:100'],
            'currentLocation'  => ['nullable', 'string', 'max:200'],
            'targetCountry'    => ['nullable', 'string', 'max:200'],
            'assignedRecruiter'=> ['nullable', 'string', 'max:100'],
            'trade'            => ['required', 'string', 'max:255'],
            'experienceYears'  => ['nullable', 'integer', 'min:0'],
            'expectedSalary'   => ['nullable', 'numeric', 'min:0'],
            'currency'         => ['nullable', 'string', 'max:10'],
            'balance'          => ['nullable', 'numeric', 'min:0'],
            'photo'            => ['nullable', 'image', 'max:5120'], // 5 MB max
        ]);

        // Generate sequential code
        $count       = Candidate::withTrashed()->count();
        $code        = 'CRG-' . str_pad((string) ($count + 1001), 4, '0', STR_PAD_LEFT);
        $psnCode     = 'PSN-' . now()->year . '-' . str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);

        // Handle photo upload
        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('candidates/photos', 'public');
        }

        $skills = $request->input('skills');
        if (is_string($skills)) {
            $decoded = json_decode($skills, true);
            $skills  = is_array($decoded) ? $decoded : [$skills];
        }
        if (empty($skills) && !empty($data['trade'])) {
            $skills = array_values(array_filter(array_map('trim', explode(',', $data['trade']))));
        }

        $candidate = Candidate::create([
            'code'               => $code,
            'psn_code'           => $psnCode,
            'first_name'         => $data['firstName'],
            'last_name'          => $data['lastName'],
            'email'              => $data['email'] ?? null,
            'phone'              => $data['phone'] ?? null,
            'passport_number'    => $data['passportNumber'],
            'passport_expiry'    => $data['passportExpiry'] ?? null,
            'cnic_number'        => $data['cnicNumber'] ?? null,
            'nationality'        => $data['nationality'] ?? 'Pakistani',
            'current_location'   => $data['currentLocation'] ?? 'Pakistan',
            'target_country'     => $data['targetCountry'] ?? null,
            'assigned_recruiter' => $data['assignedRecruiter'] ?? null,
            'trade'              => $data['trade'],
            'experience_years'   => $data['experienceYears'] ?? 0,
            'expected_salary'    => $data['expectedSalary'] ?? 0,
            'currency'           => $data['currency'] ?? 'AED',
            'photo_path'         => $photoPath,
            'status'             => 'available',
            'recruitment_stage'  => 'registered',
            'skills'             => $skills ?? [],
            'balance'            => $data['balance'] ?? 0,
            'joined_date'        => now()->toDateString(),
        ]);

        $candidate->load(['company', 'documents', 'submissions', 'withdrawal']);

        return response()->json([
            'candidate' => $this->presentDetail($candidate),
        ], 201);
    }

    // --------------------------------------------------------------------------
    // Update — PATCH /candidates/{candidate}
    // --------------------------------------------------------------------------

    public function update(Request $request, Candidate $candidate): JsonResponse
    {
        $data = $request->validate([
            'firstName'        => ['required', 'string', 'max:100'],
            'lastName'         => ['required', 'string', 'max:100'],
            'email'            => ['nullable', 'email', 'max:255'],
            'phone'            => ['nullable', 'string', 'max:50'],
            'passportNumber'   => ['required', 'string', 'max:50'],
            'passportExpiry'   => ['nullable', 'date'],
            'cnicNumber'       => ['nullable', 'string', 'max:20'],
            'nationality'      => ['nullable', 'string', 'max:100'],
            'currentLocation'  => ['nullable', 'string', 'max:200'],
            'targetCountry'    => ['nullable', 'string', 'max:200'],
            'assignedRecruiter'=> ['nullable', 'string', 'max:100'],
            'trade'            => ['required', 'string', 'max:255'],
            'experienceYears'  => ['nullable', 'integer', 'min:0'],
            'expectedSalary'   => ['nullable', 'numeric', 'min:0'],
            'currency'         => ['nullable', 'string', 'max:10'],
            'balance'          => ['nullable', 'numeric', 'min:0'],
            'photo'            => ['nullable', 'image', 'max:5120'],
        ]);

        $photoPath = $candidate->photo_path;
        if ($request->hasFile('photo')) {
            // Delete old photo
            if ($photoPath) {
                Storage::disk('public')->delete($photoPath);
            }
            $photoPath = $request->file('photo')->store('candidates/photos', 'public');
        }

        $skills = $request->input('skills');
        if (is_string($skills)) {
            $decoded = json_decode($skills, true);
            $skills  = is_array($decoded) ? $decoded : [$skills];
        }
        if ($skills === null && !empty($data['trade'])) {
            $skills = array_values(array_filter(array_map('trim', explode(',', $data['trade']))));
        }

        $candidate->fill([
            'first_name'         => $data['firstName'],
            'last_name'          => $data['lastName'],
            'email'              => $data['email'] ?? null,
            'phone'              => $data['phone'] ?? null,
            'passport_number'    => $data['passportNumber'],
            'passport_expiry'    => $data['passportExpiry'] ?? null,
            'cnic_number'        => $data['cnicNumber'] ?? null,
            'nationality'        => $data['nationality'] ?? 'Pakistani',
            'current_location'   => $data['currentLocation'] ?? 'Pakistan',
            'target_country'     => $data['targetCountry'] ?? null,
            'assigned_recruiter' => $data['assignedRecruiter'] ?? null,
            'trade'              => $data['trade'],
            'experience_years'   => $data['experienceYears'] ?? 0,
            'expected_salary'    => $data['expectedSalary'] ?? 0,
            'currency'           => $data['currency'] ?? 'AED',
            'balance'            => array_key_exists('balance', $data) ? ($data['balance'] ?? 0) : $candidate->balance,
            'skills'             => $skills ?? $candidate->skills,
            'photo_path'         => $photoPath,
        ]);
        $candidate->save();
        $candidate->refresh();

        $candidate->load(['company', 'documents', 'submissions', 'withdrawal']);

        return response()->json([
            'candidate' => $this->presentDetail($candidate),
        ]);
    }

    // --------------------------------------------------------------------------
    // Destroy — DELETE /candidates/{candidate}
    // --------------------------------------------------------------------------

    public function destroy(Candidate $candidate): JsonResponse
    {
        if ($candidate->photo_path) {
            Storage::disk('public')->delete($candidate->photo_path);
        }

        $candidate->delete();

        return response()->json(['message' => 'Candidate deleted successfully.']);
    }

    // --------------------------------------------------------------------------
    // Update Stage — PATCH /candidates/{candidate}/stage
    // --------------------------------------------------------------------------

    public function updateStage(Request $request, Candidate $candidate): JsonResponse
    {
        $data = $request->validate([
            'stage' => ['required', 'in:inquiry,registered,docs_collection,processing,visa_applied,visa_stamped,ready_to_fly'],
        ]);

        $candidate->update(['recruitment_stage' => $data['stage']]);

        return response()->json([
            'candidate' => $this->presentDetail($candidate->fresh()->load(['company', 'documents', 'submissions', 'withdrawal'])),
        ]);
    }

    // --------------------------------------------------------------------------
    // Update Status — PATCH /candidates/{candidate}/status
    // --------------------------------------------------------------------------

    public function updateStatus(Request $request, Candidate $candidate): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:available,placed,processing,on_hold,withdrawn,archived'],
        ]);

        $candidate->update(['status' => $data['status']]);

        return response()->json([
            'candidate' => $this->presentDetail($candidate->fresh()->load(['company', 'documents', 'submissions', 'withdrawal'])),
        ]);
    }

    // --------------------------------------------------------------------------
    // Shift to Company — POST /candidates/shift
    // --------------------------------------------------------------------------

    public function shiftToCompany(Request $request): JsonResponse
    {
        $data = $request->validate([
            'candidateIds'   => ['required', 'array', 'min:1'],
            'candidateIds.*' => ['required', 'integer', 'exists:candidates,id'],
            'companyId'      => ['required', 'integer', 'exists:companies,id'],
            'note'           => ['nullable', 'string', 'max:500'],
        ]);

        $company    = Company::findOrFail($data['companyId']);
        $sharedToken = Str::random(32);
        $shiftedAt   = now();
        $shiftedBy   = $request->user()->name ?? 'System';

        $candidates = Candidate::whereIn('id', $data['candidateIds'])->get();

        foreach ($candidates as $candidate) {
            $candidate->update([
                'status'             => 'placed',
                'current_company_id' => $company->id,
            ]);

            CandidateSubmission::create([
                'candidate_id' => $candidate->id,
                'company_id'   => $company->id,
                'company_name' => $company->name,
                'shifted_at'   => $shiftedAt,
                'note'         => $data['note'] ?? null,
                'shifted_by'   => $shiftedBy,
                'share_token'  => $sharedToken . '-' . $candidate->id,
            ]);
        }

        $shareableUrl = url('/share/' . $sharedToken);

        return response()->json([
            'message'      => count($candidates) . ' candidate(s) shifted to ' . $company->name,
            'shareableUrl' => $shareableUrl,
            'sharedToken'  => $sharedToken,
        ]);
    }

    // --------------------------------------------------------------------------
    // Return to Pool — PATCH /candidates/{candidate}/return
    // --------------------------------------------------------------------------

    public function returnToPool(Candidate $candidate): JsonResponse
    {
        $candidate->update([
            'status'             => 'available',
            'current_company_id' => null,
        ]);

        return response()->json([
            'candidate' => $this->presentDetail($candidate->fresh()->load(['company', 'documents', 'submissions', 'withdrawal'])),
            'message'   => 'Candidate returned to the available pool.',
        ]);
    }

    // --------------------------------------------------------------------------
    // Withdraw — POST /candidates/{candidate}/withdraw
    // --------------------------------------------------------------------------

    public function withdraw(Request $request, Candidate $candidate): JsonResponse
    {
        $data = $request->validate([
            'stage'                  => ['required', 'string'],
            'deductionPercentage'    => ['required', 'numeric', 'min:0', 'max:100'],
            'totalPaidSoFar'         => ['required', 'numeric', 'min:0'],
            'cancellationFee'        => ['required', 'numeric', 'min:0'],
            'refundPayable'          => ['required', 'numeric', 'min:0'],
            'reason'                 => ['nullable', 'string'],
            'paymentMode'            => ['required', 'in:cheque,bank_transfer,cash'],
            'chequeNumber'           => ['nullable', 'string', 'max:100'],
            'chequeDate'             => ['nullable', 'date'],
            'alertFinanceBeforeDays' => ['nullable', 'string'],
        ]);

        // Remove any existing withdrawal
        $candidate->withdrawal()->delete();

        CandidateWithdrawal::create([
            'candidate_id'               => $candidate->id,
            'stage'                      => $data['stage'],
            'deduction_percentage'       => $data['deductionPercentage'],
            'total_paid_so_far'          => $data['totalPaidSoFar'],
            'cancellation_fee'           => $data['cancellationFee'],
            'refund_payable'             => $data['refundPayable'],
            'reason'                     => $data['reason'] ?? null,
            'payment_mode'               => $data['paymentMode'],
            'cheque_number'              => $data['chequeNumber'] ?? null,
            'cheque_date'                => $data['chequeDate'] ?? null,
            'alert_finance_before_days'  => $data['alertFinanceBeforeDays'] ?? null,
            'status'                     => 'pending_hr_approval',
            'withdrawn_at'               => now(),
        ]);

        $candidate->update(['status' => 'withdrawn']);

        return response()->json([
            'candidate' => $this->presentDetail($candidate->fresh()->load(['company', 'documents', 'submissions', 'withdrawal'])),
            'message'   => 'Candidate withdrawal recorded.',
        ]);
    }

    // --------------------------------------------------------------------------
    // Upload Document — POST /candidates/{candidate}/documents
    // --------------------------------------------------------------------------

    public function storeDocument(Request $request, Candidate $candidate): JsonResponse
    {
        $data = $request->validate([
            'title'            => ['required', 'string', 'max:255'],
            'documentTypeId'   => ['nullable', 'string'],
            'documentTypeName' => ['nullable', 'string', 'max:100'],
            'file'             => ['nullable', 'file', 'max:20480'], // 20 MB
            'issueDate'        => ['nullable', 'date'],
            'expiryDate'       => ['nullable', 'date'],
            'notes'            => ['nullable', 'string'],
        ]);

        $filePath = null;
        $fileName = null;
        $fileSize = null;

        if ($request->hasFile('file')) {
            $file     = $request->file('file');
            $filePath = $file->store('candidates/' . $candidate->id . '/documents', 'public');
            $fileName = $file->getClientOriginalName();
            $fileSize = $this->humanFileSize($file->getSize());
        }

        // Determine document status based on expiry date
        $status = 'pending';
        if ($data['expiryDate'] ?? null) {
            $expiry = \Carbon\Carbon::parse($data['expiryDate']);
            $status = $expiry->isPast() ? 'expired' : 'pending';
        }

        $document = $candidate->documents()->create([
            'title'              => $data['title'],
            'document_type_id'   => $data['documentTypeId'] ?? 'doc-type-1',
            'document_type_name' => $data['documentTypeName'] ?? 'Compliance Document',
            'file_path'          => $filePath,
            'file_name'          => $fileName,
            'file_size'          => $fileSize,
            'issue_date'         => $data['issueDate'] ?? null,
            'expiry_date'        => $data['expiryDate'] ?? null,
            'status'             => $status,
            'notes'              => $data['notes'] ?? null,
        ]);

        // Auto-advance stage to docs_collection on first document upload
        if (in_array($candidate->recruitment_stage, ['inquiry', 'registered'])) {
            $candidate->update(['recruitment_stage' => 'docs_collection']);
        }

        return response()->json([
            'document' => $this->presentDocument($document),
        ], 201);
    }

    // --------------------------------------------------------------------------
    // Update Document — PATCH /candidates/{candidate}/documents/{document}
    // --------------------------------------------------------------------------

    public function updateDocument(Request $request, Candidate $candidate, CandidateDocument $document): JsonResponse
    {
        $data = $request->validate([
            'title'            => ['sometimes', 'string', 'max:255'],
            'documentTypeId'   => ['sometimes', 'string'],
            'documentTypeName' => ['sometimes', 'string', 'max:100'],
            'file'             => ['nullable', 'file', 'max:20480'],
            'issueDate'        => ['nullable', 'date'],
            'expiryDate'       => ['nullable', 'date'],
            'notes'            => ['nullable', 'string'],
            'status'           => ['sometimes', 'in:verified,expiring,expired,pending,rejected'],
        ]);

        $filePath = $document->file_path;
        $fileName = $document->file_name;
        $fileSize = $document->file_size;

        if ($request->hasFile('file')) {
            // Delete old file
            if ($filePath) {
                Storage::disk('public')->delete($filePath);
            }
            $file     = $request->file('file');
            $filePath = $file->store('candidates/' . $candidate->id . '/documents', 'public');
            $fileName = $file->getClientOriginalName();
            $fileSize = $this->humanFileSize($file->getSize());
        }

        $updateData = array_filter([
            'title'              => $data['title'] ?? null,
            'document_type_id'   => $data['documentTypeId'] ?? null,
            'document_type_name' => $data['documentTypeName'] ?? null,
            'file_path'          => $filePath,
            'file_name'          => $fileName,
            'file_size'          => $fileSize,
            'issue_date'         => $data['issueDate'] ?? null,
            'expiry_date'        => $data['expiryDate'] ?? null,
            'notes'              => $data['notes'] ?? null,
            'status'             => $data['status'] ?? null,
        ], fn ($v) => $v !== null);

        $document->update($updateData);

        return response()->json([
            'document' => $this->presentDocument($document->fresh()),
        ]);
    }

    // --------------------------------------------------------------------------
    // Delete Document — DELETE /candidates/{candidate}/documents/{document}
    // --------------------------------------------------------------------------

    public function destroyDocument(Candidate $candidate, CandidateDocument $document): JsonResponse
    {
        if ($document->file_path) {
            Storage::disk('public')->delete($document->file_path);
        }

        $document->delete();

        return response()->json(['message' => 'Document deleted.']);
    }

    // --------------------------------------------------------------------------
    // Private presenters
    // --------------------------------------------------------------------------

    private function presentList(Candidate $candidate): array
    {
        return [
            'id'                  => (string) $candidate->id,
            'code'                => $candidate->code,
            'psnCode'             => $candidate->psn_code,
            'firstName'           => $candidate->first_name,
            'lastName'            => $candidate->last_name,
            'email'               => $candidate->email ?? '',
            'passportNumber'      => $candidate->passport_number,
            'trade'               => $candidate->trade,
            'experienceYears'     => $candidate->experience_years,
            'status'              => $candidate->status,
            'currentCompanyId'    => $candidate->current_company_id ? (string) $candidate->current_company_id : null,
            'currentCompanyName'  => $candidate->company?->name,
            'photoUrl'            => $candidate->photo_url,
            'nationality'         => $candidate->nationality,
            'documents'           => $candidate->documents->map(fn (CandidateDocument $d): array => $this->presentDocument($d))->values(),
        ];
    }

    private function presentDetail(Candidate $candidate): array
    {
        return [
            'id'                  => (string) $candidate->id,
            'code'                => $candidate->code,
            'psnCode'             => $candidate->psn_code,
            'cnicNumber'          => $candidate->cnic_number,
            'firstName'           => $candidate->first_name,
            'lastName'            => $candidate->last_name,
            'email'               => $candidate->email ?? '',
            'phone'               => $candidate->phone ?? '',
            'passportNumber'      => $candidate->passport_number,
            'passportExpiry'      => $candidate->passport_expiry?->toDateString() ?? '',
            'passportSeries'      => $candidate->passport_series,
            'passportIssuedBy'    => $candidate->passport_issued_by,
            'passportIssueDate'   => $candidate->passport_issue_date?->toDateString(),
            'trade'               => $candidate->trade,
            'experienceYears'     => $candidate->experience_years,
            'nationality'         => $candidate->nationality,
            'currentLocation'     => $candidate->current_location,
            'targetCountry'       => $candidate->target_country ?? '',
            'assignedRecruiter'   => $candidate->assigned_recruiter ?? '',
            'status'              => $candidate->status,
            'recruitmentStage'    => $candidate->recruitment_stage,
            'currentCompanyId'    => $candidate->current_company_id ? (string) $candidate->current_company_id : null,
            'currentCompanyName'  => $candidate->company?->name,
            'joinedDate'          => $candidate->joined_date?->toDateString() ?? now()->toDateString(),
            'skills'              => $candidate->skills ?? [],
            'expectedSalary'      => (float) $candidate->expected_salary,
            'currency'            => $candidate->currency,
            'photoUrl'            => $candidate->photo_url,
            'balance'             => (float) $candidate->balance,
            'fatherName'          => $candidate->father_name,
            'motherName'          => $candidate->mother_name,
            'placeOfBirth'        => $candidate->place_of_birth,
            'dateOfBirth'         => $candidate->date_of_birth?->toDateString(),
            'civilStatus'         => $candidate->civil_status,
            'childrenCount'       => $candidate->children_count,
            'documents'           => $candidate->documents->map(fn (CandidateDocument $d): array => $this->presentDocument($d))->values(),
            'submissionHistory'   => $candidate->submissions->map(fn (CandidateSubmission $s): array => $this->presentSubmission($s))->values(),
            'withdrawalRecord'    => $candidate->withdrawal ? $this->presentWithdrawal($candidate->withdrawal) : null,
        ];
    }

    private function presentDocument(CandidateDocument $doc): array
    {
        return [
            'id'               => (string) $doc->id,
            'candidateId'      => (string) $doc->candidate_id,
            'title'            => $doc->title,
            'documentTypeId'   => $doc->document_type_id,
            'documentTypeName' => $doc->document_type_name,
            'fileUrl'          => $doc->file_url ?? '#',
            'filePreviewUrl'   => $doc->file_url,
            'fileName'         => $doc->file_name ?? '',
            'fileSize'         => $doc->file_size ?? '',
            'issueDate'        => $doc->issue_date?->toDateString() ?? '',
            'expiryDate'       => $doc->expiry_date?->toDateString() ?? '',
            'status'           => $doc->status,
            'verifiedBy'       => $doc->verified_by,
            'notes'            => $doc->notes,
        ];
    }

    private function presentSubmission(CandidateSubmission $sub): array
    {
        return [
            'id'           => (string) $sub->id,
            'candidateId'  => (string) $sub->candidate_id,
            'companyId'    => (string) $sub->company_id,
            'companyName'  => $sub->company_name,
            'shiftedAt'    => $sub->shifted_at?->format('Y-m-d H:i'),
            'note'         => $sub->note ?? '',
            'shiftedBy'    => $sub->shifted_by ?? '',
            'shareableUrl' => url('/share/' . $sub->share_token),
        ];
    }

    private function presentWithdrawal(CandidateWithdrawal $w): array
    {
        return [
            'stage'                  => $w->stage,
            'deductionPercentage'    => (float) $w->deduction_percentage,
            'totalPaidSoFar'         => (float) $w->total_paid_so_far,
            'cancellationFee'        => (float) $w->cancellation_fee,
            'refundPayable'          => (float) $w->refund_payable,
            'reason'                 => $w->reason ?? '',
            'paymentMode'            => $w->payment_mode,
            'chequeNumber'           => $w->cheque_number,
            'chequeDate'             => $w->cheque_date?->toDateString(),
            'alertFinanceBeforeDays' => $w->alert_finance_before_days,
            'status'                 => $w->status,
            'withdrawnAt'            => $w->withdrawn_at?->toDateTimeString(),
        ];
    }

    private function humanFileSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }

        return round($bytes / 1024) . ' KB';
    }
}
