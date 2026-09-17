# Reclamos: sincronización incremental y Telegram

## Entrega

Rama: `feature/meli-claims-incremental-telegram`.
Base comprobada: `rescue/production-2026-08-21`, commit `cf436e79b3d02cf9a9ae4b3af199a60f83130426`.
Título del PR: **feat: sincronización incremental y alertas Telegram para reclamos MeLi**.

### Antes

El servicio sin estado explícito recorría `opened` y `closed`. El comando y scheduler usaban 30 días; el botón también forzaba la lectura de detalles. Cada resultado disparaba las consultas del reclamo, detalle, reputación, historiales, resoluciones, mensajes y cambios.

### Ahora

- El webhook existente sigue encolando `SyncMeliClaimJob` en `meli` para actualizar un reclamo concreto.
- El respaldo y el botón buscan exclusivamente `opened`, sin rango de fechas y sin forzar detalles. La frecuencia sigue siendo cinco minutos.
- Se compara `last_updated` por cuenta y claim. Una fecha igual omite detalles; se conserva la precisión del timestamp remoto en `raw_claim`. Fechas ausentes/inválidas y errores previos fuerzan la lectura.
- Los abiertos con `telegram_notified_at IS NULL` intentan su aviso pendiente tanto al sincronizar detalles como al quedar `skipped`. El camino `skipped` no descarga recursos adicionales de MeLi.
- Tras completar todas las páginas, solamente los reclamos locales no `closed`/`resolved` ausentes del listado se consultan individualmente. No se descarga el historial de cerrados ni se borran registros.
- Una búsqueda fallida, inválida, sin total o truncada aborta antes de reconciliar. Las búsquedas explícitas con rango histórico no reconcilian ausencias.
- Se conservan `received`, `saved`, `failed` y se agregan `skipped`, `reconciled`. `saved` incluye las reconciliaciones exitosas; `received` cuenta resultados de búsqueda.
- Continúan disponibles `--claim`, `--force`, `--days` y `--status=closed` para operaciones explícitas.

## Archivos

| Archivo | Cambio |
| --- | --- |
| `app/Services/MercadoLibre/Claims/MeliClaimsService.php` | Comparación incremental, reconciliación, contadores y avisos pendientes |
| `app/Services/TelegramAlertService.php` | `notifyMeliNewClaim`, reserva atómica, mensaje acotado y errores sanitizados |
| `app/Models/MeliClaim.php` | Cast datetime de `telegram_notified_at` |
| `database/migrations/2026_09_15_000001_add_telegram_notified_at_to_meli_claims.php` | Columna nullable indexada y baseline limitado al máximo ID capturado antes de agregarla |
| `app/Console/Commands/SyncMeliClaimsCommand.php` | Defaults abiertos/sin rango y salida con contadores |
| `app/Http/Controllers/MeliClaimController.php` | Botón con `opened`, `0`, `false` y resumen |
| `routes/console.php` | Respaldo incremental, conservando sus protecciones y log |
| `tests/Feature/MeliClaimsTest.php` | 25 casos nuevos, incluyendo 7 correctivos, y actualización del caso del comportamiento anterior |
| `docs/meli-claims-incremental-telegram.md` | Reporte y operación |

## Telegram y concurrencia

Se reutilizan el bot y `TELEGRAM_BOT_TOKEN`, con `TELEGRAM_ALERT_CHAT_IDS` o su fallback `TELEGRAM_ALLOWED_CHAT_IDS`. No se agregan credenciales ni opciones de entorno. El mensaje contiene cuenta, claim, pedido, productos/SKU/cantidades locales, motivo, etapa, responsable, reputación, vencimiento y `route('meli.claims.show', $claim)`. No incorpora identificadores del comprador.

La integración invoca Telegram para cualquier reclamo abierto (`opened` o el alias local `open`) con `telegram_notified_at IS NULL`, aunque ya exista o su fecha remota no haya cambiado. Esto recupera avisos que salieron antes de reservar, por ejemplo por configuración ausente. La restricción única existente `(meli_account_id, claim_id)` evita dos inserciones concurrentes; `updateOrCreate` de Laravel recupera el registro ganador. Antes de enviar, el UPDATE atómico existente, condicionado por ID, estado abierto y `telegram_notified_at IS NULL`, reserva el aviso. Dos instancias del modelo, aunque estén desactualizadas, no pueden reservar el mismo registro. Un registro marcado no vuelve a invocar el notifier desde la sincronización.

