<?php

namespace App\Http\Controllers\Cgnat;

use App\Domain\Cgnat\CgnatSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\CgnatSearchRequest;
use App\Jobs\GenerateCgnatExport;
use App\Services\ClickHouse\ClickHouseQueryService;
use App\Services\Portal\ExportTaskRepository;
use App\Services\Portal\PortalAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    public function store(CgnatSearchRequest $request, ClickHouseQueryService $clickhouse, ExportTaskRepository $tasks, PortalAuditService $audit): JsonResponse|RedirectResponse
    {
        $username = (string) $request->session()->get('portal_auth.username');

        try {
            $search = CgnatSearch::fromValidated($this->normalizedFilters($request));
            $count = $clickhouse->count($search);
            if ($count['partial']) {
                throw new RuntimeException('No se puede generar una exportación parcial: uno o más nodos ClickHouse no respondieron.');
            }

            $totalFound = max(0, (int) $count['total']);
            $exportMaxRows = max(1, (int) config('clickhouse.export_max_rows', 60000000));
            $exportRows = min($totalFound, $exportMaxRows);
            $exportLimited = $totalFound > $exportMaxRows;
            $limitedPerNode = $this->limitPerNode((array) $count['per_node'], $exportRows);

            $taskId = $tasks->create($username, $search, $exportRows);
            GenerateCgnatExport::dispatch($taskId, $username, $search->toArray(), $exportRows, $limitedPerNode);

            $audit->record('export.queued', 'success', $username, $request, [
                'selected_nodes' => $search->nodes,
                'query_total_rows' => $totalFound,
                'export_rows' => $exportRows,
                'export_max_rows' => $exportMaxRows,
                'export_limited' => $exportLimited,
            ], 'export_task', $taskId, $count['elapsed_ms'], $exportRows);

            $message = $exportLimited
                ? sprintf(
                    'La consulta encontró %s registros. Por el límite máximo configurado se exportarán únicamente los primeros %s registros. La exportación fue enviada a Tareas y exportaciones.',
                    number_format($totalFound, 0, '.', ','),
                    number_format($exportRows, 0, '.', ','),
                )
                : 'La exportación fue enviada a Tareas y exportaciones. Al finalizar podrás descargar un ZIP con el CSV.';

            $payload = [
                'message' => $message,
                'task_id' => $taskId,
                'total_rows' => $totalFound,
                'export_rows' => $exportRows,
                'export_max_rows' => $exportMaxRows,
                'export_limited' => $exportLimited,
            ];

            return $request->expectsJson()
                ? response()->json($payload, 202)
                : redirect()->route('exports.index')->with('status', $message);
        } catch (RuntimeException $exception) {
            $audit->record('export.request', 'failure', $username, $request, [
                'reason' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
            return $request->expectsJson() ? response()->json(['message' => $exception->getMessage()], 422) : back()->withErrors(['export' => $exception->getMessage()]);
        }
    }

    public function index(Request $request, ExportTaskRepository $tasks): \Illuminate\Contracts\View\View
    {
        return view('exports.index', ['tasks' => $tasks->forUser((string) $request->session()->get('portal_auth.username'))]);
    }

    public function download(Request $request, string $id, ExportTaskRepository $tasks, PortalAuditService $audit): BinaryFileResponse
    {
        if (! Str::isUuid($id)) abort(404);
        $task = $tasks->findForUser($id, (string) $request->session()->get('portal_auth.username'));
        if (! $task || $task['state'] !== 'completed' || blank($task['filename'])) abort(404);
        $username = preg_replace('/[^a-z0-9_.-]/i', '_', strtolower((string) $request->session()->get('portal_auth.username')));
        $path = storage_path('app/exports/'.$username.'/'.$task['filename']);
        if (! is_file($path)) abort(404);
        $audit->record(
            'export.downloaded',
            'success',
            (string) $request->session()->get('portal_auth.username'),
            $request,
            ['filename' => (string) $task['filename']],
            'export_task',
            $id,
        );

        $extension = strtolower((string) pathinfo((string) $task['filename'], PATHINFO_EXTENSION));
        $contentType = $extension === 'zip' ? 'application/zip' : 'text/csv; charset=UTF-8';

        return response()->download($path, (string) $task['filename'], ['Content-Type' => $contentType]);
    }

    /**
     *
     * @param array<string, int|numeric-string> $perNode
     * @return array<string, int>
     */
    private function limitPerNode(array $perNode, int $maxRows): array
    {
        $remaining = max(0, $maxRows);
        $limited = [];

        foreach ($perNode as $name => $rows) {
            $available = max(0, (int) $rows);
            $take = min($available, $remaining);
            $limited[(string) $name] = $take;
            $remaining -= $take;

            if ($remaining <= 0) {
                $remaining = 0;
            }
        }

        return $limited;
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
