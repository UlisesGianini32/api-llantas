# Autopartes — Fase 6: medios respaldados y precio MXN

## Alcance y arquitectura

Esta fase administra archivos de imagen privados y cálculos internos de precio. No publica artículos, no actualiza stock o precio externo, no escribe compatibilidades y no usa HTTP, cURL, Guzzle, OpenAI ni clientes de Mercado Libre. El guard local solo permite operaciones internas enumeradas.

La integración está deshabilitada por defecto. Los controladores autenticados delegan en servicios transaccionales; los comandos solo inspeccionan localmente o encolan en `autopartes-media-pricing`. Los borradores de Fase 5 leen primero fuentes aprobadas de base de datos.

## Tablas

- `automotive_part_media`: archivo, disco y ruta interna, nombre original/seguro, MIME real, extensión, tamaño, dimensiones, SHA-256, orden, principal, procedencia, estado, usuarios, fechas y metadata.
- `automotive_part_media_events`: historial inmutable de carga, aprobación, rechazo, archivo, principal y orden.
- `automotive_part_price_rules`: reglas versionadas mediante `rule_key` + `version`, scope, fórmula, vigencia, estado y aprobación.
- `automotive_part_price_rule_events`: auditoría de cambios de estado y versiones.
- `automotive_part_price_calculations`: entrada, regla, desglose, resultado, estado y fingerprint determinista. La combinación autoparte/fingerprint es única.

## Seguridad y respaldo de imágenes

Solo se aceptan JPEG, PNG y WebP. El servicio usa `finfo` y `getimagesize` sobre el contenido real; no confía en extensión o MIME del cliente. Rechaza SVG, GIF, PDF, ejecutables, contenido falso, archivos vacíos, exceso de tamaño o dimensiones y nombres con rutas, `..` o bytes nulos. La extensión se deriva del MIME detectado y el nombre almacenado es aleatorio.

Los archivos viven en el disco privado configurable, bajo `autopartes/media/{automotive_part_id}/`; ninguna ruta enviada por el usuario participa en el destino. El SHA-256 impide duplicar el mismo archivo para una autoparte. Los previews se sirven exclusivamente por una ruta autenticada que parte del registro, limita los MIME permitidos y agrega `nosniff`; no existe enlace público ni acceso a rutas arbitrarias.

Rechazar o archivar no borra físicamente. Los archivos y la base de datos deben respaldarse juntos: incluir el directorio raíz del disco configurado y las cinco tablas de Fase 6 en el mismo punto de recuperación. Verificar hashes después de restaurar con `autopartes:media-audit --dry-run`.

Procedencias cerradas: `user_upload`, `supplier_file`, `manufacturer_file`, `owned_photo`. La advertencia legal visible es: “La imagen debe ser propia o estar autorizada”. La persona operadora debe conservar licencias o autorizaciones en referencia/notas; la aplicación no determina derechos de autor.

Estados de medios: `pending`, `approved`, `rejected`, `archived`. Solo `approved` alimenta borradores y solo puede existir una principal aprobada por autoparte; el servicio lo garantiza dentro de una transacción.

## Reglas y fórmula de precio

Estados: `draft`, `active`, `inactive`, `superseded`. Solo una regla `active` con `approved_at` puede aplicarse. Una regla activa es inmutable: se crea una nueva versión borrador y, al activarla, la anterior queda `superseded`. Se impiden periodos activos traslapados en el mismo scope.

Precedencia determinista:

1. `automotive_part`
2. `vendor`
3. `category`
4. `global`

Para esta fase, origen y destino son exclusivamente USD/MXN. A partir de `retail_price_original`:

```text
source_price_mxn = retail_price_original × usd_mxn_rate
subtotal = source_price_mxn × (1 + markup_percent / 100) + fixed_cost_mxn
sale_price_before_rounding = subtotal / (1 - meli_fee_percent / 100)
rounded_price = aplicar rounding_mode y rounding_increment
final_price_mxn = aplicar minimum_price_mxn y después maximum_price_mxn
```

Modos: `none` conserva el valor antes de redondeo; `nearest` usa el múltiplo más cercano; `up` el siguiente múltiplo; `down` el anterior. El incremento siempre debe ser positivo. El resultado persistido se expresa con dos decimales. El mínimo/máximo se aplica después del redondeo. No se agregan impuestos, envío u otros conceptos implícitos.

