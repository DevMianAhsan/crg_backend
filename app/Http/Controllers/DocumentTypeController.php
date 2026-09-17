<?php

namespace App\Http\Controllers;

use App\Models\DocumentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'document-types.view');
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
        $data = $this->validated($request);
        $documentType->update($this->attributes($data));

        return response()->json([
            'documentType' => $this->present($documentType->fresh()),
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100'],
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