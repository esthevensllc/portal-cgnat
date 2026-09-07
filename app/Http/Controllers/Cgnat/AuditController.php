<?php

namespace App\Http\Controllers\Cgnat;

use App\Http\Controllers\Controller;
use App\Services\Portal\AuditEventRepository;
use App\Services\Portal\PortalAuditService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request, AuditEventRepository $events, PortalAuditService $audit): View
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'username' => ['nullable', 'string', 'max:100'],
            'event_type' => ['nullable', 'string', 'max:100'],
            'outcome' => ['nullable', 'in:success,failure,denied,rate_limited'],
            'source_ip' => ['nullable', 'ip'],
            'request_id' => ['nullable', 'uuid'],
        ]);

        $filters['from'] ??= now()->subDays(7)->format('Y-m-d');
        $filters['to'] ??= now()->format('Y-m-d');

        $results = $events->search($filters);
        $audit->record(
            'audit.viewed',
            'success',
            (string) $request->session()->get('portal_auth.username'),
            $request,
            ['filters' => array_filter($filters)],
            totalRows: count($results),
        );

        return view('audit.index', [
            'events' => $results,
            'filters' => $filters,
        ]);
    }
}