El baseline captura `MAX(id)` antes de agregar la columna y marca únicamente `id <= baselineMaxId`. Una inserción posterior a esa captura conserva null, incluso si ocurre antes del UPDATE del baseline. Si la tabla estaba vacía, no se marca ningún registro. Los reclamos históricos marcados siguen excluidos de los avisos.

**Semántica: como máximo un intento por chat.** `telegram_notified_at` representa baseline o reserva de intento, no comprobante de recepción. Se marca antes del HTTP y no se limpia ante rechazo, timeout o entrega parcial. No hay reintentos automáticos que dupliquen un envío incierto. Los errores se registran sin cuerpo de respuesta, URL del bot ni mensaje de excepción. Una falla de Telegram no revierte el claim.

Limitaciones deliberadas:

- Puede perderse una alerta si el proceso muere después de reservar o si Telegram falla. Las siguientes sincronizaciones no reenvían una entrega reservada, incluso si fue incierta. Si todavía no hubo reserva, el aviso permanece pendiente y puede recuperarse en una sincronización posterior mientras el reclamo siga abierto.
- No se promete entrega exactamente una vez; Telegram y la BD no comparten una transacción.
- El despliegue debe pausar todos los escritores MeLi durante la migración como defensa adicional a la frontera por ID.
- La paginación remota no es una instantánea transaccional. Una ausencia provoca una lectura individual, nunca un cierre inferido ni borrado.
- Un recurso opcional puede seguir sin estar disponible por permisos de MeLi; se conserva el manejo existente y sus logs.
- No se verificó entrega real a Telegram ni rendimiento contra MeLi; las pruebas no crean reclamos reales. Tampoco se ejecutaron cambios en producción.

## Validaciones iniciales, 2026-09-15

PHP 8.4.24 de Herd, Laravel instalado por el proyecto y SQLite en memoria; APIs simuladas con `Http::fake`, `Http::preventStrayRequests`, mocks y `Bus::fake`.

| Verificación | Resultado |
| --- | --- |
| PHP de todos los archivos modificados: `php -l` | Sin errores de sintaxis |
| `php artisan optimize:clear` | Correcto, entorno local aislado |
| `php artisan migrate:status` sobre SQLite vacía | No existía tabla de migraciones; no se consultó la BD real |
| Migración real vía Artisan en SQLite en memoria | Baseline correcto, `migrate:status --path=...` mostró `[2] Ran`; rollback retiró columna y conservó el reclamo |
| `php artisan schedule:list` | `*/5 * * * * php artisan meli:sync-claims --status=opened --days=0` |
| Tests PHP de reclamos | **67 tests, 524 assertions, correctos** |
| Tests JavaScript de reclamos | **5 tests correctos** |
| Suite completa de la feature | **534 tests, 4732 assertions, 36 errores, 0 fallos de aserción** |
| Suite de la base `cf436e7` en copia temporal | **516 tests, 4662 assertions, los mismos 36 errores, 0 fallos de aserción** |
| Comparación de identidades de tests con error | **0 diferencias; sin errores nuevos** |

Errores preexistentes: 32 tests que ejecutan migraciones generales encuentran `producto_compuestos` ya existente; 4 tests de `MeliLinkedPublicationServiceTest` usan una tabla `users` sin columna `role`. No se modificaron esos módulos. La comparación base usó el mismo vendor y manifiesto Vite local, sin copiar `.env`.

La suite necesitó `php -d memory_limit=512M vendor/bin/phpunit`: el límite inicial de 128 MB era insuficiente. Herd muestra además una advertencia de inicio por la extensión GMP bloqueada por Windows, también presente en la base; las pruebas de reclamos terminan correctamente.

Los 18 casos nuevos cubren fechas iguales/cambiadas/ausentes (incluyendo fracciones), creación, force, reconciliación aislada por cuenta, búsqueda truncada/fallida/sin total, rango histórico, paginación, Telegram una vez con modelos desactualizados, errores HTTP/excepciones/timeout, baseline, reclamo nuevo cerrado, tamaño/productos/URL del mensaje, botón/comando, scheduler y topics/cola del webhook.

