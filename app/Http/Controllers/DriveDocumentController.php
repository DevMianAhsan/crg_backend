<?php

namespace App\Http\Controllers;

use App\Models\DriveDocument;
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

        $file = $data['file'];
        $document = DriveDocument::create([
            'name' => $data['name'],
            'file_path' => $file->store('drive', 'public'),
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
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
            Storage::disk('public')->delete($driveDocument->file_path);
            $file = $data['file'];
            $attributes += [
                'file_path' => $file->store('drive', 'public'),
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
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
