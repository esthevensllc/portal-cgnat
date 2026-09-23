<?php

namespace App\Jobs;

use App\Domain\Cgnat\CgnatSearch;
use App\Services\ClickHouse\ClickHouseQueryService;
use App\Services\Portal\ExportTaskRepository;
use App\Services\Portal\PortalAuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;
use ZipArchive;

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

    public function handle(ClickHouseQueryService $clickhouse, ExportTaskRepository $tasks, PortalAuditService $audit): void
    {
        $search = CgnatSearch::fromValidated($this->filters);
        $directory = storage_path('app/exports/'.preg_replace('/[^a-z0-9_.-]/i', '_', strtolower($this->username)));
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException('No fue posible preparar el directorio de exportación.');
        }

        $baseName = sprintf('cgnat-%s-%s', now()->format('Ymd-His'), $this->taskId);
        $csvFilename = $baseName.'.csv';
        $zipFilename = $baseName.'.zip';
        $csvPath = $directory.'/'.$csvFilename;
        $zipPath = $directory.'/'.$zipFilename;
        $tasks->update($this->taskId, $this->username, 'running', $this->totalRows, 0);
        $audit->record('export.started', 'pending', $this->username, resourceType: 'export_task', resourceId: $this->taskId, totalRows: $this->totalRows);

        $processedRows = 0;

        try {
            $processedRows = $clickhouse->exportTo(
                $search,
                $csvPath,
                $this->perNode,
                function (int $processed) use ($tasks, &$processedRows): void {
                    $processedRows = $processed;
                    $tasks->update($this->taskId, $this->username, 'running', $this->totalRows, $processed);
                },
            );

            if ($this->totalRows > 0 && $processedRows === 0) {
                throw new RuntimeException(sprintf(
                    'La exportación no generó registros aunque la consulta reportó %s registros.',
                    number_format($this->totalRows, 0, '.', ','),
                ));
            }

            $csvBytes = filesize($csvPath);
            if ($csvBytes === false || $csvBytes <= 0) {
                throw new RuntimeException('El CSV generado está vacío y no puede comprimirse.');
            }

            $tasks->update(
                $this->taskId,
                $this->username,
                'compressing',
                $this->totalRows,
                $processedRows,
            );

            $this->compressCsv($csvPath, $csvFilename, $zipPath);

            $zipBytes = filesize($zipPath);
            if ($zipBytes === false || $zipBytes <= 0) {
                throw new RuntimeException('No fue posible validar el archivo ZIP generado.');
            }

            if (! @unlink($csvPath) && is_file($csvPath)) {
                throw new RuntimeException('El ZIP fue generado, pero no fue posible eliminar el CSV temporal.');
            }

            $finalTotalRows = max($this->totalRows, $processedRows);

            $tasks->update(
                $this->taskId,
                $this->username,
                'completed',
                $finalTotalRows,
                $processedRows,
                $zipFilename,
            );

            $audit->record('export.completed', 'success', $this->username, details: [
                'filename' => $zipFilename,
                'csv_filename' => $csvFilename,
                'expected_rows' => $this->totalRows,
                'exported_rows' => $processedRows,
                'csv_bytes' => $csvBytes,
                'zip_bytes' => $zipBytes,
            ], resourceType: 'export_task', resourceId: $this->taskId, totalRows: $processedRows);
        } catch (Throwable $exception) {
            @unlink($csvPath);
            @unlink($zipPath);
            $tasks->update(
                $this->taskId,
                $this->username,
                'failed',
                $this->totalRows,
                $processedRows,
                '',
                $exception->getMessage(),
            );
            $audit->record('export.failed', 'failure', $this->username, details: [
                'reason' => mb_substr($exception->getMessage(), 0, 1000),
                'processed_rows' => $processedRows,
            ], resourceType: 'export_task', resourceId: $this->taskId, totalRows: $this->totalRows);
            throw $exception;
        }
    }
    private function compressCsv(string $csvPath, string $csvFilename, string $zipPath): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('La extensión ZIP de PHP no está disponible en el worker.');
        }

        $zip = new ZipArchive();
        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new RuntimeException('No fue posible crear el archivo ZIP de la exportación.');
        }

        try {
            if (! $zip->addFile($csvPath, $csvFilename)) {
                throw new RuntimeException('No fue posible agregar el CSV al archivo ZIP.');
            }

            if (method_exists($zip, 'setCompressionName')) {
                $zip->setCompressionName($csvFilename, ZipArchive::CM_DEFLATE);
            }
        } catch (Throwable $exception) {
            $zip->close();
            throw $exception;
        }

        if (! $zip->close()) {
            throw new RuntimeException('No fue posible finalizar el archivo ZIP de la exportación.');
        }
    }

}
