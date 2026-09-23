<?php

namespace App\Http\Controllers;

use App\Models\CandidateDocument;
use App\Models\DocumentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'documentTypes' => DocumentType::query()
                ->orderBy('id')
                ->get()
                ->map(fn (DocumentType $documentType): array => $this->present($documentType)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'document-types.create');
        $data = $this->validated($request);
        $documentType = DocumentType::create($this->attributes($data));

        return response()->json([
            'documentType' => $this->present($documentType),
        ], 201);
    }

    public function update(Request $request, DocumentType $documentType): JsonResponse
    {
        $this->requirePermission($request, 'document-types.update');
        $data = $this->validated($request, $documentType);
        $documentType->update($this->attributes($data));

        return response()->json([
            'documentType' => $this->present($documentType->fresh()),
        ]);
    }

    public function destroy(Request $request, DocumentType $documentType): JsonResponse
    {
        $this->requirePermission($request, 'document-types.delete');

        $inUseCount = CandidateDocument::query()
            ->where('document_type_id', (string) $documentType->id)
            ->orWhere('document_type_id', $documentType->code)
            ->count();

        if ($inUseCount > 0) {
            return response()->json([
                'message' => "Cannot delete document type '{$documentType->name}' because {$inUseCount} candidate document(s) are associated with it.",
            ], 422);
        }

        $documentType->delete();

        return response()->json([
            'message' => 'Document type deleted successfully.',
        ]);
    }

    private function validated(Request $request, ?DocumentType $documentType = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('document_types', 'code')->ignore($documentType?->id),
            ],
            'description' => ['nullable', 'string'],
            'isMandatory' => ['required', 'boolean'],
            'validityMonths' => ['required', 'integer', 'min:0'],
            'requiresExpiryDate' => ['required', 'boolean'],
        ]);
    }

    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'code' => $data['code'],
            'description' => $data['description'] ?? '',
            'is_mandatory' => $data['isMandatory'],
            'validity_months' => $data['validityMonths'],
            'requires_expiry_date' => $data['requiresExpiryDate'],
        ];
    }

    private function present(DocumentType $documentType): array
    {
        return [
            'id' => (string) $documentType->id,
            'name' => $documentType->name,
            'code' => $documentType->code,
            'description' => $documentType->description ?? '',
            'isMandatory' => $documentType->is_mandatory,
            'validityMonths' => $documentType->validity_months,
            'requiresExpiryDate' => $documentType->requires_expiry_date,
        ];
    }
}