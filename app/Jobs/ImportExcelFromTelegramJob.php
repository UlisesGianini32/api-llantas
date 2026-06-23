<?php

namespace App\Jobs;

use App\Imports\LlantasImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class ImportExcelFromTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200; // 20 min
    public int $tries = 1;

    public function __construct(
        public string $chatId,
        public string $fileId,
        public string $fileName
    ) {}

    public function handle(): void
    {
        $this->send("📥 Importando: {$this->fileName}");

        try {
            $token = (string) env('TELEGRAM_BOT_TOKEN');

            // 1) obtener file_path
            $getFile = Http::timeout(30)->get("https://api.telegram.org/bot{$token}/getFile", [
                'file_id' => $this->fileId,
            ])->json();

            $filePath = data_get($getFile, 'result.file_path');
            if (!$filePath) {
                throw new \Exception('No se pudo obtener file_path desde Telegram');
            }

            // 2) descargar binario
            $downloadUrl = "https://api.telegram.org/file/bot{$token}/{$filePath}";
            $binary = Http::timeout(120)->get($downloadUrl)->body();

            // 3) guardar en storage/app/imports
            $safeName = preg_replace('/[^a-zA-Z0-9\.\-\_]/', '_', $this->fileName);
            $stored   = 'imports/' . now()->format('Y-m-d_His') . '_' . $safeName;
            $fullPath = storage_path('app/' . $stored);

            @mkdir(dirname($fullPath), 0775, true);
            file_put_contents($fullPath, $binary);

            // 4) importar
            Excel::import(new LlantasImport, $fullPath);

            $this->send("✅ Importado correctamente: {$this->fileName}");
            $this->send("🔄 Sincronizando con Mercado Libre (stock/precio)…");

            // 5) disparar sync (Job 2)
            SyncMeliAfterImportJob::dispatch($this->chatId);

        } catch (\Throwable $e) {
            Log::error('Telegram import error', [
                'msg' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->send("❌ Error importando: " . $e->getMessage());
            throw $e;
        }
    }

    private function send(string $text): void
    {
        $token = (string) env('TELEGRAM_BOT_TOKEN');

        Http::timeout(20)->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $this->chatId,
            'text'    => $text,
        ]);
    }
}
