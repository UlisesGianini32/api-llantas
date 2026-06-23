<?php

namespace App\Console\Commands;

use App\Models\SyscomMeliQueue;
use App\Models\User;
use Illuminate\Console\Command;

class SyscomMeliColaCommand extends Command
{
    protected $signature = 'syscom:cola
                            {--user_id= : Filtrar por usuario (id en users)}
                            {--status= : pending|published|error|pending_price|todos}
                            {--limit=50 : Máximo de filas a mostrar}';

    protected $description = 'Lista la cola SYSCOM → Mercado Libre (tabla syscom_meli_queues)';

    public function handle(): int
    {
        $userId = $this->option('user_id');
        $status = strtolower(trim((string) $this->option('status', 'todos')));
        $limit = max(1, min(500, (int) $this->option('limit')));

        $q = SyscomMeliQueue::query()
            ->with('product:id,syscom_producto_id,titulo,marca,modelo,stock_hermosillo')
            ->orderByDesc('updated_at');

        if ($userId !== null && $userId !== '') {
            $q->where('user_id', (int) $userId);
        }

        match ($status) {
            'pending', 'pendiente', 'pending_price' => $q->where('status', 'pending_price')
                ->where(function ($w) {
                    $w->whereNull('mlm')->orWhere('mlm', '');
                }),
            'published', 'publicado' => $q->where(function ($w) {
                $w->whereNotNull('mlm')->where('mlm', '!=', '')
                    ->orWhere('status', 'published');
            }),
            'error' => $q->where('status', 'error'),
            'todos', '', 'all' => null,
            default => $this->warn("Estado desconocido «{$status}»; mostrando todos."),
        };

        $rows = $q->limit($limit)->get();

        if ($rows->isEmpty()) {
            $this->line('Cola vacía (con los filtros actuales).');

            return self::SUCCESS;
        }

        $this->info('Cola SYSCOM → ML ('.$rows->count().' fila(s), límite '.$limit.')');
        $this->newLine();

        $table = [];
        foreach ($rows as $row) {
            $p = $row->product;
            $err = $row->publish_error;
            if (is_string($err) && strlen($err) > 60) {
                $err = substr($err, 0, 57).'…';
            }

            $table[] = [
                $row->user_id,
                $row->syscom_producto_id,
                $p ? mb_substr((string) $p->titulo, 0, 40) : '—',
                $row->status,
                $row->mlm ?: '—',
                $p ? (int) $p->stock_hermosillo : '—',
                $err ?: '—',
                $row->updated_at?->format('Y-m-d H:i') ?? '—',
            ];
        }

        $this->table(
            ['user', 'syscom_id', 'título', 'status', 'mlm', 'stock', 'error', 'actualizado'],
            $table
        );

        $base = SyscomMeliQueue::query();
        if ($userId !== null && $userId !== '') {
            $base->where('user_id', (int) $userId);
        }
        $this->newLine();
        $pendiente = (clone $base)->where('status', 'pending_price')->where(function ($w) {
            $w->whereNull('mlm')->orWhere('mlm', '');
        })->count();
        $conMlm = (clone $base)->where(function ($w) {
            $w->where(function ($q) {
                $q->whereNotNull('mlm')->where('mlm', '!=', '');
            })->orWhere('status', 'published');
        })->count();

        $this->line(sprintf(
            'Totales: %d en cola | %d pendiente | %d con MLM | %d error',
            (clone $base)->count(),
            $pendiente,
            $conMlm,
            (clone $base)->where('status', 'error')->count()
        ));

        if (! $userId) {
            $linked = User::query()->whereNotNull('access_token')->pluck('id', 'email');
            if ($linked->isNotEmpty()) {
                $this->line('Usuarios con ML vinculado (para --user_id=): '.$linked->map(fn ($id, $email) => "{$id}={$email}")->implode(', '));
            }
        }

        return self::SUCCESS;
    }
}