## Revisión correctiva

Se agregaron 7 tests para: aviso pendiente en un claim sin cambios sin descargar detalles, exclusión de marcados tanto en skip como refresh, recuperación tras configuración ausente, excepción antes de reservar que mantiene el aviso pendiente, segundo notifier durante un envío en curso, inserción posterior al máximo ID histórico e inserción con baseline inicialmente vacío. Los tests existentes siguen cubriendo timeout ambiguo sin segundo envío.

- Claims y Telegram: **74 tests, 554 assertions, correctos**.
- Suite completa: **541 tests, 4762 assertions, los mismos 36 errores preexistentes**, sin fallos de aserción. Comparación de identidades contra los resultados de la base `cf436e7`: cero diferencias.
- `php -l`: sin errores en los tres PHP corregidos. `git diff --check`: correcto.
- Lectura estricta UTF-8 de los 9 archivos PHP/Markdown modificados por la feature: sin mojibake real. Verificados `Búsqueda`, `configuración`, `acción`, `Sí`, `—` y `🚨` en el código PHP. No fue necesario cambiar ningún archivo por encoding.
- Se conserva sin cambios la reserva atómica de `TelegramAlertService`; no se limpian marcas después de timeout o rechazo. Los tests usan SQLite en memoria y APIs simuladas.

## Despliegue por el operador

Ejecutar desde la raíz del proyecto en el servidor solamente después de revisar y aprobar el PR. No se fusiona automáticamente. No editar `.env`. Conservar el backup habitual de BD y la release anterior. Este cambio no requiere Composer ni compilación de frontend.

1. Identificar cómo se ejecutan los workers `meli` y el scheduler existentes. Los nombres de servicios/grupos no están en el repositorio: no se inventa un servicio ni un cron. Para Supervisor, obtener el grupo real con `sudo supervisorctl status` y asignarlo a `MELI_WORKER_GROUP`. Si se usa otro gestor, aplicar su equivalente de parada/arranque. Comprobar también workers de otras colas que puedan consumir `meli`.
2. Registrar el commit anterior, entrar en mantenimiento y detener los consumidores. El scheduler normal omite tareas en mantenimiento; si se configuró algún proceso externo que fuerce ejecuciones, pausarlo con su gestor. Esperar a que terminen sincronizaciones ya iniciadas y no ejecutar comandos manuales durante el baseline.

   ```bash
   git status --short
   git rev-parse HEAD > /tmp/meli-claims-previous-release.txt
   php artisan down
   sudo supervisorctl stop "${MELI_WORKER_GROUP}:*"
   pgrep -af 'artisan (meli:sync-claims|queue:work|queue:listen|schedule:run)'
   ```

   Si hay cambios locales o un escritor de reclamos activo, resolverlo antes de continuar. No sobrescribir cambios ni matar una sincronización a mitad de escritura.

3. Obtener el commit revisado. Estas instrucciones permiten desplegar la feature sin hacer merge; si el proceso habitual produce una release aprobada distinta, usar su SHA exacto.

   ```bash
   git fetch origin
   git switch --detach origin/feature/meli-claims-incremental-telegram
   php artisan optimize:clear
   php artisan migrate:status
   php artisan migrate --path=database/migrations/2026_09_15_000001_add_telegram_notified_at_to_meli_claims.php --force
   php artisan migrate:status --path=database/migrations/2026_09_15_000001_add_telegram_notified_at_to_meli_claims.php
   php artisan schedule:list
   ```

   Confirmar que las migraciones anteriores de reclamos ya estaban aplicadas. La migración nueva captura el máximo ID antes de agregar la columna y asigna `now()` solamente hasta ese ID; los registros posteriores conservan null. Mantener pausados los escritores MeLi como defensa adicional. No ejecutar `migrate:fresh`, `migrate:reset` ni una migración histórica modificada. Esta corrección pertenece a la migración de la feature aún sin desplegar; no volver a correr manualmente el `up()` sobre una tabla ya migrada.

