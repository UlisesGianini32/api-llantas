# Autopartes — Fase 4: categorías y atributos de Mercado Libre México

## Alcance

Esta fase prepara las 3,893 autopartes para una publicación futura mediante candidatos de categoría, snapshots oficiales, requisitos de atributos, mapeo determinístico y revisión humana. No modifica `automotive_parts`, no usa OpenAI, no crea compatibilidades y no publica ni modifica artículos, stock o precios.

La integración inicia deshabilitada y opera exclusivamente sobre el sitio México (`MLM`).

## Documentación y endpoints oficiales

Documentación verificada el 22 de agosto de 2026:

- [Dominios y categorías](https://developers.mercadolibre.com.mx/es_ar/dominios-y-categorias)
- [Categorización de productos](https://developers.mercadolibre.com.mx/es_ar/categoriza-productos)
- [Atributos](https://developers.mercadolibre.com.mx/es_ar/atributos)
- [Referencias de dominios, productos y atributos para autopartes](https://developers.mercadolibre.com.mx/referencias-de-dominios-productos-y-atributos-para-autopartes)
- [Compatibilidades entre ítems y productos](https://developers.mercadolibre.com.mx/es_ar/como-empezar/compatibilidades-entre-items-y-productos)

El cliente de esta fase admite únicamente `GET` sobre paths configurados y comprobados contra una allowlist:

- `/sites/{site_id}/categories`
- `/sites/{site_id}/domain_discovery/search`
- `/categories/{category_id}`
- `/categories/{category_id}/attributes`
- `/catalog_domains/{domain_id}`
- `/catalog_compatibilities/restrictions/values`

El descubrimiento de dominios continúa documentado y limita el resultado oficial a ocho elementos; esta implementación usa el menor límite configurado, con cinco candidatos por defecto. No usa un predictor antiguo ni scraping.

El endpoint `POST /catalog_compatibilities/products_search/chunks` dejó de estar disponible el 15 de julio de 2026. Se descartó, al igual que todos los endpoints de escritura de compatibilidades. El dominio de referencia para compatibilidad de autos y camionetas en México es `MLM-CARS_AND_VANS_FOR_COMPATIBILITIES`, pero esta fase solo conserva metadatos y advertencias; no carga fitments.

## Arquitectura

1. Un comando o acción autenticada genera un preview local y encola un job por producto.
2. `AutomotivePartMeliCategorySuggestionService` elige la mejor consulta disponible y obtiene como máximo el número configurado de candidatos.
3. `MercadoLibreCatalogMetadataClient` valida configuración y token OAuth existente, aplica allowlist, presupuesto diario, caché y reintentos.
4. Los candidatos permanecen `pending` hasta una decisión humana.
5. Al aprobar, se vuelve a confirmar la categoría oficial, se sincronizan sus atributos y se marcan los demás candidatos activos como `superseded`.
6. `AutomotivePartMeliAttributeMapper` propone únicamente valores respaldados por campos canónicos.
7. `AutomotivePartMeliReadinessService` registra faltantes, advertencias y el estado de preparación.
8. Una segunda confirmación humana es indispensable para llegar a `ready`.

Las llamadas HTTP ocurren fuera de transacciones. Las transacciones se reservan para cambios locales de aprobación.

## Configuración

`config/autopartes_meli.php` define:

- `AUTOPARTES_MELI_ENABLED=false`
- `AUTOPARTES_MELI_SITE_ID=MLM`
- `AUTOPARTES_MELI_BASE_URL=https://api.mercadolibre.com`
- `AUTOPARTES_MELI_TIMEOUT=20`
- `AUTOPARTES_MELI_CACHE_TTL=86400`
- `AUTOPARTES_MELI_MAX_BATCH=10`
- `AUTOPARTES_MELI_MAX_DAILY_REQUESTS=100`
- `AUTOPARTES_MELI_MAX_CANDIDATES=5`

Los templates de endpoints, `rules_version` y las reglas determinísticas también están centralizados allí. No se agregan variables a `.env` ni `.env.example` en esta fase.

La URL base debe ser HTTPS y usar exactamente `api.mercadolibre.com`. El cliente reutiliza el token vigente de `MeliAccount` —o la compatibilidad OAuth existente en `User`— sin copiarlo a tablas nuevas, logs o frontend. El dry-run no requiere integración habilitada ni token.

## Persistencia

La migración nueva crea cuatro tablas:

- `automotive_part_meli_categories`: snapshot de categoría, dominio, path, settings, payload crudo y fechas de sincronización.
- `automotive_part_meli_category_candidates`: candidato, origen, consulta, posición, score opcional, evidencia, payload y decisión humana.
- `automotive_part_meli_attribute_requirements`: requisitos normalizados y payload crudo por categoría y atributo.
- `automotive_part_meli_readiness`: categoría aprobada, propuestas, faltantes, compatibilidad, advertencias y confirmación final.

`automotive_parts` no recibe columnas nuevas ni valores de Mercado Libre. Los snapshots son idempotentes por sitio/categoría y los requisitos por categoría/atributo. Un refresco explícito reemplaza el snapshot corriente, pero no elimina categorías referenciadas por candidatos.

## Caché, límites y errores

La caché usa endpoint y parámetros ordenados como clave. El TTL predeterminado es de 24 horas; `--refresh` o `--refresh-metadata` fuerza una consulta. El presupuesto diario cuenta solicitudes HTTP reales, incluidos reintentos, no cache hits.

Solo se reintentan errores de conexión, HTTP 408, 429 y 5xx, con hasta tres intentos, backoff exponencial con jitter y respeto de `Retry-After`. 400, 401 y 403 no se reintentan. Los mensajes se sanitizan y los logs contienen solo path permitido, estado, request ID y código seguro; nunca token, Authorization ni respuestas completas.

## Candidatos y reglas determinísticas

La consulta se elige en este orden:

1. propuesta manual aprobada en español;
2. propuesta completa de enriquecimiento;
3. título determinístico de reglas;
4. descripción original;
5. categoría/subcategoría internas.

Marca, modelo prevalente y número de parte se agregan solo como contexto. No se envían precio, stock, notas privadas ni credenciales.

Las reglas internas están en `deterministic_rules`. El repositorio no incluye IDs predeterminados: una regla debe indicar categoría interna, subcategoría opcional y un `category_id` previamente confirmado por la API. Aun así, el servicio vuelve a validar ese ID y solo crea un candidato `pending`. Ni una regla ni un único resultado de Mercado Libre producen aprobación automática.

Las fuentes persistidas son `deterministic`, `domain_discovery`, `category_predictor` (reservada para un recurso oficial futuro) y `manual`. La posición y el score son opcionales y nunca se fabrican.

## Atributos

El mapeo es estricto y auditable. Cada propuesta guarda `attribute_id`, `value`, `value_id`, unidad, `source_field`, transformación, confianza determinística y advertencias.

Reglas implementadas:

- `SELLER_SKU` puede usar `item_number`.
- `MPN`, `PART_NUMBER` y `MANUFACTURER_PART_NUMBER` pueden usar el número de parte del fabricante.
- `BRAND` usa `vendor`; si hay valores permitidos, exige coincidencia exacta normalizada y conserva el `value_id` oficial.
- dimensiones y peso solo se asignan cuando el nombre oficial identifica explícitamente dimensiones o peso del producto/pieza; se conservan `cm` y `kg`.
- dimensiones de paquete o embalaje nunca se rellenan con dimensiones de la pieza.
- `GTIN`, `EAN` y `UPC` nunca se infieren; `item_number` no se usa como GTIN.
- modelos, años y compatibilidades estructuradas no se derivan de texto ambiguo.
- no hay fuzzy matching de valores permitidos, afirmaciones OEM, extensión de años ni normalización inventada de marca.

Los flags `required`, `catalog_required` y `conditional_required` se guardan por separado. Un atributo condicional no se rellena sin evidencia y queda listado para revisión.

## Compatibilidad

Cuando la categoría corresponde a autopartes, se consulta el snapshot oficial del dominio y se conserva su payload junto con el texto de compatibilidad disponible en el origen. La implementación muestra ausencia o ambigüedad, pero nunca construye fitments.

La compatibilidad bloquea `ready` únicamente cuando los metadatos oficiales declaran expresamente que es obligatoria y no hay fuente suficiente. Si el requisito oficial es ambiguo, se registra una advertencia para revisión humana y no se inventa una conclusión.

## Estados y revisión humana

- `unmapped`: no hay candidatos aprobados ni pendientes.
- `category_pending`: existen candidatos, pero ninguno ha sido aprobado.
- `incomplete`: hay categoría aprobada y falta al menos un atributo obligatorio o una compatibilidad expresamente requerida.
- `ready_for_review`: categoría y obligatorios completos; espera confirmación humana final, incluidos condicionales y advertencias.
- `ready`: el mismo fingerprint de evaluación fue confirmado por una persona.

Si cambian categoría, atributos, faltantes, compatibilidad o advertencias, el fingerprint cambia y la confirmación previa se invalida. `ready` no publica nada.

El rechazo requiere nota. La selección manual valida el formato y existencia oficial, queda `pending` y conserva usuario/evidencia. Aprobar registra usuario, fecha y nota y supersede candidatos activos alternativos.

## Operación

Vista previa local de una autoparte, incluso con la integración deshabilitada:

```bash
php artisan autopartes:meli-map --part-id=123 --limit=1 --dry-run
```

Encolar un único producto:

```bash
php artisan autopartes:meli-map --part-id=123 --limit=1
php artisan queue:work --queue=autopartes-meli-mapping
```

Filtrar o refrescar:

```bash
php artisan autopartes:meli-map --review-id=456 --limit=1
php artisan autopartes:meli-map --internal-category=ROTORS --limit=10
php artisan autopartes:meli-map --part-id=123 --limit=1 --refresh-metadata
```

Sincronizar detalle y atributos de una categoría confirmada:

```bash
php artisan autopartes:meli-sync-category MLM123456
php artisan autopartes:meli-sync-category MLM123456 --refresh
```

`--force` permite volver a generar candidatos, pero no sustituye ni cambia una aprobación humana. La interfaz autenticada está bajo `/autopartes/mercado-libre/categorias`; las acciones con API tienen rate limiting y no existe botón de publicación.

## Protecciones de solo lectura

El cliente rechaza cualquier método distinto de GET antes de enviar la solicitud. La allowlist rechaza paths ajenos y bloquea expresamente recursos de artículos, publicaciones de vendedor, órdenes, mensajes, preguntas, stock y precios. Por diseño no hay código para `POST /items`, `PUT /items`, `DELETE /items`, publicación o actualización comercial.

## Despliegue gradual

1. Mantener `AUTOPARTES_MELI_ENABLED=false`.
2. Aplicar la migración nueva en un entorno controlado.
3. Ejecutar el dry-run de un producto.
4. Habilitar solo la consulta de metadatos con OAuth existente.
5. Sincronizar una categoría conocida.
6. Generar candidatos de un producto.
7. Revisar y aprobar manualmente.
8. Probar un lote máximo de 10.
9. Auditar candidatos, atributos, faltantes, warnings y consumo diario.
10. Ampliar únicamente con autorización.

## Rollback

1. Deshabilitar la integración y detener el worker `autopartes-meli-mapping`.
2. Retirar rutas, jobs, servicios y UI mediante el despliegue normal.
3. Si esta es la última migración aplicada y existe autorización para perder snapshots e historial, revertir únicamente `2026_08_22_000003_create_automotive_part_meli_mapping_tables.php`.

El rollback no toca `automotive_parts`, enriquecimiento, publicaciones existentes ni otros módulos.

## Fuera de alcance

Esta fase no publica, edita, pausa ni elimina artículos; no cambia stock o precio; no carga compatibilidades; no consulta HTML; no usa OpenAI; no decide categorías automáticamente; no modifica propuestas de enriquecimiento ni el catálogo canónico; y no introduce cambios en llantas, Syscom, AMS, Shopify, mensajería u órdenes.
