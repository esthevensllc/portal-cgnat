<?php

namespace App\Jobs;

use App\Domain\Cgnat\CgnatSearch;
use App\Services\ClickHouse\ClickHouseQueryService;
use App\Services\Portal\ExportTaskRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class GenerateCgnatExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    /** @param array<string, mixed> $filters @param array<string, int> $perNode */
    public function __construct(
        public readonly string $taskId,
        public readonly string $username,
        public readonly array $filters,
        public readonly int $totalRows,
        public readonly array $perNode,
    ) {}

    public function handle(ClickHouseQueryService $clickhouse, ExportTaskRepository $tasks): void
    {
        $search = CgnatSearch::fromValidated($this->filters);
        $directory = storage_path('app/exports/'.preg_replace('/[^a-z0-9_.-]/i', '_', strtolower($this->username)));
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException('No fue posible preparar el directorio de exportación.');
        }

        $filename = sprintf('cgnat-%s-%s.csv', now()->format('Ymd-His'), $this->taskId);
        $path = $directory.'/'.$filename;
        $tasks->update($this->taskId, $this->username, 'running', $this->totalRows, 0);

        try {
            $clickhouse->exportTo($search, $path, $this->perNode, function (int $processed) use ($tasks): void {
                $tasks->update($this->taskId, $this->username, 'running', $this->totalRows, $processed);
            });
            $tasks->update($this->taskId, $this->username, 'completed', $this->totalRows, $this->totalRows, $filename);
        } catch (Throwable $exception) {
            @unlink($path);
            $tasks->update($this->taskId, $this->username, 'failed', $this->totalRows, 0, '', $exception->getMessage());
            throw $exception;
        }
    }
}
