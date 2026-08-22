<?php

namespace App\Console\Commands;

use App\Services\Autopartes\AutomotivePartEnrichmentAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AuditAutomotivePartEnrichmentCommand extends Command
{
    protected $signature = 'autopartes:audit-enrichment
        {--limit= : Cantidad máxima de productos a revisar}
        {--part-id= : ID de una autoparte específica}
        {--refresh-approved : Recalcular problemas en revisiones aprobadas}';

    protected $description = 'Audita autopartes y prepara revisiones de enriquecimiento';

    public function handle(AutomotivePartEnrichmentAuditService $service): int
    {
        $limit = $this->positiveIntegerOption('limit');
        $partId = $this->positiveIntegerOption('part-id');

        if ($limit === false || $partId === false) {
            return self::FAILURE;
        }

        $stats = $service->audit(
            $limit,
            $partId,
            (bool) $this->option('refresh-approved'),
        );

        $this->table(['Métrica', 'Total'], [
            ['Productos revisados', $stats['reviewed']],
            ['Pendientes creados', $stats['created']],
            ['Revisiones actualizadas', $stats['updated']],
            ['Aprobados omitidos', $stats['approved_skipped']],
            ['Rechazados omitidos', $stats['rejected_skipped']],
            ['Errores', $stats['errors']],
        ]);

        $errorDetails = $stats['error_details'] ?? [];
        if ($errorDetails !== []) {
            $verbose = $this->getOutput()->isVerbose();

            $this->newLine();
            $this->warn('Detalle de errores (máximo 10):');
            $this->table(['Automotive Part ID', 'Excepción', 'Mensaje'], array_map(
                fn (array $detail) => [
                    $detail['automotive_part_id'],
                    $detail['exception_class'],
                    $verbose ? $detail['message'] : Str::limit($detail['message'], 240, '…'),
                ],
                $errorDetails,
            ));

            if (! $verbose) {
                $this->comment('Usa -v, -vv o -vvv para mostrar mensajes completos.');
            }
        }

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function positiveIntegerOption(string $name): int|false|null
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            $this->error("--{$name} debe ser un entero mayor a cero.");

            return false;
        }

        return (int) $value;
    }
}
