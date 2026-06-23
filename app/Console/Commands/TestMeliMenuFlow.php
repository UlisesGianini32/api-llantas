<?php

namespace App\Console\Commands;

use App\Services\MeliMenuAutomationService;
use Illuminate\Console\Command;

class TestMeliMenuFlow extends Command
{
    protected $signature = 'meli:test-menu 
                            {event_type : order_created o buyer_message}
                            {order_id}
                            {buyer_id}
                            {--message=}
                            {--conversation_id=conv_test_001}
                            {--pack_id=}
                            {--user_id=1}
                            {--sku=}
                            {--item_id=}';

    protected $description = 'Prueba el menú posventa (buyer_message; order_created no envía por política MeLi)';

    public function handle(MeliMenuAutomationService $service): int
    {
        $payload = [
            'event_type' => $this->argument('event_type'),
            'order_id' => $this->argument('order_id'),
            'buyer_id' => $this->argument('buyer_id'),
            'conversation_id' => $this->option('conversation_id'),
            'pack_id' => $this->option('pack_id') ?: null,
            'message_id' => 'cli-' . uniqid(),
            'message_text' => $this->option('message'),
            'sku' => $this->option('sku'),
            'item_id' => $this->option('item_id'),
        ];

        $service->handleIncomingEvent(
            payload: $payload,
            userId: (int) $this->option('user_id')
        );

        $this->info('Flujo procesado correctamente.');

        return self::SUCCESS;
    }
}
