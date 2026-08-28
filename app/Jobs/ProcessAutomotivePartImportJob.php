<?php

namespace App\Jobs;

use App\Models\AutomotivePartImport;
use App\Services\Autopartes\AutomotivePartImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Facades\Excel;

class ProcessAutomotivePartImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $importId,
        public string $filePath,
    ) {}

    public function handle(AutomotivePartImportService $service): void
    {
        $import = AutomotivePartImport::query()->findOrFail($this->importId);

        $import->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);

        try {
            Excel::import(new \App\Imports\AutomotivePartExcelImport($this->importId), $this->filePath);
            $import->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $import->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }
}