4. Restablecer las cachés que use el procedimiento habitual, reiniciar los workers con el código nuevo y salir de mantenimiento. Si se pausó el gestor del scheduler, reanudarlo.

   ```bash
   php artisan queue:restart
   sudo supervisorctl start "${MELI_WORKER_GROUP}:*"
   sudo supervisorctl status
   php artisan up
   php artisan queue:failed
   ```

   La pausa del webhook puede dejar notificaciones pendientes; el scheduler recupera abiertos. Confirmar que el worker consume `meli` y que no hay fallos nuevos de columna ausente.

## Prueba manual tras desplegar

```bash
time php artisan meli:sync-claims --account=1 --status=opened --days=0
time php artisan meli:sync-claims --account=1 --status=opened --days=0
grep -E 'recibidos|guardados|sin cambios|reconciliados|fallidos' storage/logs/meli-claims-sync.log | tail -n 20
grep -E 'MELI WEBHOOK: reclamo encolado|MELI CLAIMS|TelegramAlertService:.*reclamo' storage/logs/laravel.log | tail -n 40
sudo supervisorctl status
php artisan queue:failed
```

Si el canal rota logs, adaptar únicamente el nombre del archivo. En la segunda ejecución se esperan principalmente `sin cambios`, menos peticiones y menor tiempo. No prometer un tiempo fijo: depende de red, cambios remotos y recursos faltantes. Abrir el botón de sincronización y comprobar sus contadores. Un cierre de un reclamo local debe reconciliarse individualmente; los cerrados históricos permanecen.

Prueba segura de Telegram, sin notificación real ni cambios permanentes de estado:

```bash
php artisan test --filter=MeliClaimsTest
node --test tests/Unit/meliClaimsPresentation.test.js tests/Unit/meliClaimMessageUi.test.js tests/Unit/meliClaimResolutionUi.test.js
```

Los tests llaman a `notifyMeliNewClaim` con BD en memoria y HTTP simulado. No limpiar `telegram_notified_at` en un registro real ni fabricar un reclamo para probar.

## Rollback

**Preferido: volver al código anterior conservando la columna nullable y sus marcas.** Es compatible con el código anterior y mantiene el historial de deduplicación para un redespliegue.

1. Entrar en mantenimiento, detener los mismos workers y esperar a los escritores como en el despliegue.
2. Desde la raíz del proyecto:

   ```bash
   php artisan down
   sudo supervisorctl stop "${MELI_WORKER_GROUP}:*"
   git switch --detach "$(cat /tmp/meli-claims-previous-release.txt)"
   php artisan optimize:clear
   php artisan queue:restart
   sudo supervisorctl start "${MELI_WORKER_GROUP}:*"
   php artisan up
   php artisan schedule:list
   ```

   Restaurar las cachés del procedimiento habitual y el gestor del scheduler si se pausó. El código anterior recupera su búsqueda histórica de 30 días; el rollback también revierte la optimización.

**Retirar además la columna, solamente si se necesita:** con los escritores detenidos, antes de cambiar al código anterior, comprobar que la nueva migración es la última aplicada y ejecutar:

```bash
php artisan migrate:status
php artisan migrate:rollback --path=database/migrations/2026_09_15_000001_add_telegram_notified_at_to_meli_claims.php --step=1 --force
```

Después ejecutar el cambio de código y reinicios indicados arriba. No usar este rollback selectivo si otra migración posterior requiere plan propio. Se pierden únicamente las marcas Telegram; no se elimina ningún reclamo, mensaje ni historial. Un redespliegue vuelve a baselinar los registros existentes.

## Commits de implementación

- `b1339ea`: estado Telegram y baseline.
- `d80a970`: sincronización incremental y reconciliación.
- `d079659`: Telegram y reserva atómica.
- `310eb38`: botón, comando y scheduler.
- `17cd1f2`: pruebas y validación de total remoto antes de reconciliar.
- `8493199`: preservar los datos operativos y la URL al acotar mensajes con productos largos; 67 tests de reclamos nuevamente correctos.
- `c0d6855`: revisión correctiva de avisos sin reserva y frontera del baseline, con 7 tests adicionales.

El commit de este reporte se identifica mediante `git log --oneline rescue/production-2026-08-21..HEAD`.
