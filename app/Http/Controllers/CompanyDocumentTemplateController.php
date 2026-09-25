<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyDocumentTemplate;
use App\Models\CompanyLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-company document templates (job offers, address letters, ...). The
 * content is HTML with {{placeholders}}; PDFs are generated on the client.
 */
class CompanyDocumentTemplateController extends Controller
{
    public function index(Request $request, Company $company): JsonResponse
    {
        $this->requirePermission($request, 'companies.view');

        return response()->json([
            'templates' => CompanyDocumentTemplate::where('company_id', $company->id)
                ->latest('updated_at')
                ->get()
                ->map(fn (CompanyDocumentTemplate $t): array => $this->present($t))
                ->values(),
        ]);
    }

    public function store(Request $request, Company $company): JsonResponse
    {
        $this->requirePermission($request, 'companies.update');
        $data = $this->validated($request);
        $userName = $request->user()->name ?? 'System';

        $template = CompanyDocumentTemplate::create([
            'company_id' => $company->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'content' => $this->sanitize($data['content']),
            'created_by' => $userName,
            'updated_by' => $userName,
        ]);

        CompanyLog::record($company->id, 'template_created', "Created document template \"{$template->name}\"", [
            'templateId' => $template->id,
        ], $request);

        return response()->json(['template' => $this->present($template)], 201);
    }

    public function update(Request $request, Company $company, CompanyDocumentTemplate $template): JsonResponse
    {
        $this->requirePermission($request, 'companies.update');
        abort_unless($template->company_id === $company->id, 404);
        $data = $this->validated($request);

        $template->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'content' => $this->sanitize($data['content']),
            'updated_by' => $request->user()->name ?? 'System',
        ]);

        CompanyLog::record($company->id, 'template_updated', "Updated document template \"{$template->name}\"", [
            'templateId' => $template->id,
        ], $request);

        return response()->json(['template' => $this->present($template->fresh())]);
    }

    public function destroy(Request $request, Company $company, CompanyDocumentTemplate $template): JsonResponse
    {
        $this->requirePermission($request, 'companies.update');
        abort_unless($template->company_id === $company->id, 404);

        $name = $template->name;
        $template->delete();

        CompanyLog::record($company->id, 'template_deleted', "Deleted document template \"{$name}\"", [], $request);

        return response()->json(['message' => 'Template deleted.']);
    }

    /** Records that documents were generated from a template (generation itself is client-side). */
    public function generated(Request $request, Company $company, CompanyDocumentTemplate $template): JsonResponse
    {
        $this->requirePermission($request, 'companies.view');
        abort_unless($template->company_id === $company->id, 404);
        $data = $request->validate([
            'candidateNames' => ['required', 'array', 'min:1'],
            'candidateNames.*' => ['string', 'max:255'],
        ]);

        $count = count($data['candidateNames']);
        CompanyLog::record(
            $company->id,
            'documents_generated',
            $count === 1
                ? "Generated \"{$template->name}\" for {$data['candidateNames'][0]}"
                : "Generated \"{$template->name}\" for {$count} candidates",
            ['templateId' => $template->id, 'candidateNames' => $data['candidateNames']],
            $request
        );

        return response()->json(['message' => 'Logged.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            // Images (stamps, signatures) are embedded as data URLs, hence the high limit
            'content' => ['required', 'string', 'max:6000000'],
        ]);
    }

    /** Strips scripts, event handlers and javascript: URLs from the template HTML. */
    private function sanitize(string $html): string
    {
        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|textarea|button|link|meta)\b[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|textarea|button|link|meta)\b[^>]*/?>#is', '', $html);
        $html = preg_replace('#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = preg_replace('#(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*\2#i', '$1="#"', $html);

        return $html;
    }

    private function present(CompanyDocumentTemplate $t): array
    {
        return [
            'id' => (string) $t->id,
            'companyId' => (string) $t->company_id,
            'name' => $t->name,
            'description' => $t->description ?? '',
            'content' => $t->content,
            'createdBy' => $t->created_by,
            'updatedBy' => $t->updated_by,
            'createdAt' => $t->created_at?->toIso8601String(),
            'updatedAt' => $t->updated_at?->toIso8601String(),
        ];
    }
}
