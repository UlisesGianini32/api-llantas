<?php

namespace App\Http\Controllers;

use App\Models\AmsProductIssue;
use App\Models\MeliOrderItem;
use App\Models\MeliPublication;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class AmsProductIssueController extends Controller
{
    protected function timezone(): string
    {
        return (string) config('ams_colecta.business_timezone', env('AMS_COLECTA_TIMEZONE', 'America/Hermosillo'));
    }

    public function index(Request $request): Response
    {
        $status = (string) $request->input('status', 'pending');
        $issueType = (string) $request->input('issue_type', '');
        $search = trim((string) $request->input('search', ''));

        $query = AmsProductIssue::query()
            ->with([
                'reportedBy:id,name,email',
                'resolvedBy:id,name,email',
                'product:id,name,ml,sku,thumbnail,price',
                'publication:id,mlm,title,sku,permalink,raw',
            ]);

        if ($status === 'pending') {
            $query->where('status', AmsProductIssue::STATUS_PENDING);
        } elseif ($status === 'resolved') {
            $query->where('status', AmsProductIssue::STATUS_RESOLVED);
        } elseif ($status === 'ignored') {
            $query->where('status', AmsProductIssue::STATUS_IGNORED);
        }

        if ($issueType !== '' && in_array($issueType, array_keys(AmsProductIssue::issueTypeLabels()), true)) {
            $query->where('issue_type', $issueType);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('item_id', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhere('order_id', 'like', "%{$search}%");
            });
        }

        $issues = $query
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $tz = $this->timezone();

        $issues->getCollection()->transform(function (AmsProductIssue $issue) use ($tz) {
            $issue->issue_type_label = $issue->issue_type_label;
            $issue->created_at_formateada = $issue->created_at
                ? Carbon::parse($issue->created_at)->timezone($tz)->format('d/m/Y H:i')
                : null;
            $issue->resolved_at_formateada = $issue->resolved_at
                ? Carbon::parse($issue->resolved_at)->timezone($tz)->format('d/m/Y H:i')
                : null;

            // Thumbnail efectivo para comparar
            $currentEffectiveThumbnail = $issue->product?->thumbnail
                ?: $issue->current_image_url;

            if (!$currentEffectiveThumbnail && $issue->publication) {
                $raw = is_array($issue->publication->raw)
                    ? $issue->publication->raw
                    : json_decode($issue->publication->raw ?? '[]', true);

                $currentEffectiveThumbnail = $raw['pictures'][0]['secure_url']
                    ?? $raw['pictures'][0]['url']
                    ?? $raw['thumbnail']
                    ?? null;
            }

            $issue->effective_thumbnail = $currentEffectiveThumbnail;

            return $issue;
        });

        // Contadores rápidos para filtros
        $counts = [
            'total_pending' => AmsProductIssue::where('status', AmsProductIssue::STATUS_PENDING)->count(),
            'total_resolved' => AmsProductIssue::where('status', AmsProductIssue::STATUS_RESOLVED)->count(),
            'total_all' => AmsProductIssue::count(),
            'by_type' => [
                'sin_imagen' => AmsProductIssue::where('status', AmsProductIssue::STATUS_PENDING)->where('issue_type', 'sin_imagen')->count(),
                'imagen_incorrecta' => AmsProductIssue::where('status', AmsProductIssue::STATUS_PENDING)->where('issue_type', 'imagen_incorrecta')->count(),
                'sku_incorrecto' => AmsProductIssue::where('status', AmsProductIssue::STATUS_PENDING)->where('issue_type', 'sku_incorrecto')->count(),
                'titulo_incorrecto' => AmsProductIssue::where('status', AmsProductIssue::STATUS_PENDING)->where('issue_type', 'titulo_incorrecto')->count(),
                'otro' => AmsProductIssue::where('status', AmsProductIssue::STATUS_PENDING)->where('issue_type', 'otro')->count(),
            ],
        ];

        return Inertia::render('Ams/IncidenciasIndex', [
            'issues' => $issues,
            'counts' => $counts,
            'issueTypes' => AmsProductIssue::issueTypeLabels(),
            'filters' => [
                'status' => $status,
                'issue_type' => $issueType,
                'search' => $search,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_id' => ['required', 'string', 'max:64'],
            'issue_type' => ['required', 'string', 'in:sin_imagen,imagen_incorrecta,sku_incorrecto,titulo_incorrecto,otro'],
            'sku' => ['nullable', 'string', 'max:128'],
            'title' => ['nullable', 'string', 'max:500'],
            'current_image_url' => ['nullable', 'string', 'max:1000'],
            'order_id' => ['nullable', 'string', 'max:64'],
            'shipping_id' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $itemId = trim($validated['item_id']);
        $issueType = $validated['issue_type'];

        // Si ya hay una incidencia abierta para el mismo producto y tipo, actualizamos notas o devolvemos éxito
        $existing = AmsProductIssue::query()
            ->where('item_id', $itemId)
            ->where('issue_type', $issueType)
            ->where('status', AmsProductIssue::STATUS_PENDING)
            ->first();

        if ($existing) {
            if (!empty($validated['notes'])) {
                $existing->notes = trim(($existing->notes ? $existing->notes . ' | ' : '') . $validated['notes']);
                $existing->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Esta incidencia ya estaba reportada. Se agregaron las notas adicionales.',
                'issue' => $existing,
            ]);
        }

        $issue = AmsProductIssue::create([
            'item_id' => $itemId,
            'sku' => $validated['sku'] ?? null,
            'title' => $validated['title'] ?? null,
            'current_image_url' => $validated['current_image_url'] ?? null,
            'order_id' => $validated['order_id'] ?? null,
            'shipping_id' => $validated['shipping_id'] ?? null,
            'issue_type' => $issueType,
            'notes' => $validated['notes'] ?? null,
            'status' => AmsProductIssue::STATUS_PENDING,
            'reported_by_user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Incidencia reportada correctamente.',
            'issue' => $issue,
        ], 201);
    }

    public function update(Request $request, AmsProductIssue $issue): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'new_sku' => ['nullable', 'string', 'max:128'],
            'new_image_url' => ['nullable', 'string', 'max:1000'],
            'new_image_file' => ['nullable', 'image', 'max:5120'], // 5MB
            'new_title' => ['nullable', 'string', 'max:500'],
            'resolution_notes' => ['nullable', 'string', 'max:1000'],
            'mark_resolved' => ['nullable', 'boolean'],
        ]);

        $appliedChanges = [];
        $finalImageUrl = null;

        // 1. Manejo de imagen nueva
        if ($request->hasFile('new_image_file')) {
            $path = $request->file('new_image_file')->store('product-issues', 'public');
            $finalImageUrl = Storage::disk('public')->url($path);
        } elseif (!empty($validated['new_image_url'])) {
            $finalImageUrl = trim($validated['new_image_url']);
        }

        if ($finalImageUrl !== null && $finalImageUrl !== '') {
            // Actualizar producto local si existe
            $product = Product::query()
                ->where('ml', $issue->item_id)
                ->orWhere(function ($q) use ($issue) {
                    if (!empty($issue->sku)) {
                        $q->where('sku', $issue->sku);
                    }
                })
                ->first();

            if ($product) {
                $product->thumbnail = $finalImageUrl;
                $product->save();
            } else {
                Product::create([
                    'ml' => $issue->item_id,
                    'sku' => $validated['new_sku'] ?? $issue->sku ?? ('SKU-' . $issue->item_id),
                    'name' => $validated['new_title'] ?? $issue->title ?? 'Producto ' . $issue->item_id,
                    'thumbnail' => $finalImageUrl,
                    'status' => 'active',
                ]);
            }

            // Actualizar publicación ML si existe
            $publication = MeliPublication::where('mlm', $issue->item_id)->first();
            if ($publication) {
                $raw = is_array($publication->raw)
                    ? $publication->raw
                    : json_decode($publication->raw ?? '[]', true);

                $raw['thumbnail'] = $finalImageUrl;
                if (!isset($raw['pictures']) || !is_array($raw['pictures'])) {
                    $raw['pictures'] = [];
                }
                if (isset($raw['pictures'][0])) {
                    $raw['pictures'][0]['url'] = $finalImageUrl;
                    $raw['pictures'][0]['secure_url'] = $finalImageUrl;
                } else {
                    $raw['pictures'][] = [
                        'id' => 'custom',
                        'url' => $finalImageUrl,
                        'secure_url' => $finalImageUrl,
                    ];
                }
                $publication->raw = $raw;
                $publication->save();
            }

            $appliedChanges['image'] = $finalImageUrl;
            $issue->current_image_url = $finalImageUrl;
        }

        // 2. Manejo de SKU nuevo
        if (!empty($validated['new_sku'])) {
            $newSku = trim($validated['new_sku']);

            // Actualizar en productos
            Product::query()
                ->where('ml', $issue->item_id)
                ->orWhere('sku', $issue->sku)
                ->update(['sku' => $newSku]);

            // Actualizar en pedidos meli_order_items para que aparezca bien en los pedidos de AMS
            MeliOrderItem::query()
                ->where('item_id', $issue->item_id)
                ->update(['sku' => $newSku]);

            // Actualizar en meli_publications
            $pubUpdates = ['sku' => $newSku];
            if (\Illuminate\Support\Facades\Schema::hasColumn('meli_publications', 'seller_custom_field')) {
                $pubUpdates['seller_custom_field'] = $newSku;
            }

            MeliPublication::query()
                ->where('mlm', $issue->item_id)
                ->update($pubUpdates);

            $appliedChanges['sku'] = $newSku;
            $issue->sku = $newSku;
        }

        // 3. Manejo de título nuevo
        if (!empty($validated['new_title'])) {
            $newTitle = trim($validated['new_title']);

            Product::query()
                ->where('ml', $issue->item_id)
                ->orWhere('sku', $issue->sku)
                ->update(['name' => $newTitle]);

            MeliOrderItem::query()
                ->where('item_id', $issue->item_id)
                ->update(['title' => $newTitle]);

            // Actualizar en meli_publications
            $pubs = MeliPublication::where('mlm', $issue->item_id)->get();
            foreach ($pubs as $pubItem) {
                $raw = is_array($pubItem->raw) ? $pubItem->raw : (json_decode($pubItem->raw, true) ?: []);
                $raw['title'] = $newTitle;
                $pubItem->raw = $raw;
                if (\Illuminate\Support\Facades\Schema::hasColumn('meli_publications', 'title')) {
                    $pubItem->title = $newTitle;
                }
                $pubItem->save();
            }

            $appliedChanges['title'] = $newTitle;
            $issue->title = $newTitle;
        }

        // 4. Marcar como resuelto si se solicitó o si se aplicaron correcciones
        $markResolved = $request->boolean('mark_resolved', true);
        if ($markResolved) {
            $issue->status = AmsProductIssue::STATUS_RESOLVED;
            $issue->resolved_by_user_id = $request->user()?->id;
            $issue->resolved_at = now();
            $issue->resolution_notes = $validated['resolution_notes'] ?? 'Corrección aplicada en catálogo.';
        }

        $issue->applied_changes = array_merge($issue->applied_changes ?? [], $appliedChanges);
        $issue->save();

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Producto e incidencia actualizados correctamente.',
                'issue' => $issue,
            ]);
        }

        return redirect()->back()->with('success', 'Producto e incidencia actualizados correctamente.');
    }

    public function resolve(Request $request, AmsProductIssue $issue): JsonResponse|RedirectResponse
    {
        $notes = $request->input('notes', 'Marcado como resuelto manualmente.');

        $issue->update([
            'status' => AmsProductIssue::STATUS_RESOLVED,
            'resolved_by_user_id' => $request->user()?->id,
            'resolved_at' => now(),
            'resolution_notes' => $notes,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Incidencia marcada como resuelta.',
                'issue' => $issue,
            ]);
        }

        return redirect()->back()->with('success', 'Incidencia marcada como resuelta.');
    }

    public function reopen(Request $request, AmsProductIssue $issue): JsonResponse|RedirectResponse
    {
        $issue->update([
            'status' => AmsProductIssue::STATUS_PENDING,
            'resolved_by_user_id' => null,
            'resolved_at' => null,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Incidencia reabierta.',
                'issue' => $issue,
            ]);
        }

        return redirect()->back()->with('success', 'Incidencia reabierta.');
    }

    public function destroy(Request $request, AmsProductIssue $issue): JsonResponse|RedirectResponse
    {
        $issue->delete();

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Incidencia eliminada.',
            ]);
        }

        return redirect()->back()->with('success', 'Incidencia eliminada.');
    }
}
