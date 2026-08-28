<?php

namespace App\Imports;

use App\Services\Autopartes\AutomotivePartImportService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

class AutomotivePartExcelImport implements ToCollection
{
    public function __construct(
        protected int $importId,
    ) {}

    public function collection(Collection $rows): void
    {
        app(AutomotivePartImportService::class)->processRows($this->importId, $rows);
    }
}
