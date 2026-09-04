<?php

namespace App\Http\Controllers\Cgnat;

use App\Domain\Cgnat\CgnatSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\CgnatSearchRequest;
use App\Jobs\GenerateCgnatExport;
use App\Services\ClickHouse\ClickHouseQueryService;
use App\Services\Portal\ExportTaskRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    public function store(CgnatSearchRequest $request, ClickHouseQueryService $clickhouse, ExportTaskRepository $tasks): JsonResponse|RedirectResponse
    {
        try {
            $search = CgnatSearch::fromValidated($this->normalizedFilters($request));
            $count = $clickhouse->count($search);
            if ($count['partial']) {
                throw new RuntimeException('No se puede generar una exportación parcial: uno o más nodos ClickHouse no respondieron.');
            }
            $username = (string) $request->session()->get('portal_auth.username');
            $taskId = $tasks->create($username, $search, $count['total']);
            GenerateCgnatExport::dispatch($taskId, $username, $search->toArray(), $count['total'], $count['per_node']);
            $message = 'El CSV fue enviado a Tareas y exportaciones. Puedes continuar trabajando mientras se genera.';
            return $request->expectsJson() ? response()->json(['message' => $message, 'task_id' => $taskId], 202) : redirect()->route('exports.index')->with('status', $message);
        } catch (RuntimeException $exception) {
            return $request->expectsJson() ? response()->json(['message' => $exception->getMessage()], 422) : back()->withErrors(['export' => $exception->getMessage()]);
        }
    }

    public function index(Request $request, ExportTaskRepository $tasks): \Illuminate\Contracts\View\View
    {
        return view('exports.index', ['tasks' => $tasks->forUser((string) $request->session()->get('portal_auth.username'))]);
    }

    public function download(Request $request, string $id, ExportTaskRepository $tasks): BinaryFileResponse
    {
        if (! Str::isUuid($id)) abort(404);
        $task = $tasks->findForUser($id, (string) $request->session()->get('portal_auth.username'));
        if (! $task || $task['state'] !== 'completed' || blank($task['filename'])) abort(404);
        $username = preg_replace('/[^a-z0-9_.-]/i', '_', strtolower((string) $request->session()->get('portal_auth.username')));
        $path = storage_path('app/exports/'.$username.'/'.$task['filename']);
        if (! is_file($path)) abort(404);
        return response()->download($path, (string) $task['filename'], ['Content-Type' => 'text/csv; charset=UTF-8']);
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
