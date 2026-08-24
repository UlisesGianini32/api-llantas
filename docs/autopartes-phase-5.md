# Autopartes — Fase 5: borradores internos de Mercado Libre

## Alcance

La Fase 5 construye, valida, versiona y somete a aprobación humana borradores internos de publicaciones de Autopartes. No publica, modifica ni elimina artículos en Mercado Libre; no cambia stock o precio remoto; no carga imágenes ni compatibilidades; no usa OpenAI y no realiza solicitudes HTTP externas.

La integración inicia deshabilitada. Todos los datos proceden de las tablas de Fases 1 a 4 y de configuración explícita.

## Arquitectura

1. `AutomotivePartDraftBuilder` carga producto, enriquecimiento, categoría aprobada, snapshot oficial local, requisitos de atributos y readiness.
2. `AutomotivePartDraftPriceCalculator` calcula un precio local solo cuando existe configuración válida.
3. `AutomotivePartDraftValidator` devuelve errores bloqueantes y advertencias estructuradas.
4. `AutomotivePartDraftFingerprint` canonicaliza snapshot y payload y genera SHA-256.
5. `AutomotivePartDraftGenerator` devuelve la versión existente cuando el fingerprint no cambió o crea una versión nueva y marca las anteriores `stale`.
6. `GenerateAutomotivePartMeliDraftJob` procesa un producto en `autopartes-meli-drafts` y comprueba nuevamente el fingerprint.
7. `AutomotivePartDraftReviewService` registra aprobación, rechazo y retorno a revisión sin clientes HTTP.
8. `automotive_part_meli_draft_events` conserva todas las transiciones humanas y automáticas.
9. `AutomotivePartDraftLocalOnlyGuard` rechaza cualquier operación distinta de generar, regenerar o revisar localmente.

No se mantienen transacciones durante trabajo externo porque esta fase no tiene trabajo externo. Las transacciones solo protegen versionado y decisiones locales.

## Persistencia

La migración `2026_08_24_000001_create_automotive_part_meli_drafts_tables.php` crea:

- `automotive_part_meli_drafts`: versiones completas con categoría, contenido, precio, stock, moneda, condición, atributos, compatibilidades, imágenes, snapshot, fingerprint, validación y revisión.
- `automotive_part_meli_draft_events`: bitácora de generación, cambio de fuente, aprobación, rechazo y retorno a revisión.

Restricciones únicas:

- `automotive_part_id + fingerprint`, para impedir duplicados;
- `automotive_part_id + version`, para mantener una secuencia estable.

El borrador nunca se guarda en `automotive_parts`.

## Estados

- `draft`: reservado para una construcción local todavía no validada.
- `incomplete`: tiene uno o más errores bloqueantes.
- `pending_review`: cumple la validación previa y espera decisión humana.
- `approved`: aprobado internamente; no publicado.
- `rejected`: rechazado con nota obligatoria.
- `stale`: los datos o reglas ya no coinciden con su fingerprint.

Solo `pending_review` puede aprobarse. Volver a pendiente recalcula la validación: el resultado es `pending_review` o `incomplete`. Una versión aprobada que queda obsoleta conserva usuario, nota, fechas y eventos.

## Fuentes permitidas

El payload se construye exclusivamente con:

- `automotive_parts`;
- `automotive_part_enrichment_reviews` aprobada;
- candidato MLM aprobado por una persona;
- snapshot local de categoría y requisitos sincronizados en Fase 4;
- `automotive_part_meli_readiness` confirmado como `ready`;
- configuración explícita de precio, condición e imágenes.

No se infieren GTIN, marca, fabricante, número de parte, material, posición, lado, garantía, país, modelos, años, condición ni IDs. Los atributos son una copia filtrada de las propuestas respaldadas de readiness. Las compatibilidades solo proceden de una revisión de enriquecimiento aprobada. Las imágenes solo se aceptan desde una lista HTTPS configurada para el `source_key` exacto.

## Validación

Cada issue conserva `code`, `field`, mensaje seguro y metadata. Códigos bloqueantes implementados:

- `missing_approved_enrichment`
- `missing_approved_category`
- `stale_category_mapping`
- `missing_price_mxn`
- `missing_exchange_rate`
- `invalid_price_configuration`
- `invalid_stock`
- `missing_images`
- `missing_required_attribute`, con `attribute_id` y nombre
- `missing_compatibility`
- `invalid_title`
- `invalid_description`
- `unsupported_currency`
- `unsupported_condition`
- `readiness_not_ready`
- `stale_source_data`

Las advertencias incluyen atributos condicionales faltantes y warnings heredados de readiness. Una categoría se considera obsoleta si no existe el snapshot, no coincide con el candidato/readiness, no tiene fechas de categoría y atributos sincronizados o `listing_allowed` es `false`.

Los límites predeterminados son 10–60 caracteres para título y 40–50,000 para descripción. El stock debe ser entero y mayor o igual a cero. La condición debe configurarse expresamente como `new` o `used`.

## Precio

Variables opcionales:

- `AUTOPARTES_DRAFTS_ENABLED=false`
- `AUTOPARTES_USD_MXN_RATE`
- `AUTOPARTES_PRICE_MARKUP=0`
- `AUTOPARTES_MELI_FEE_PERCENT=0`
- `AUTOPARTES_DRAFT_MAX_BATCH=10`
- `AUTOPARTES_DRAFT_CONDITION`
- `AUTOPARTES_DRAFT_IMAGES_JSON`

No se modifica `.env` ni `.env.example`; la configuración operativa debe gestionarse fuera del repositorio.

Para un precio USD, la fórmula explícita es:

```text
base_mxn = precio_usd × tasa_usd_mxn
precio_mxn = redondear(base_mxn × (1 + markup / 100) / (1 - fee / 100), 2)
```

La tasa debe ser mayor que cero, el markup no puede ser negativo y la comisión debe estar entre 0 y menos de 100. Sin tasa válida se registran `missing_exchange_rate` y `missing_price_mxn`. No existe fallback ni consulta externa de tipo de cambio. El snapshot conserva precio original, tasa, markup y comisión utilizados.

`AUTOPARTES_DRAFT_IMAGES_JSON` admite un objeto JSON cuyas claves sean `source_key` exactos y cuyos valores sean listas de URLs HTTPS respaldadas. La aplicación no abre ni descarga esas URLs.

## Fingerprint y versionado

El fingerprint canonicaliza recursivamente y serializa con Unicode, slashes y fracciones preservadas. Incluye:

- campos relevantes y `updated_at` del producto;
- contenido y decisión del enriquecimiento;
- candidato de categoría y decisión humana;
- categoría, path, settings y fechas de sincronización;
- requisitos y valores preparados;
- readiness, compatibilidad y advertencias;
- precio, stock, imágenes, condición y reglas configuradas.

Regenerar sin cambios devuelve el mismo registro, incluso con `--force`, y conserva estado, usuario y notas. Si el fingerprint cambia, las versiones anteriores activas pasan a `stale` y se crea la siguiente versión. Un intento de aprobar un fingerprint viejo persiste `stale_source_data` antes de devolver el error.

## Operación

Dry-run local, permitido aunque la integración esté deshabilitada:

```bash
php artisan autopartes:drafts-generate --part-id=123 --limit=1 --dry-run
```

El dry-run muestra elegibilidad, estado previsto, fingerprint y códigos; no persiste, no encola y no usa HTTP.

Encolar generación o regeneración:

```bash
php artisan autopartes:drafts-generate --part-id=123 --limit=1
php artisan autopartes:drafts-generate --draft-id=456 --limit=1 --force
php artisan queue:work --queue=autopartes-meli-drafts
```

El límite nunca puede superar `AUTOPARTES_DRAFT_MAX_BATCH`.

La interfaz autenticada está en `/autopartes/mercado-libre/borradores`. Permite buscar, filtrar, generar, regenerar, aprobar, rechazar, regresar a revisión y consultar todas las versiones y eventos. Todas las pantallas muestran: “Borrador interno: todavía no publicado en Mercado Libre”. No existe botón de publicación.

## Seguridad

La Fase 5 no importa ni inyecta `MercadoLibreCatalogMetadataClient`, OAuth, OpenAI ni Laravel HTTP Client. Generar, validar y aprobar son operaciones de base de datos/cola locales. Las pruebas usan `Http::preventStrayRequests()` y verifican `Http::assertNothingSent()`.

No hay código para:

- `POST`, `PUT` o `DELETE /items`;
- cambios remotos de stock o precio;
- publicación de imágenes;
- escritura de compatibilidades;
- scraping o consultas de tipo de cambio.

## Riesgos y conexión futura

Antes de una fase futura de publicación real se deberá diseñar un componente separado, deshabilitado por defecto, con autorización adicional, idempotency key remota, validación de términos de Mercado Libre, actualización fresca de categoría/atributos, confirmación explícita del usuario, límites, dry-run, auditoría y rollback. Ese componente deberá consumir únicamente borradores `approved`, recalcular el fingerprint inmediatamente antes de publicar y detenerse ante cualquier cambio.

La aprobación de esta fase no autoriza ese proceso futuro ni constituye una publicación.

## Despliegue y rollback

1. Mantener `AUTOPARTES_DRAFTS_ENABLED=false`.
2. Aplicar la migración nueva únicamente en un entorno controlado.
3. Ejecutar un dry-run de un producto.
4. Configurar manualmente tasa, condición e imágenes respaldadas.
5. Habilitar generación local.
6. Generar una autoparte y revisar snapshot/errores.
7. Procesar un lote máximo de 10.
8. Auditar versiones y decisiones antes de ampliar.

Rollback: deshabilitar la integración, detener `autopartes-meli-drafts`, retirar rutas/servicios/UI y revertir solo la migración de Fase 5 si existe autorización para eliminar borradores e historial. Ningún paso requiere tocar Mercado Libre, `automotive_parts` o las tablas de Fases anteriores.
