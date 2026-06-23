<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MeliOrderSyncService;
use Illuminate\Console\Command;

class MeliSyncOrderByIdCommand extends Command
{
    protected $signature = 'meli:sync-order
                            {order_id : ID de la orden en Mercado Libre}
                            {--user_id= : ID del usuario}
                            {--email= : Email del usuario}';

    protected $description = 'Sincroniza una orden puntual de Mercado Libre por order_id';

    public function handle(MeliOrderSyncService $service): int
    {
        $userId = $this->option('user_id');
        $email = $this->option('email');
        $orderId = (string) $this->argument('order_id');

        $user = null;

        if ($userId) {
            $user = User::find($userId);
        } elseif ($email) {
            $user = User::where('email', $email)->first();
        }

        if (!$user) {
            $this->error('No se encontró el usuario. Usa --user_id= o --email=');

            return self::FAILURE;
        }

        if (!$user->access_token) {
            $this->error('El usuario no tiene access_token.');

            return self::FAILURE;
        }

        try {
            $result = $service->syncOrderById($user, $orderId);

            $this->info('Sincronización puntual completada.');
            $this->line('Order ID: ' . $result['order_id']);
            $this->line('Seller ID: ' . ($result['seller_id'] ?? 'N/A'));
            $this->line('Items guardados: ' . $result['items']);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Error al sincronizar orden puntual: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
