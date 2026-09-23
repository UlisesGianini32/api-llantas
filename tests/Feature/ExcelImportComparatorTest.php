<?php

namespace Tests\Feature;

use App\Http\Controllers\ExcelImportController;
use App\Services\Llantas\LlantaComparisonScannerService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class ExcelImportComparatorTest extends TestCase
{
    public function test_successful_excel_import_runs_comparator_and_reports_new_candidates(): void
    {
        Excel::fake();
        $this->mock(LlantaComparisonScannerService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andReturn([
                'llantas' => 3,
                'bloques' => 2,
                'pares' => 1,
                'candidates' => 1,
                'new_candidates' => 2,
                'refreshed_candidates' => 0,
                'decisions_respected' => 0,
                'pending_total' => 2,
            ]);
        });
        Session::start();

        $request = Request::create('/importar-excel', 'POST');
        $request->files->set('archivo', UploadedFile::fake()->create('llantas.xlsx'));
        $response = app(ExcelImportController::class)->importar(
            $request,
            app(LlantaComparisonScannerService::class)
        );

        $this->assertSame(route('excel.vista'), $response->getTargetUrl());
        $this->assertSame('Archivo importado correctamente.', Session::get('success'));
        $this->assertStringContainsString('2 candidatos nuevos', (string) Session::get('warning'));
        Excel::assertImported('llantas.xlsx');
    }

    public function test_successful_excel_import_without_new_candidates_keeps_normal_success_without_warning(): void
    {
        Excel::fake();
        $this->mock(LlantaComparisonScannerService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andReturn([
                'llantas' => 0,
                'bloques' => 0,
                'pares' => 0,
                'candidates' => 0,
                'new_candidates' => 0,
                'refreshed_candidates' => 1,
                'decisions_respected' => 1,
                'pending_total' => 1,
            ]);
        });
        Session::start();
        Session::forget('warning');

        $request = Request::create('/importar-excel', 'POST');
        $request->files->set('archivo', UploadedFile::fake()->create('llantas.xlsx'));
        $response = app(ExcelImportController::class)->importar(
            $request,
            app(LlantaComparisonScannerService::class)
        );

        $this->assertSame(route('excel.vista'), $response->getTargetUrl());
        $this->assertSame(
            'Archivo importado correctamente.',
            Session::get('success')
        );
        $this->assertNull(Session::get('warning'));
        Excel::assertImported('llantas.xlsx');
    }

    public function test_comparator_failure_does_not_fail_a_successful_excel_import(): void
    {
        Excel::fake();
        Log::spy();
        $this->mock(LlantaComparisonScannerService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andThrow(new \RuntimeException('scanner down'));
        });
        Session::start();

        $request = Request::create('/importar-excel', 'POST');
        $request->files->set('archivo', UploadedFile::fake()->create('llantas.xlsx'));
        $response = app(ExcelImportController::class)->importar(
            $request,
            app(LlantaComparisonScannerService::class)
        );

        $this->assertSame(route('excel.vista'), $response->getTargetUrl());
        $this->assertSame('Archivo importado correctamente.', Session::get('success'));
        $this->assertStringContainsString('no fue posible analizar', (string) Session::get('warning'));
        Excel::assertImported('llantas.xlsx');
        Log::shouldHaveReceived('error')->once();
    }
}
