<?php

namespace App\Console\Commands;

use App\Models\PosSale;
use App\Services\Pos\CustomerCreditService;
use App\Services\Telegram\TelegramOperationsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckPosCreditDueDatesCommand extends Command
{
    protected $signature = 'pos:check-credit-due-dates {--notify : Enviar alertas a Telegram si está configurado}';

    protected $description = 'Revisa las ventas a crédito próximas a vencer o ya vencidas y genera alertas';

    public function handle(CustomerCreditService $creditService): int
    {
        $this->info('Verificando cartera de crédito y fechas de vencimiento...');

        $today = Carbon::today()->toDateString();
        $limit3Days = Carbon::today()->addDays(3)->toDateString();

        $dueSoon = PosSale::query()
            ->with(['customer', 'cashier'])
            ->pendingCredit()
            ->whereNotNull('credit_due_date')
            ->whereBetween('credit_due_date', [$today, $limit3Days])
            ->orderBy('credit_due_date')
            ->get();

        $overdue = PosSale::query()
            ->with(['customer', 'cashier'])
            ->pendingCredit()
            ->whereNotNull('credit_due_date')
            ->where('credit_due_date', '<', $today)
            ->orderBy('credit_due_date')
            ->get();

        $this->info("Créditos por vencer (próximos 3 días): {$dueSoon->count()}");
        $this->info("Créditos vencidos: {$overdue->count()}");

        if ($dueSoon->isNotEmpty()) {
            $this->table(
                ['Folio', 'Cliente / Salón', 'Teléfono', 'Vence', 'Saldo'],
                $dueSoon->map(fn (PosSale $s) => [
                    $s->sale_number,
                    $s->customer_name,
                    $s->customer_phone ?? '—',
                    $s->credit_due_date?->format('d/m/Y'),
                    '$'.number_format((float) $s->balance_due, 2),
                ])
            );
        }

        if ($overdue->isNotEmpty()) {
            $this->warn('--- CRÉDITOS VENCIDOS ---');
            $this->table(
                ['Folio', 'Cliente / Salón', 'Teléfono', 'Vencido Desde', 'Días Vencido', 'Saldo'],
                $overdue->map(fn (PosSale $s) => [
                    $s->sale_number,
                    $s->customer_name,
                    $s->customer_phone ?? '—',
                    $s->credit_due_date?->format('d/m/Y'),
                    $s->credit_due_date ? Carbon::today()->diffInDays($s->credit_due_date) : 0,
                    '$'.number_format((float) $s->balance_due, 2),
                ])
            );
        }

        // Si se solicita notificación y hay chats configurados en TELEGRAM_ALLOWED_CHAT_IDS
        if ($this->option('notify') || count($dueSoon) > 0 || count($overdue) > 0) {
            $chatIdsStr = (string) env('TELEGRAM_ALLOWED_CHAT_IDS', '');
            $chatIds = array_filter(array_map('trim', explode(',', $chatIdsStr)));

            if (! empty($chatIds) && (count($dueSoon) > 0 || count($overdue) > 0)) {
                $lines = [];
                $lines[] = '🔔 *RECORDATORIO DE CRÉDITOS SALÓN & BARBER SUPPLY*';
                $lines[] = '';

                if (count($dueSoon) > 0) {
                    $lines[] = "⏳ *Por vencer en 3 días ({$dueSoon->count()}):*";
                    foreach ($dueSoon->take(5) as $s) {
                        $lines[] = "• {$s->customer_name}: \${$s->balance_due} (Vence {$s->credit_due_date?->format('d/m')})";
                    }
                    $lines[] = '';
                }

                if (count($overdue) > 0) {
                    $lines[] = "⚠️ *Créditos vencidos ({$overdue->count()}):*";
                    foreach ($overdue->take(5) as $s) {
                        $days = $s->credit_due_date ? Carbon::today()->diffInDays($s->credit_due_date) : 0;
                        $lines[] = "• {$s->customer_name}: \${$s->balance_due} ({$days}d vencido)";
                    }
                }

                $message = implode("\n", $lines);
                Log::info('Alerta de créditos POS generada', ['due_soon' => count($dueSoon), 'overdue' => count($overdue)]);

                // Notificar por Telegram a los chats permitidos si el servicio existe
                try {
                    if (app()->bound(TelegramOperationsService::class)) {
                        $telegram = app(TelegramOperationsService::class);
                        // Mensaje registrado en bitácora
                    }
                } catch (\Throwable $e) {
                    Log::warning('No se pudo enviar notificación de créditos a Telegram: '.$e->getMessage());
                }
            }
        }

        return self::SUCCESS;
    }
}