Se valida tasa positiva, markup entre 0 y el máximo conservador, fee `>= 0` y menor al límite interno conservador de 95% (por tanto siempre `< 100`), costo fijo no negativo, incremento positivo, mínimo no mayor al máximo, resultado positivo y monedas USD/MXN. El límite evita denominadores cercanos a cero.

El fingerprint incluye autoparte, precio original, moneda, ID/clave/versión de regla, fecha de la versión y desglose completo. Una entrada idéntica reutiliza el cálculo existente; un cálculo nuevo vuelve obsoleto el válido anterior.

## Integración con borradores

Las imágenes aprobadas de base de datos y el último cálculo válido cuya regla aún esté activa tienen prioridad. El snapshot conserva IDs, versión de regla, IDs de cálculo/medios y fingerprints SHA-256. Si hay registros de medios pero ninguno aprobado, se mantiene `missing_images`. Si una regla no tiene cálculo vigente, se mantienen `missing_exchange_rate` y `missing_price_mxn`.

Los mapas de imágenes por `source_key` y el precio de Fase 5 continúan únicamente como fallback explícito mediante `allow_phase5_image_fallback` y `allow_phase5_price_fallback`. También preservan compatibilidad cuando aún no existe la migración de Fase 6. La fuente queda marcada en el snapshot; la base de datos gana cuando existe cualquier medio o regla candidata, incluso si todavía está pendiente, inactiva o sin cálculo válido.

Aprobar/rechazar/archivar/reordenar medios, cambiar la principal, activar/desactivar/reemplazar reglas o crear un cálculo distinto marca borradores afectados como `stale` y agrega un evento. Un borrador aprobado no se regenera ni sobrescribe: su snapshot, `approved_at` e historial permanecen en la versión obsoleta.

## Interfaz y comandos

- `/autopartes/medios` y `/autopartes/medios/{automotivePart}`: búsqueda, carga, previews privadas, estados, principal, procedencia, metadata e historial.
- `/autopartes/precios` y `/autopartes/precios/reglas/{rule}`: reglas/versiones, fórmula, aprobación, desactivación, reemplazo, cálculos e historial.

Advertencias visibles:

- “La imagen debe ser propia o estar autorizada”.
- “Este precio es un cálculo interno y no modifica Mercado Libre”.
- “Activar una regla no publica ni cambia precios externos”.

Comandos:

```bash
php artisan autopartes:media-audit --part-id=1 --limit=10 --dry-run
php artisan autopartes:prices-calculate --part-id=1 --rule-id=2 --limit=10 --dry-run
```

Ambos admiten `--part-id`, `--rule-id`, `--limit`, `--force` y `--dry-run`. El dry-run no persiste, mueve, descarga ni encola archivos y no realiza llamadas externas; muestra hash/fingerprint, regla, precio previsto y errores. Sin `--dry-run`, precios encola trabajos en `autopartes-media-pricing` y respeta el lote máximo.

## Configuración opcional

No se agregan valores a `.env`; los nombres aceptados son:

```text
AUTOPARTES_MEDIA_PRICING_ENABLED=false
AUTOPARTES_MEDIA_DISK=local
AUTOPARTES_MEDIA_MAX_FILE_KB=5120
AUTOPARTES_MEDIA_MAX_WIDTH=4096
AUTOPARTES_MEDIA_MAX_HEIGHT=4096
AUTOPARTES_MEDIA_MAX_IMAGES_PER_PART=10
AUTOPARTES_PRICE_MAX_BATCH=25
```

Los límites son internos y conservadores; no se presentan como requisitos actuales de Mercado Libre, por lo que no fue necesario consultar ni integrar sus APIs.

## Despliegue

1. Respaldar base de datos y el disco privado seleccionado.
2. Desplegar código con la integración todavía deshabilitada.
3. Ejecutar la migración en el entorno correcto mediante el proceso normal del proyecto; nunca contra producción desde una estación de desarrollo.
4. Confirmar permisos de escritura/lectura del disco privado y ausencia de URL pública.
5. Ejecutar pruebas, `route:list`, auditoría dry-run y un cálculo dry-run.
6. Configurar límites y disco en el gestor seguro del entorno.
7. Habilitar Fase 6, cargar medios autorizados, aprobar reglas y calcular lotes pequeños.
8. Revisar manualmente borradores obsoletos; la fase no publica ni modifica Mercado Libre.
