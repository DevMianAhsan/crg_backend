<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\CompanyLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CompanyDocumentController extends Controller
{
    public function index(Request $request, Company $company): JsonResponse
    {
        $this->requireAnyPermission($request, ['companies.view', 'documents.view']);

        return response()->json([
            'documents' => $company->documents()
                ->latest()
                ->get()
                ->map(fn (CompanyDocument $doc): array => $this->present($doc))
                ->values(),
        ]);
    }

    public function store(Request $request, Company $company): JsonResponse
    {
        $this->requireAnyPermission($request, ['companies.update', 'companies.create', 'documents.create']);

        $data = $request->validate([
            'title'          => ['required', 'string', 'max:255'],
            'documentType'   => ['nullable', 'string', 'max:100'],
            'document_type'  => ['nullable', 'string', 'max:100'],
            'documentNumber' => ['nullable', 'string', 'max:255'],
            'document_number'=> ['nullable', 'string', 'max:255'],
            'file'           => ['required', 'file', 'max:102400'], // 100 MB
            'issueDate'      => ['nullable', 'date'],
            'issue_date'     => ['nullable', 'date'],
            'expiryDate'     => ['nullable', 'date'],
            'expiry_date'    => ['nullable', 'date'],
            'notes'          => ['nullable', 'string'],
            'status'         => ['nullable', 'string', 'max:50'],
        ]);

        $docType = $data['documentType'] ?? $data['document_type'] ?? 'General Document';
        $docNumber = $data['documentNumber'] ?? $data['document_number'] ?? null;
        $issueDate = $data['issueDate'] ?? $data['issue_date'] ?? null;
        $expiryDate = $data['expiryDate'] ?? $data['expiry_date'] ?? null;

        $filePath = null;
        $fileName = null;
        $fileSize = null;

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            // Store inside: company/{company_id}/...
            $folder = 'company/' . $company->id;
            $filePath = $file->store($folder, 'public');
            $fileName = $file->getClientOriginalName();
            $fileSize = $this->humanFileSize($file->getSize());
        }

        $status = $data['status'] ?? 'active';
        if ($expiryDate) {
            $exp = \Carbon\Carbon::parse($expiryDate);
            if ($exp->isPast()) {
                $status = 'expired';
            }
        }

        $userName = $request->user()?->name ?? 'Staff';

        $document = $company->documents()->create([
            'title'           => $data['title'],
            'document_type'   => $docType,
            'document_number' => $docNumber,
            'file_path'       => $filePath,
            'file_name'       => $fileName,
            'file_size'       => $fileSize,
            'issue_date'      => $issueDate,
            'expiry_date'     => $expiryDate,
            'status'          => $status,
            'notes'           => $data['notes'] ?? null,
            'uploaded_by'     => $userName,
        ]);

        CompanyLog::record(
            $company->id,
            'document_uploaded',
            "Uploaded document \"{$document->title}\" ({$document->document_type})",
            [
                'documentId' => $document->id,
                'fileName'   => $document->file_name,
                'fileSize'   => $document->file_size,
                'docType'    => $document->document_type,
            ],
            $request
        );

        return response()->json([
            'document' => $this->present($document),
            'message'  => 'Document uploaded successfully.',
        ], 201);
    }

    public function update(Request $request, Company $company, CompanyDocument $document): JsonResponse
    {
        $this->requireAnyPermission($request, ['companies.update', 'documents.update']);

        if ((int) $document->company_id !== (int) $company->id) {
            abort(404, 'Document does not belong to this company.');
        }

        $data = $request->validate([
            'title'          => ['sometimes', 'required', 'string', 'max:255'],
            'documentType'   => ['nullable', 'string', 'max:100'],
            'document_type'  => ['nullable', 'string', 'max:100'],
            'documentNumber' => ['nullable', 'string', 'max:255'],
            'document_number'=> ['nullable', 'string', 'max:255'],
            'file'           => ['nullable', 'file', 'max:102400'],
            'issueDate'      => ['nullable', 'date'],
            'issue_date'     => ['nullable', 'date'],
            'expiryDate'     => ['nullable', 'date'],
            'expiry_date'    => ['nullable', 'date'],
            'notes'          => ['nullable', 'string'],
            'status'         => ['nullable', 'string', 'max:50'],
        ]);

        $updates = [];
        if (array_key_exists('title', $data)) $updates['title'] = $data['title'];
        if ($request->has('documentType') || $request->has('document_type')) {
            $updates['document_type'] = $data['documentType'] ?? $data['document_type'];
        }
        if ($request->has('documentNumber') || $request->has('document_number')) {
            $updates['document_number'] = $data['documentNumber'] ?? $data['document_number'];
        }
        if ($request->has('issueDate') || $request->has('issue_date')) {
            $updates['issue_date'] = $data['issueDate'] ?? $data['issue_date'];
        }
        if ($request->has('expiryDate') || $request->has('expiry_date')) {
            $updates['expiry_date'] = $data['expiryDate'] ?? $data['expiry_date'];
            if ($updates['expiry_date']) {
                $exp = \Carbon\Carbon::parse($updates['expiry_date']);
                $updates['status'] = $exp->isPast() ? 'expired' : ($data['status'] ?? 'active');
            }
        }
        if (array_key_exists('notes', $data)) $updates['notes'] = $data['notes'];
        if (array_key_exists('status', $data)) $updates['status'] = $data['status'];

        if ($request->hasFile('file')) {
            // Delete old file if exists
            if ($document->file_path && Storage::disk('public')->exists($document->file_path)) {
                Storage::disk('public')->delete($document->file_path);
            }

            $file = $request->file('file');
            $folder = 'company/' . $company->id;
            $updates['file_path'] = $file->store($folder, 'public');
            $updates['file_name'] = $file->getClientOriginalName();
            $updates['file_size'] = $this->humanFileSize($file->getSize());
        }

        $document->update($updates);

        CompanyLog::record(
            $company->id,
            'document_updated',
            "Updated document \"{$document->title}\"",
            ['documentId' => $document->id],
            $request
        );

        return response()->json([
            'document' => $this->present($document->fresh()),
            'message'  => 'Document updated successfully.',
        ]);
    }

    public function destroy(Request $request, Company $company, CompanyDocument $document): JsonResponse
    {
        $this->requireAnyPermission($request, ['companies.update', 'companies.delete', 'documents.delete']);

        if ((int) $document->company_id !== (int) $company->id) {
            abort(404, 'Document does not belong to this company.');
        }

        $title = $document->title;
        $docId = $document->id;

        if ($document->file_path && Storage::disk('public')->exists($document->file_path)) {
            Storage::disk('public')->delete($document->file_path);
        }

        $document->delete();

        CompanyLog::record(
            $company->id,
            'document_deleted',
            "Deleted document \"{$title}\"",
            ['documentId' => $docId],
            $request
        );

        return response()->json([
            'message' => 'Document deleted successfully.',
        ]);
    }

    private function present(CompanyDocument $doc): array
    {
        return [
            'id'             => (string) $doc->id,
            'companyId'      => (string) $doc->company_id,
            'title'          => $doc->title,
            'documentType'   => $doc->document_type,
            'documentNumber' => $doc->document_number,
            'filePath'       => $doc->file_path,
            'fileUrl'        => $doc->file_url,
            'fileName'       => $doc->file_name,
            'fileSize'       => $doc->file_size,
            'issueDate'      => $doc->issue_date?->toDateString(),
            'expiryDate'     => $doc->expiry_date?->toDateString(),
            'status'         => $doc->status,
            'notes'          => $doc->notes,
            'uploadedBy'     => $doc->uploaded_by,
            'createdAt'      => $doc->created_at?->toIso8601String(),
            'updatedAt'      => $doc->updated_at?->toIso8601String(),
        ];
    }

    private function humanFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
