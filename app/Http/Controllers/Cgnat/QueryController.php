<?php

namespace App\Http\Controllers\Cgnat;

use App\Domain\Cgnat\CgnatSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\CgnatSearchRequest;
use App\Services\ClickHouse\ClickHouseQueryService;
use App\Services\ClickHouse\PortalQueryAuditService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class QueryController extends Controller
{
    public function index(Request $request): View
    {
        return view('queries.index', [
            'nodes' => config('clickhouse.nodes', []),
            'result' => null,
            'permissions' => (array) $request->session()->get('portal_auth.permissions', []),
        ]);
    }

    public function store(CgnatSearchRequest $request, ClickHouseQueryService $service, PortalQueryAuditService $audit): View|JsonResponse
    {
        $search = null;

        try {
            $validated = $this->normalizedFilters($request);
            $search = CgnatSearch::fromValidated($validated);
            $isPagination = array_key_exists('known_total', $validated);
            $threshold = (int) config('clickhouse.export_threshold', 100000);

            if ($isPagination) {
                $totalRows = (int) $validated['known_total'];
                $countElapsed = 0;
                $countNodes = [];
            } else {
                $count = $service->count($search);

                if ($count['partial']) {
                    throw new RuntimeException('No se puede mostrar un resultado parcial: uno o más nodos ClickHouse no respondieron.');
                }

                $totalRows = (int) $count['total'];
                $countElapsed = (int) $count['elapsed_ms'];
                $countNodes = $count['nodes'];
            }

            $largeResult = $totalRows > $threshold;

            if ($largeResult) {
                $pageResult = [
                    'rows' => [],
                    'nodes' => $countNodes,
                    'partial' => false,
                    'elapsed_ms' => 0,
                    'has_more' => false,
                    'next_cursor' => null,
                    'page_size' => 20,
                    'large_result' => true,
                    'export_threshold' => $threshold,
                ];
            } elseif (! $isPagination && $totalRows === 0) {
                $pageResult = [
                    'rows' => [],
                    'nodes' => $countNodes,
                    'partial' => false,
                    'elapsed_ms' => 0,
                    'has_more' => false,
                    'next_cursor' => null,
                    'page_size' => 20,
                    'large_result' => false,
                    'export_threshold' => $threshold,
                ];
            } else {
                $pageResult = $service->search($search);

                if ($pageResult['partial']) {
                    throw new RuntimeException('No se puede mostrar un resultado parcial: uno o más nodos ClickHouse no respondieron.');
                }

                $pageResult['large_result'] = false;
                $pageResult['export_threshold'] = $threshold;
            }

            $result = [
                ...$pageResult,
                'total_rows' => $totalRows,
                'elapsed_ms' => $countElapsed + (int) ($pageResult['elapsed_ms'] ?? 0),
            ];

            if (! $isPagination) {
                $audit->recordSuccess((string) $request->session()->get('portal_auth.username'), $search, $result, $request);
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'html' => view('queries.partials.result', compact('result'))->render(),
                    'total_rows' => $totalRows,
                    'large_result' => $largeResult,
                    'export_threshold' => $threshold,
                ]);
            }

            return view('queries.index', [
                'nodes' => config('clickhouse.nodes', []),
                'result' => $result,
                'permissions' => (array) $request->session()->get('portal_auth.permissions', []),
            ]);
        } catch (RuntimeException $exception) {
            if ($search instanceof CgnatSearch && ! ($isPagination ?? false)) {
                $audit->recordFailure(
                    (string) $request->session()->get('portal_auth.username'),
                    $search,
                    $exception->getMessage(),
                    $request,
                );
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'html' => view('queries.partials.result', [
                        'result' => null,
                        'runtimeError' => $exception->getMessage(),
                    ])->render(),
                    'message' => $exception->getMessage(),
                ], 422);
            }

            return view('queries.index', [
                'nodes' => config('clickhouse.nodes', []),
                'result' => null,
                'permissions' => (array) $request->session()->get('portal_auth.permissions', []),
            ])->with('runtimeError', $exception->getMessage());
        }
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
