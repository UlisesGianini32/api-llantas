<?php

namespace App\Services;

use App\Models\AmsProductIssue;
use App\Models\Llanta;
use App\Models\MeliChatFlow;
use App\Models\MeliClaim;
use App\Models\MeliFullStock;
use App\Models\MeliPublication;
use App\Models\MeliQuestion;
use App\Models\SystemNotificationRead;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NotificationCenterService
{
    public const TYPE_PUBLICATION = 'publication';
    public const TYPE_CLAIM = 'claim';
    public const TYPE_MESSAGE = 'message';
    public const TYPE_QUESTION = 'question';
    public const TYPE_STOCK = 'stock';
    public const TYPE_FULL = 'full';
    public const TYPE_ISSUE = 'issue';

    public const CATEGORY_CRITICAL = 'critical';
    public const CATEGORY_CUSTOMER = 'customer';
    public const CATEGORY_INVENTORY = 'inventory';
    public const CATEGORY_OPERATION = 'operation';

    public const SEVERITY_DANGER = 'danger';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_INFO = 'info';

    /**
     * Obtiene el resumen consolidado de notificaciones para el badge y topbar.
     * Se almacena en cache breve para no saturar consultas en cada navegación.
     */
    public function getSummary(User $user, bool $forceFresh = false): array
    {
        $cacheKey = "notif_summary_user_{$user->id}";

        if (! $forceFresh && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $all = $this->collectAllNotifications($user);

        // Agrupación y contadores
        $unread = $all->where('is_read', false)->values();
        $criticalCount = $unread->where('severity', self::SEVERITY_DANGER)->count();

        $countsByType = [
            self::TYPE_PUBLICATION => $unread->where('type', self::TYPE_PUBLICATION)->count(),
            self::TYPE_CLAIM => $unread->where('type', self::TYPE_CLAIM)->count(),
            self::TYPE_MESSAGE => $unread->where('type', self::TYPE_MESSAGE)->count(),
            self::TYPE_QUESTION => $unread->where('type', self::TYPE_QUESTION)->count(),
            self::TYPE_STOCK => $unread->where('type', self::TYPE_STOCK)->count() + $unread->where('type', self::TYPE_FULL)->count(),
            self::TYPE_ISSUE => $unread->where('type', self::TYPE_ISSUE)->count(),
        ];

        $summary = [
            'total_unread' => $unread->count(),
            'total_critical' => $criticalCount,
            'has_critical' => $criticalCount > 0,
            'counts' => $countsByType,
            'latest' => $all->take(8)->values()->all(),
        ];

        Cache::put($cacheKey, $summary, now()->addSeconds(30));

        return $summary;
    }

    /**
     * Obtiene la lista completa de notificaciones con filtros opcionales.
     *
     * @param array<string, mixed> $filters
     */
    public function getNotifications(User $user, array $filters = []): Collection
    {
        $all = $this->collectAllNotifications($user);

        $type = $filters['type'] ?? null;
        $category = $filters['category'] ?? null;
        $status = $filters['status'] ?? 'all'; // 'unread', 'read', 'all'
        $search = trim((string) ($filters['search'] ?? ''));

        return $all->filter(function (array $item) use ($type, $category, $status, $search) {
            if ($type && $type !== 'all' && $item['type'] !== $type) {
                return false;
            }

            if ($category && $category !== 'all' && $item['category'] !== $category) {
                return false;
            }

            if ($status === 'unread' && $item['is_read']) {
                return false;
            }

            if ($status === 'read' && ! $item['is_read']) {
                return false;
            }

            if ($search !== '') {
                $q = mb_strtolower($search);
                $haystack = mb_strtolower(
                    ($item['title'] ?? '') . ' ' .
                    ($item['subtitle'] ?? '') . ' ' .
                    ($item['message'] ?? '') . ' ' .
                    ($item['badge_text'] ?? '')
                );

                if (! str_contains($haystack, $q)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * Marca una notificación como leída.
     */
    public function markAsRead(User $user, string $key): void
    {
        if (Schema::hasTable('system_notification_reads')) {
            SystemNotificationRead::updateOrCreate(
                ['user_id' => $user->id, 'notification_key' => $key],
                ['read_at' => now()]
            );
        }

        $this->forgetUserCache($user);
    }

    /**
     * Marca todas las notificaciones activas como leídas para el usuario.
     */
    public function markAllAsRead(User $user): void
    {
        if (! Schema::hasTable('system_notification_reads')) {
            return;
        }

        $all = $this->collectAllNotifications($user);
        $now = now();

        $rows = $all->map(fn (array $item) => [
            'user_id' => $user->id,
            'notification_key' => $item['id'],
            'read_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if (! empty($rows)) {
            SystemNotificationRead::upsert(
                $rows,
                ['user_id', 'notification_key'],
                ['read_at', 'updated_at']
            );
        }

        $this->forgetUserCache($user);
    }

    /**
     * Descarta / Oculta una notificación.
     */
    public function dismiss(User $user, string $key): void
    {
        if (Schema::hasTable('system_notification_reads')) {
            SystemNotificationRead::updateOrCreate(
                ['user_id' => $user->id, 'notification_key' => $key],
                ['read_at' => now(), 'dismissed_at' => now()]
            );
        }

        $this->forgetUserCache($user);
    }

    /**
     * Limpia la cache del usuario.
     */
    public function forgetUserCache(User $user): void
    {
        Cache::forget("notif_summary_user_{$user->id}");
    }

    /**
     * Recolecta todas las fuentes de notificaciones y las normaliza.
     */
    protected function collectAllNotifications(User $user): Collection
    {
        $dismissedKeys = collect();
        $readKeys = collect();

        if (Schema::hasTable('system_notification_reads')) {
            $reads = SystemNotificationRead::where('user_id', $user->id)->get();
            $dismissedKeys = $reads->whereNotNull('dismissed_at')->pluck('notification_key')->flip();
            $readKeys = $reads->whereNotNull('read_at')->pluck('notification_key')->flip();
        }

        $items = collect();

        // 1. PUBLICACIONES BLOQUEADAS / PAUSADAS / MODERADAS
        $items = $items->concat($this->collectPublicationNotifications());

        // 2. RECLAMOS Y DEVOLUCIONES
        $items = $items->concat($this->collectClaimNotifications());

        // 3. MENSAJERÍA POSVENTA
        $items = $items->concat($this->collectMessageNotifications());

        // 4. PREGUNTAS PREVENTA SIN RESPONDER
        $items = $items->concat($this->collectQuestionNotifications());

        // 5. STOCK CRÍTICO / AGOTADOS
        $items = $items->concat($this->collectStockNotifications());

        // 6. FULL AGOTADO CON STOCK EN BODEGA
        $items = $items->concat($this->collectFullStockNotifications());

        // 7. INCIDENCIAS EN MESA DE EMPAQUE (AMS)
        $items = $items->concat($this->collectIssueNotifications());

        // Filtrar descartadas y mapear estado de lectura
        return $items
            ->reject(fn (array $n) => $dismissedKeys->has($n['id']))
            ->map(function (array $n) use ($readKeys) {
                $n['is_read'] = $readKeys->has($n['id']);
                return $n;
            })
            ->sortBy(function (array $n) {
                // Ordenar primero por severidad (danger = 1, warning = 2, info = 3),
                // luego por no leídas, luego por fecha más reciente
                $sevWeight = match ($n['severity']) {
                    self::SEVERITY_DANGER => 1,
                    self::SEVERITY_WARNING => 2,
                    default => 3,
                };
                $readWeight = $n['is_read'] ? 1 : 0;
                $timestamp = $n['timestamp'] ?? 0;

                // Devolver tupla ordenada
                return sprintf('%d_%d_%012d', $sevWeight, $readWeight, 999999999999 - $timestamp);
            })
            ->values();
    }

    /**
     * 1. Publicaciones Bloqueadas / Moderadas
     */
    protected function collectPublicationNotifications(): Collection
    {
        if (! Schema::hasTable('meli_publications')) {
            return collect();
        }

        $pubs = MeliPublication::query()
            ->whereIn('status', ['closed', 'inactive', 'blocked', 'under_review', 'suspended', 'paused'])
            ->orderByDesc('updated_at')
            ->limit(35)
            ->get();

        return $pubs->map(function (MeliPublication $pub) {
            $isBlocked = in_array($pub->status, ['closed', 'inactive', 'blocked', 'under_review', 'suspended'], true);
            $reason = $pub->block_reason;
            $subStatus = $pub->sub_status_text;

            $statusText = match ($pub->status) {
                'blocked' => 'Bloqueada por ML',
                'under_review' => 'En Revisión por ML',
                'suspended' => 'Suspendida por ML',
                'closed' => 'Finalizada / Cerrada',
                'paused' => 'Pausada',
                'inactive' => 'Inactiva',
                default => strtoupper((string) $pub->status),
            };

            $desc = $reason ?: ($subStatus ? "Sub-estado: {$subStatus}" : "Estado actual: {$statusText} en Mercado Libre.");

            $dt = $pub->updated_at ?? $pub->created_at ?? now();

            return [
                'id' => "pub_{$pub->id}_{$pub->status}",
                'type' => self::TYPE_PUBLICATION,
                'category' => self::CATEGORY_CRITICAL,
                'severity' => $isBlocked ? self::SEVERITY_DANGER : self::SEVERITY_WARNING,
                'title' => $isBlocked ? '🚫 Publicación Bloqueada o en Revisión' : '⏸️ Publicación Pausada',
                'subtitle' => "SKU: {$pub->sku} • MLM: {$pub->mlm}",
                'message' => $desc,
                'badge_text' => $statusText,
                'action_url' => "/meli/publicaciones?search={$pub->mlm}",
                'action_label' => 'Revisar Publicación',
                'time' => $dt->diffForHumans(),
                'timestamp' => $dt->timestamp,
                'meta' => [
                    'sku' => $pub->sku,
                    'mlm' => $pub->mlm,
                    'status' => $pub->status,
                    'permalink' => $pub->permalink,
                ],
            ];
        });
    }

    /**
     * 2. Reclamos y Devoluciones
     */
    protected function collectClaimNotifications(): Collection
    {
        if (! Schema::hasTable('meli_claims')) {
            return collect();
        }

        $claims = MeliClaim::query()
            ->whereNotIn('status', ['closed'])
            ->where('stage', '!=', 'closed')
            ->orderByDesc('affects_reputation')
            ->orderBy('reputation_due_date')
            ->orderByDesc('updated_at')
            ->limit(35)
            ->get();

        return $claims->map(function (MeliClaim $claim) {
            $affects = (bool) $claim->affects_reputation;
            $dt = $claim->last_updated ?? $claim->date_created ?? $claim->created_at ?? now();

            $dueInfo = '';
            if ($claim->reputation_due_date) {
                $dueInfo = ' • Vence: ' . Carbon::parse($claim->reputation_due_date)->diffForHumans();
            } elseif ($claim->due_date) {
                $dueInfo = ' • Vence: ' . Carbon::parse($claim->due_date)->diffForHumans();
            }

            $msg = $claim->detail_title ?: ($claim->problem ?: "Reclamo #{$claim->claim_id} activo en orden #{$claim->order_id}");

            return [
                'id' => "claim_{$claim->id}_{$claim->status}",
                'type' => self::TYPE_CLAIM,
                'category' => self::CATEGORY_CRITICAL,
                'severity' => $affects ? self::SEVERITY_DANGER : self::SEVERITY_WARNING,
                'title' => $affects ? '⚠️ Reclamo Urgente (Afecta Reputación)' : '⚠️ Reclamo Abierto',
                'subtitle' => "Orden #{$claim->order_id} • Reclamo #{$claim->claim_id}",
                'message' => $msg . $dueInfo,
                'badge_text' => $affects ? 'Afecta Reputación' : ($claim->type ?: 'Reclamo'),
                'action_url' => "/meli-claims/{$claim->id}",
                'action_label' => 'Atender Reclamo',
                'time' => $dt->diffForHumans(),
                'timestamp' => $dt->timestamp,
                'meta' => [
                    'claim_id' => $claim->claim_id,
                    'order_id' => $claim->order_id,
                    'type' => $claim->type,
                    'affects_reputation' => $affects,
                ],
            ];
        });
    }

    /**
     * 3. Mensajería Posventa
     */
    protected function collectMessageNotifications(): Collection
    {
        if (! Schema::hasTable('meli_chat_flows')) {
            return collect();
        }

        $flows = MeliChatFlow::query()
            ->where(function ($q) {
                $q->where('requires_human', true)
                    ->orWhere('last_message_role', 'buyer');
            })
            ->whereNotNull('order_id')
            ->where('order_id', '!=', '')
            ->orderByDesc('requires_human')
            ->orderByDesc('updated_at')
            ->limit(35)
            ->get();

        return $flows->map(function (MeliChatFlow $flow) {
            $reqHuman = (bool) $flow->requires_human;
            $dt = $flow->last_message_at ?? $flow->updated_at ?? now();

            $msg = $flow->last_message_text ?: "El comprador está esperando atención en la orden #{$flow->order_id}.";

            return [
                'id' => "msg_{$flow->id}_" . ($flow->last_inbound_message_id ?: 'pending'),
                'type' => self::TYPE_MESSAGE,
                'category' => self::CATEGORY_CUSTOMER,
                'severity' => $reqHuman ? self::SEVERITY_DANGER : self::SEVERITY_WARNING,
                'title' => $reqHuman ? '💬 Mensaje Posventa Requiere Atención Humana' : '💬 Mensaje Posventa de Comprador',
                'subtitle' => "Orden #{$flow->order_id}" . ($flow->sku ? " • SKU: {$flow->sku}" : ''),
                'message' => $msg,
                'badge_text' => $reqHuman ? 'Requiere Humano' : 'Sin Responder',
                'action_url' => "/meli/mensajeria?flow={$flow->id}",
                'action_label' => 'Abrir Chat Posventa',
                'time' => $dt->diffForHumans(),
                'timestamp' => $dt->timestamp,
                'meta' => [
                    'order_id' => $flow->order_id,
                    'pack_id' => $flow->pack_id,
                    'buyer_id' => $flow->buyer_id,
                    'sku' => $flow->sku,
                ],
            ];
        });
    }

    /**
     * 4. Preguntas Pre-venta
     */
    protected function collectQuestionNotifications(): Collection
    {
        if (! Schema::hasTable('meli_questions')) {
            return collect();
        }

        $questions = MeliQuestion::query()
            ->where('status', 'UNANSWERED')
            ->orderByDesc('question_created_at')
            ->limit(35)
            ->get();

        return $questions->map(function (MeliQuestion $q) {
            $dt = $q->question_created_at ?? $q->created_at ?? now();

            return [
                'id' => "quest_{$q->id}",
                'type' => self::TYPE_QUESTION,
                'category' => self::CATEGORY_CUSTOMER,
                'severity' => self::SEVERITY_INFO,
                'title' => '❓ Pregunta Pre-venta Pendiente',
                'subtitle' => $q->item_title ?: "Publicación {$q->item_id}" . ($q->sku ? " • SKU: {$q->sku}" : ''),
                'message' => $q->text ?: 'El cliente tiene una duda sobre este producto.',
                'badge_text' => 'Sin Responder',
                'action_url' => '/meli/preguntas',
                'action_label' => 'Responder Pregunta',
                'time' => $dt->diffForHumans(),
                'timestamp' => $dt->timestamp,
                'meta' => [
                    'item_id' => $q->item_id,
                    'sku' => $q->sku,
                    'thumbnail' => $q->item_thumbnail,
                    'price' => $q->item_price,
                ],
            ];
        });
    }

    /**
     * 5. Stock Crítico / Agotados
     */
    protected function collectStockNotifications(): Collection
    {
        if (! Schema::hasTable('llantas')) {
            return collect();
        }

        $items = Llanta::query()
            ->where('stock', '<=', 4)
            ->orderBy('stock')
            ->limit(25)
            ->get(['id', 'sku', 'marca', 'descripcion', 'stock', 'costo', 'updated_at']);

        return $items->map(function (Llanta $llanta) {
            $isZero = (int) $llanta->stock <= 0;
            $dt = $llanta->updated_at ?? now();

            return [
                'id' => "stock_{$llanta->id}_{$llanta->stock}",
                'type' => self::TYPE_STOCK,
                'category' => self::CATEGORY_INVENTORY,
                'severity' => $isZero ? self::SEVERITY_DANGER : self::SEVERITY_WARNING,
                'title' => $isZero ? '📦 Producto Agotado (Stock 0)' : '⚠️ Stock Crítico (<= 4 pzas)',
                'subtitle' => "SKU: {$llanta->sku} • Marca: {$llanta->marca}",
                'message' => "Existencia actual: {$llanta->stock} piezas. " . ($llanta->descripcion ?: ''),
                'badge_text' => $isZero ? 'Agotado (0)' : "Quedan {$llanta->stock}",
                'action_url' => '/reabastecimiento/pronostico',
                'action_label' => 'Ver Reabastecimiento',
                'time' => $dt->diffForHumans(),
                'timestamp' => $dt->timestamp,
                'meta' => [
                    'sku' => $llanta->sku,
                    'stock' => (int) $llanta->stock,
                ],
            ];
        });
    }

    /**
     * 6. FULL Agotado con Stock en Bodega Local
     */
    protected function collectFullStockNotifications(): Collection
    {
        if (! Schema::hasTable('meli_full_stocks') || ! Schema::hasTable('llantas')) {
            return collect();
        }

        $outFull = MeliFullStock::query()
            ->where('full_available_quantity', '<=', 2)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->limit(20)
            ->get();

        if ($outFull->isEmpty()) {
            return collect();
        }

        $skus = $outFull->pluck('sku')->unique()->all();
        $localStocks = Llanta::whereIn('sku', $skus)->pluck('stock', 'sku')->all();

        return $outFull
            ->filter(fn ($f) => ($localStocks[$f->sku] ?? 0) > 0)
            ->map(function (MeliFullStock $f) use ($localStocks) {
                $local = (int) ($localStocks[$f->sku] ?? 0);
                $dt = $f->synced_at ?? $f->updated_at ?? now();

                return [
                    'id' => "full_{$f->id}_{$f->full_available_quantity}",
                    'type' => self::TYPE_FULL,
                    'category' => self::CATEGORY_INVENTORY,
                    'severity' => self::SEVERITY_WARNING,
                    'title' => '⚡ Stock Agotado en FULL (Tienes en Bodega Local)',
                    'subtitle' => "SKU: {$f->sku} • MLM: {$f->mlm}",
                    'message' => "En Mercado Libre FULL quedan {$f->full_available_quantity} pzas, pero en tu almacén local tienes {$local} pzas listas para enviar.",
                    'badge_text' => 'FULL Agotado',
                    'action_url' => '/meli/full/envios',
                    'action_label' => 'Preparar Envío FULL',
                    'time' => $dt->diffForHumans(),
                    'timestamp' => $dt->timestamp,
                    'meta' => [
                        'sku' => $f->sku,
                        'mlm' => $f->mlm,
                        'full_stock' => $f->full_available_quantity,
                        'local_stock' => $local,
                    ],
                ];
            });
    }

    /**
     * 7. Incidencias en Mesa de Empaque (AMS)
     */
    protected function collectIssueNotifications(): Collection
    {
        if (! Schema::hasTable('ams_product_issues')) {
            return collect();
        }

        $issues = AmsProductIssue::query()
            ->where('status', AmsProductIssue::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return $issues->map(function (AmsProductIssue $issue) {
            $dt = $issue->created_at ?? now();

            return [
                'id' => "issue_{$issue->id}",
                'type' => self::TYPE_ISSUE,
                'category' => self::CATEGORY_OPERATION,
                'severity' => self::SEVERITY_DANGER,
                'title' => '🚨 Incidencia en Mesa de Empaque',
                'subtitle' => "SKU: {$issue->sku} • Orden #{$issue->order_id}",
                'message' => "Tipo: {$issue->issue_type_label}. " . ($issue->notes ?: 'Reportado durante la preparación del paquete.'),
                'badge_text' => $issue->issue_type_label,
                'action_url' => '/ams/incidencias',
                'action_label' => 'Resolver Incidencia',
                'time' => $dt->diffForHumans(),
                'timestamp' => $dt->timestamp,
                'meta' => [
                    'sku' => $issue->sku,
                    'order_id' => $issue->order_id,
                    'issue_type' => $issue->issue_type,
                ],
            ];
        });
    }
}
