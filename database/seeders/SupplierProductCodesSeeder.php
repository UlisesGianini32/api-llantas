<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class SupplierProductCodesSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('purchasing:import-supplier-codes');
    }
}
