# Autopartes — Fase 7: publicador controlado para Mercado Libre México

Fecha de diseño y consulta documental: **25 de agosto de 2026**. Esta fase prepara un publicador de un solo artículo, deshabilitado por defecto y separado de los publicadores históricos del proyecto. Durante su desarrollo no se hicieron solicitudes reales a Mercado Libre ni se creó, modificó, pausó o eliminó ninguna publicación.

## Arquitectura y límites

El flujo tiene tres modos que no se mezclan:

1. **Preview local:** construye y valida el payload en memoria. `--dry-run` no persiste ni usa HTTP.
2. **Validación remota:** únicamente `POST /items/validate`, con activación propia. No crea artículos.
3. **Publicación live:** `POST /items`, con activación adicional, validación remota vigente y aprobación humana final exacta.

La cuenta se recibe como un `MeliAccount` explícito. El servicio nunca selecciona la cuenta predeterminada ni cae al token legado. Reutiliza la renovación OAuth existente solo para la cuenta elegida. Los tokens no se copian a las tablas de Fase 7 ni a eventos, intentos, respuestas o logs.

El cliente fija `https://api.mercadolibre.com` en código, conserva la verificación TLS y aplica esta allowlist:

| Método | Ruta | Uso |
|---|---|---|
| POST | `/pictures/items/upload` | Subir un medio privado aprobado |
| POST | `/items/validate` | Validar sin publicar |
| POST | `/items` | Crear exactamente un artículo |
| POST | `/items/{MLM…}/description` | Crear la descripción separada |
| GET | `/items/{MLM…}` | Reconciliar un resultado ambiguo |
| GET | `/items/{MLM…}/description` | Comprobar antes de reintentar la descripción |

PUT, DELETE, URLs absolutas, rutas desconocidas, preguntas, órdenes, pausa/cierre, cambios de precio/stock y escritura de compatibilidades se rechazan antes de construir una solicitud HTTP. No se usa `withoutVerifying()`.

## Persistencia

La migración `2026_08_25_000001_create_automotive_part_meli_publication_tables.php` crea:

- `automotive_part_meli_publications`: destino, fingerprints, snapshots, validación, aprobación, resultado del artículo, descripción, errores y pendiente de compatibilidad.
- `automotive_part_meli_publication_attempts`: una fila por operación/intento con request fingerprint y datos sanitizados. La combinación publicación/operación/número es única.
- `automotive_part_meli_picture_uploads`: resultado por medio privado. La combinación publicación/medio es única; un upload exitoso se puede reutilizar por cuenta y SHA-256.
- `automotive_part_meli_publication_events`: bitácora de decisiones y transiciones.

Existe un índice único por cuenta y borrador aprobado y otro para `meli_item_id`. Las respuestas persistidas tienen tamaño máximo y todo campo con nombres de token, Authorization, password o secret se reemplaza por `[REDACTED]`.

## Estados y transiciones

Estados: `draft`, `local_invalid`, `local_valid`, `uploading_pictures`, `validating`, `validation_failed`, `validated`, `final_approved`, `queued`, `publishing`, `item_created`, `description_pending`, `published`, `published_pending_compatibility`, `partial_failure`, `reconciliation_required`, `failed`, `cancelled` y `stale`.

Las transiciones están declaradas en una máquina de estados; no se permite asignar arbitrariamente un siguiente estado. El camino normal es:

`draft → local_valid → uploading_pictures → local_valid → validating → validated → final_approved → queued → publishing → item_created → description_pending → published`

Puede terminar en `published_pending_compatibility` cuando el snapshot contiene compatibilidades. Un error local produce `local_invalid`; una respuesta de validación 400, `validation_failed`; un corte ambiguo durante creación, `reconciliation_required`; una fuente modificada, `stale`.

## Preflight y payload determinista

El preflight exige borrador aprobado y vigente, fingerprint sin cambios, enriquecimiento y categoría aprobados, readiness `ready`, categoría MLM, precio MXN positivo, stock entero positivo, listing type y buying mode explícitos, atributos completos, cuenta explícita, al menos una imagen aprobada y una principal. También vuelve a leer cada archivo y verifica ruta privada, existencia, SHA-256 y MIME.

