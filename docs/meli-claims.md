# Reclamos de Mercado Libre — Fase 1

## Alcance

El módulo sincroniza y muestra reclamos en modo de solo lectura. No envía mensajes ni ejecuta reembolsos, devoluciones, disputas, cierres u otras `available_actions`.

## Arquitectura y tablas

- `meli_claims`: estado consolidado por cuenta y claim. La clave única es `(meli_account_id, claim_id)` y `meli_order_id` enlaza únicamente el pedido local resuelto dentro de esa misma cuenta.
- `meli_claim_reasons`: caché local de nombres y detalles de motivos.
- Los historiales, resoluciones esperadas y acciones disponibles se guardan como JSON porque en esta fase solo se presentan como timeline/información.
- Los datos de producto se obtienen de `meli_orders` y `meli_order_items`; un pedido local ausente no impide guardar o mostrar el claim.
- `MeliAccountApiClient` conserva la única implementación de tokens, refresh, rate limits, reintentos y sanitización.

## Endpoints de lectura

La integración usa el namespace documentado de postventa y mapea respuestas de forma tolerante:

- `GET /post-purchase/v1/claims/search` acotado con `players.user_id` y `players.role=respondent`.
- `GET /post-purchase/v1/claims/{claim_id}`.
- `GET /post-purchase/v1/claims/{claim_id}/detail`.
- `GET /post-purchase/v1/claims/reasons/{reason_id}` para el detalle legible del motivo.
- `GET /post-purchase/v1/claims/{claim_id}/affects-reputation`.
- `GET /post-purchase/v1/claims/{claim_id}/status-history`.
- `GET /post-purchase/v1/claims/{claim_id}/actions-history`.
- `GET /post-purchase/v1/claims/{claim_id}/expected_resolutions`.
- `available_actions` se lee del jugador `respondent`/`seller` incluido en el claim.

Los dos últimos recursos se muestran únicamente como información. Mercado Libre puede restringir recursos según sitio, etapa o permisos; una respuesta opcional no disponible no borra el claim base.

## Sincronización

```bash
php artisan meli:sync-claims
php artisan meli:sync-claims --account=3 --days=30
php artisan meli:sync-claims --account=3 --status=open
php artisan meli:sync-claims --account=3 --claim=123456789 --force
```

El scheduler ejecuta un respaldo cada cinco minutos con `withoutOverlapping` y `runInBackground`. El botón de la bandeja realiza una sincronización de lectura para la cuenta seleccionada.

## Webhook

El webhook existente reconoce `claims`, `claims_actions` y `post_purchase`, acepta resources como `/post-purchase/v1/claims/{id}` y encola `SyncMeliClaimJob`. La cuenta se resuelve con el `user_id` vendedor de la notificación. Deben activarse los topics disponibles para la aplicación desde Mercado Libre Developer Center; el código no puede habilitarlos.

## Seguridad

Las pantallas solo consultan claims pertenecientes a cuentas del usuario autenticado. Los tokens permanecen ocultos y no se guardan payloads de comprador por separado. Los logs contienen IDs operativos y errores sanitizados, no tokens ni payloads completos.

## Validación real pendiente

Antes de producción debe contrastarse un claim real del sitio MLM para confirmar la forma exacta de `detail`, `affects-reputation`, historiales, `expected-resolutions` y `available-actions`, porque su disponibilidad y envoltura pueden variar por etapa/permisos. `raw_claim` y `raw_detail` permiten comparar sin perder campos opcionales.

## Fases posteriores (fuera de alcance)

Mensajes, adjuntos, reembolsos totales/parciales, autorización de devoluciones, disputas, cierre de claims, cambio de producto, IA y ejecución de cualquier acción económica o irreversible.
