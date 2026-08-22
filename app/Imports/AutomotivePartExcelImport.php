<?php

namespace App\Imports;

use App\Services\Autopartes\AutomotivePartImportService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class AutomotivePartExcelImport implements ToCollection, WithChunkReading
{
    public function __construct(
        protected int $importId,
    ) {}

    public function collection(Collection $rows): void
    {
        app(AutomotivePartImportService::class)->processRows($this->importId, $rows);
    }

    public function chunkSize(): int
    {
        return 250;
    }
}