El payload inicial solo contiene `site_id`, `title`, `category_id`, `price`, `currency_id`, `available_quantity`, `buying_mode`, `listing_type_id`, `pictures`, `attributes` y, únicamente si se configuraron, `channels`. No contiene `exclusive_channel`, descripción, GTIN, garantía, envío, SKU, marca, catálogo ni compatibilidad inventados. `ITEM_CONDITION` procede de `prepared_attributes` y conserva `value_id`/`value_name` respaldado; no se deriva del antiguo campo `condition` del borrador.

La descripción viaja después como `{ "plain_text": "…" }`. El preflight rechaza HTML, Markdown, enlaces, correo y números que parezcan información de contacto; no transforma silenciosamente el texto.

Si cambian producto, stock, precio, medios, categoría, atributos, configuración o fingerprint, la publicación pasa a `stale`, se limpia la aprobación final y se conserva el historial. Un registro ya avanzado no se sobrescribe con otro payload.

## Imágenes privadas

La URL autenticada de preview nunca se envía como `source`. La operación separada lee bytes desde el disk configurado en la fila de medio, vuelve a comprobar aprobación/hash/MIME y hace multipart a `/pictures/items/upload`. Solo el `meli_picture_id` resultante aparece en validación/publicación. El orden mantiene primero la imagen principal y luego posición/ID.

Antes de subir se busca un resultado exitoso con la misma cuenta y SHA-256. Si existe, se reutiliza su ID sin HTTP. Si el archivo cambió, el upload se invalida y la publicación queda obsoleta.

## Validación y aprobación humana

La validación remota requiere los uploads completos y guarda payload, respuesta sanitizada, request ID, fecha y expiración. Una validación 204 pasa a `validated`; sus errores no activan publicación.

Después, un usuario autenticado debe escribir una nota y confirmar exactamente cuenta, título, precio, stock, categoría y los últimos ocho caracteres del fingerprint. Esta aprobación solo persiste datos locales y no llama HTTP. Regenerar/revalidar, expirar la validación o cambiar una fuente revoca la aprobación.

## Publicación, idempotencia y recuperación

El job `PublishAutomotivePartToMeliJob` usa la cola `autopartes-meli-publisher`, un producto por job, `tries=1`, timeout conservador, backoff con jitter y `WithoutOverlapping`. El servicio toma además un lock distribuido por cuenta y día y aplica el límite diario (uno por defecto).

Dentro de una transacción corta bloquea la publicación, vuelve a comprobar estado, fingerprint, expiración, aprobación, ausencia de `meli_item_id` e intentos ambiguos y reserva el intento. El HTTP ocurre fuera de la transacción. `POST /items` se ejecuta una sola vez. Al recibir un ID MLM válido, se persiste de inmediato antes de crear la descripción.

Un timeout/corte de conexión de creación se considera ambiguo: se registra el intento y pasa a `reconciliation_required`, sin retry. La reconciliación manual admite:

- `item_found`: requiere un ID MLM, consulta `GET /items/{id}`, guarda el item confirmado y continúa solo con descripción;
- `not_created`: registra la conclusión humana y termina en `failed`, sin volver a publicar automáticamente.

Si falla la descripción, el ID queda guardado y el estado permanece `description_pending`. Antes de reintentar se consulta el GET de descripción; si ya existe, se completa localmente; si devuelve 404, se repite únicamente el POST de descripción. Nunca se repite el POST del artículo para corregir esta falla.

401 y 403 no se reintentan. 429 solo puede reintentarse en operaciones seguras (GET y validación), respetando un `Retry-After` acotado; la creación nunca tiene retry automático.

## Compatibilidades

La Fase 7 no llama ningún endpoint de escritura de compatibilidades. Las compatibilidades preparadas permanecen en el snapshot. Tras crear artículo y descripción, el estado queda `published_pending_compatibility` y metadata contiene `compatibility_task=pending_no_write_phase_7`.

## Configuración

Las claves opcionales son:

