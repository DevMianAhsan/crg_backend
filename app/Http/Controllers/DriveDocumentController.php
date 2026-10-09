<?php

namespace App\Http\Controllers;

use App\Models\DriveDocument;
use App\Services\DocumentCompressionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DriveDocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'drive.view');
        return response()->json([
            'documents' => DriveDocument::with('uploader')
                ->latest('updated_at')
                ->get()
                ->map(fn (DriveDocument $document): array => $this->present($document))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'drive.create');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:15360'],
        ]);

        $comp = app(DocumentCompressionService::class)->storeAndCompress(
            $data['file'],
            'drive',
            'public'
        );

        $document = DriveDocument::create([
            'name' => $data['name'],
            'file_path' => $comp['path'],
            'file_name' => $comp['file_name'],
            'file_size' => $comp['size_bytes'],
            'mime_type' => $comp['mime_type'],
            'uploaded_by' => $request->user()?->id,
        ]);

        return response()->json(['document' => $this->present($document->load('uploader'))], 201);
    }

    public function update(Request $request, DriveDocument $driveDocument): JsonResponse
    {
        $this->requirePermission($request, 'drive.update');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'file' => ['nullable', 'file', 'max:15360'],
        ]);

        $attributes = ['name' => $data['name']];
        if ($request->hasFile('file')) {
            if ($driveDocument->file_path && Storage::disk('public')->exists($driveDocument->file_path)) {
                Storage::disk('public')->delete($driveDocument->file_path);
            }
            $comp = app(DocumentCompressionService::class)->storeAndCompress(
                $data['file'],
                'drive',
                'public'
            );
            $attributes += [
                'file_path' => $comp['path'],
                'file_name' => $comp['file_name'],
                'file_size' => $comp['size_bytes'],
                'mime_type' => $comp['mime_type'],
            ];
        }

        $driveDocument->update($attributes);

        return response()->json([
            'document' => $this->present($driveDocument->fresh()->load('uploader')),
        ]);
    }

    public function destroy(Request $request, DriveDocument $driveDocument): JsonResponse
    {
        $this->requirePermission($request, 'drive.delete');
        Storage::disk('public')->delete($driveDocument->file_path);
        $driveDocument->delete();

        return response()->json(['message' => 'Drive document deleted successfully.']);
    }

    private function present(DriveDocument $document): array
    {
        return [
            'id' => (string) $document->id,
            'name' => $document->name,
            'size' => (int) $document->file_size,
            'type' => $document->mime_type,
            'uploadedAt' => $document->created_at?->toDateString(),
            'updatedAt' => $document->updated_at?->toDateString(),
            'uploadedBy' => $document->uploader?->name ?? 'System',
            'url' => $document->file_url ? url($document->file_url) : null,
            // True when the record exists but its file isn't on this server's disk
            'fileMissing' => !$document->file_path || !Storage::disk('public')->exists($document->file_path),
        ];
    }
}
