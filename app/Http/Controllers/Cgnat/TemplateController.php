<?php

namespace App\Http\Controllers\Cgnat;

use App\Http\Controllers\Controller;
use App\Http\Requests\CgnatSearchRequest;
use App\Services\Portal\QueryTemplateRepository;
use App\Services\Portal\PortalAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    public function store(CgnatSearchRequest $request, QueryTemplateRepository $templates, PortalAuditService $audit): JsonResponse
    {
        $data = $request->validate(['template_name' => ['required', 'string', 'max:80', 'regex:/^[\pL\pN _.-]+$/u']]);
        $filters = $this->normalizedFilters($request);
        $username = (string) $request->session()->get('portal_auth.username');
        $templateId = $templates->create($username, $data['template_name'], $filters);
        $audit->record('template.created', 'success', $username, $request, [
            'name' => $data['template_name'],
        ], 'query_template', $templateId);
        return response()->json(['message' => 'Plantilla guardada correctamente.']);
    }

    public function index(Request $request, QueryTemplateRepository $templates): \Illuminate\Contracts\View\View
    {
        return view('templates.index', ['templates' => $templates->forUser((string) $request->session()->get('portal_auth.username'))]);
    }

    /** @return array<string, mixed> */
    private function normalizedFilters(CgnatSearchRequest $request): array
    {
        $filters = $request->validated();
        if (! in_array('cgnat.nodes', (array) $request->session()->get('portal_auth.permissions', []), true)) {
            $filters['nodes'] = array_keys(config('clickhouse.nodes', []));
        }

        return $filters;
    }
}