```text
AUTOPARTES_MELI_PUBLISHER_ENABLED=false
AUTOPARTES_MELI_REMOTE_VALIDATION_ENABLED=false
AUTOPARTES_MELI_IMAGE_UPLOAD_ENABLED=false
AUTOPARTES_MELI_LIVE_ENABLED=false
AUTOPARTES_MELI_PUBLISHER_ACCOUNT_ID=
AUTOPARTES_MELI_LISTING_TYPE_ID=
AUTOPARTES_MELI_BUYING_MODE=buy_it_now
AUTOPARTES_MELI_PUBLISHER_CHANNELS_JSON=[]
AUTOPARTES_MELI_PUBLISHER_MAX_BATCH=1
AUTOPARTES_MELI_PUBLISHER_MAX_DAILY_ITEMS=1
AUTOPARTES_MELI_PUBLISHER_TIMEOUT=30
AUTOPARTES_MELI_VALIDATION_TTL_MINUTES=60
AUTOPARTES_MELI_PUBLISHER_RULES_VERSION=v1
```

No se añadieron a `.env` ni `.env.example`. No se asumen cuenta, listing type, canal, condición, garantía o envío. `buy_it_now` solo es el valor configurable propuesto; listing type y cuenta no tienen fallback.

## Comando, UI y worker

La bandeja autenticada vive en `/autopartes/mercado-libre/publicaciones`; abrirla o abrir un detalle solo consulta datos locales. Sus botones separan preflight, regeneración, imágenes, validación, aprobación/revocación, enqueue live, retry de descripción, reconciliación y cancelación previa.

```bash
php artisan autopartes:meli-publish --draft-id=123 --dry-run
php artisan autopartes:meli-publish --draft-id=123
php artisan autopartes:meli-publish --publication-id=45 --upload-images
php artisan autopartes:meli-publish --publication-id=45 --validate-only
php artisan autopartes:meli-publish --publication-id=45 --live
php artisan queue:work --queue=autopartes-meli-publisher
```

`--limit` debe ser 1. Los modos son excluyentes. `--live` solo acepta `--publication-id`. `--force` regenera localmente, pero no omite cuenta, fingerprint, validación, aprobación ni idempotencia.

## Despliegue, prueba autorizada y rollback

1. Respaldar BD y desplegar con todos los flags apagados.
2. Ejecutar la migración en el entorno previsto; no se ejecutó contra producción durante el desarrollo.
3. Validar dry-run con un borrador aprobado.
4. Configurar explícitamente una cuenta autorizada y listing type.
5. Activar primero publicador e imágenes; inspeccionar uploads.
6. Activar validación remota y confirmar que solo se recibe 204/respuesta de validación.
7. Revisar payload, cuenta, precio, stock, categoría, imágenes y fingerprint en UI.
8. Solo con autorización operativa, activar live, aprobar humanamente una fila y encolar exactamente una.
9. Vigilar eventos/intentos y detener el worker ante cualquier ambigüedad.

Para rollback operativo, detener `autopartes-meli-publisher` y apagar los cuatro flags. Esto no toca artículos remotos. El rollback de esquema debe hacerse solo después de respaldar/exportar auditoría y confirmar que no se necesita reconciliar ningún item, pues `migrate:rollback` elimina las cuatro tablas locales.

Riesgos residuales: cambios contractuales de Mercado Libre, expiración/alcance del token, respuesta ambigua de red, reglas por categoría/listing type, moderación posterior e intervención manual para compatibilidades. La implementación deliberadamente no corrige ni actualiza un artículo ya creado.

## Documentación oficial consultada

- [Validador de publicaciones (`POST /items/validate`)](https://developers.mercadolibre.com.mx/en_us/manage-questions-answers/listing-validator), consultado el 25-08-2026; la página indica que no existe sandbox/preproducción para esta validación.
- [Publicar productos (`POST /items` y atributos de condición)](https://developers.mercadolibre.com.mx/es_mx/publica-productos), consultado el 25-08-2026.
- [Trabajo con imágenes (`POST /pictures/items/upload`)](https://developers.mercadolibre.com.mx/en_us/genexus/working-with-pictures), consultado el 25-08-2026.
- [Descripción de ítems (`GET/POST /items/{id}/description`)](https://developers.mercadolibre.com.mx/en_us/item-description-2), consultado el 25-08-2026.
- [Crear ítem y agregar descripción posteriormente](https://developers.mercadolibre.com.mx/en_us/tools/list-products), consultado el 25-08-2026.
- [Ítems y búsquedas (`GET /items/{id}`)](https://developers.mercadolibre.com.mx/es_mx/items-y-busquedas), consultado el 25-08-2026.
