<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class LlantasImport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            'MAYOREO HERMOSILLO' => new LlantasHermosilloSheetImport(),
        ];
    }
}
