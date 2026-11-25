# R2 — Recuperación Segura de Colas Laravel (`mrpoolhmo.com`)

**Rama:** `fix/production-queue-recovery-r2`  
**Commit base exacto (producción):** `11c33bded3f5ba711e11b1f9f65a7392dedbfab5`

> **IMPORTANTE:** Esta entrega cubre exclusivamente **R2 (herramienta de diagnóstico, respaldo verificable y poda controlada por micro-lotes)**. No modifica la lógica de reservas, promociones Beauty ni Price Manager (reservado para fases posteriores), ni ejecuta acciones sobre producción.

---

## 1. Arquitectura de los Comandos

| Comando Artisan | Modo | Responsabilidad |
|---|---|---|
| `php artisan ops:queues-prune-redundant` | `--dry-run` (default) / `--apply` | Escaneo por cursor PK (`id > cursor AND id <= end_id`), sin `OFFSET` ni `unserialize()`, generación de manifiesto firmado con SHA-256, poda por micro-transacciones (máx. 500 filas), checkpoint atómico y reconciliación tras caída. |
| `php artisan ops:queues-verify-backup` | Solo lectura (o `--export` a archivo externo) | Exporta filas completas del manifiesto a NDJSON con hash por fila y digest global, valida hashes y ejecuta prueba de restauración en base de datos SQLite `:memory:` aislada. |
| `php artisan ops:queues-diagnose-reconstruction` | 100% solo lectura | Genera inventario de las 11 categorías de pedidos y promociones Beauty afectadas antes de descartar sus jobs, sin escribir en BD ni llamar a Mercado Libre. |

### Servicios internos (`app/Services/System/QueueRecovery/`)

- `QueuePayloadInspector`: lista blanca estricta (`ReconcileInventoryMeliOrderReservationsJob` y `ProcessMeliBeautyScheduledPriceJob`), validación de JSON sin `unserialize()`, coincidencia exacta de `displayName`, `data.commandName` y clase serializada `O:<len>:"<FQCN>"`, y extracción segura de identificadores (`meliOrderId`, `discountId`, `meliItemId`). Preserva cualquier payload JSON inválido o inconsistente.
- `QueueRecoveryManifest`: escritura atómica y firma criptográfica canónica (`manifest_sha256`). Aborta ante cualquier alteración.
- `QueueRecoveryBackupService`: exportación por lotes PK a NDJSON (`header` + `row` con `payload_hash` y `row_sha256` + `footer` con `entries_sha256`), verificación de hashes + prueba de restauración en SQLite `:memory:` aislada, y restauración determinística (`restoreToConnection`) hacia cualquier conexión aislada.
- `QueueRecoveryDiskChecker`: valida espacio mínimo libre (default `8192 MiB` = 8 GiB) sobre `base_path()`, `storage_path()`, `sys_get_temp_dir()`, directorios de manifiesto/respaldo/audit-log y rutas extra (`--disk-check-path=/var/lib/mysql`) antes de iniciar y antes de cada micro-lote.
- `QueueWorkerDetector`: inspecciona `/proc/*/cmdline` (Linux) / `ps` para abortar si existe cualquier worker activo (`queue:work`, `queue:listen`, `horizon`) que pueda consumir las colas objetivo.
- `QueueRecoveryLock`: exclusión mutua combinada (archivo `flock(LOCK_EX|LOCK_NB)` + MariaDB/MySQL `GET_LOCK(..., 0)`).
- `QueueRecoveryAuditLog` & `QueueRecoveryCheckpoint`: bitácora NDJSON append-only (`batch_intent` → DB `COMMIT` → `batch_committed` + checkpoint atómico). Si ocurre una caída entre `COMMIT` y actualización del checkpoint, `--resume` reconcilia el `batch_intent` pendiente contra la tabla `jobs` sin perder trazabilidad.
- `QueuePruneService`: orquestador de `dryRun()` y `apply()` con revalidación `SELECT ... FOR UPDATE`, límite superior de PK (`end_id`), reintentos acotados ante deadlocks/lock-wait (`40001`, `1213`, `1205`) y manejo de `SIGINT`/`SIGTERM`.
- `QueueReconstructionDiagnosticsService`: diagnóstico de solo lectura en 11 categorías sin asumir reconstrucción automática hasta verificar evidencia.

---

## 2. Garantías de Seguridad Pre-Apply (10 Barreras Obligatorias)

Antes de ejecutar una sola sentencia `DELETE`, `--apply` exige que se cumplan las 10 condiciones; cualquier discrepancia aborta inmediatamente:

1. `INVENTORY_MELI_ORDER_RESERVATIONS_AUTOMATIC === false` **y** `MELI_BEAUTY_SCHEDULED_PRICES_SCHEDULER_ENABLED === false`.
2. Ventana de mantenimiento explícita (`--maintenance-window=...`) **y** cero workers activos consumiendo las colas objetivo.
3. Cero trabajos reservados (`reserved_at IS NULL`) en todos los lotes objetivo (verificado en pre-flight y revalidado bajo `FOR UPDATE` dentro de cada micro-transacción).
4. Mínimo **8 GiB** (`--min-free-disk-mb=8192`) libres en todos los filesystems relevantes (revalidado antes de cada lote).
5. Coincidencia exacta de conexión, base de datos, tabla y esquema `jobs` respecto al manifiesto.
6. Manifiesto firmado previamente generado por `--dry-run`, sin alteraciones (`manifest_sha256` válido) y coincidencia exacta de `--job`.
7. Respaldo NDJSON completo del lote autorizado (preferiblemente en almacenamiento externo o montado fuera del volumen crítico).
8. Verificación de hashes del respaldo contra el manifiesto **y** prueba de restauración aislada exitosa en SQLite `:memory:`.
9. Revalidación de cada fila candidata dentro de la micro-transacción (`id <= end_id`, `reserved_at IS NULL`, `queue`, `payload_hash` y clase exacta re-inspeccionada).
10. Bloqueo exclusivo (`flock` + `GET_LOCK`) para impedir ejecuciones simultáneas.

---

## 3. Ejemplos de Ejecución

### 3.1 Dry-Run + Generación de Manifiesto + Respaldo en Almacenamiento Externo

```bash
php artisan ops:queues-prune-redundant \
  --dry-run \
  --job="App\Jobs\ReconcileInventoryMeliOrderReservationsJob" \
  --chunk=500 \
  --max-delete=10000 \
  --start-id=0 \
  --end-id=5222259 \
  --incident="INC-2026-1124-R2-BATCH01" \
  --manifest="/mnt/backups/r2/batch01.manifest.json" \
  --backup-out="/mnt/backups/r2/batch01.backup.ndjson" \
  --audit-log="/var/log/mrpoolhmo/r2-batch01.audit.ndjson"
```

### 3.2 Verificación Independiente de Respaldo y Prueba de Restauración Aislada

```bash
php artisan ops:queues-verify-backup \
  --manifest="/mnt/backups/r2/batch01.manifest.json" \
  --backup="/mnt/backups/r2/batch01.backup.ndjson"
```

### 3.3 Diagnóstico de Reconstrucción (100% Solo Lectura, Antes de Descartar)

```bash
php artisan ops:queues-diagnose-reconstruction \
  --manifest="/mnt/backups/r2/batch01.manifest.json" \
  --output="/mnt/backups/r2/batch01.reconstruction-diagnosis.json"
```

### 3.4 Ejecución Controlada `--apply` (y Reanudación con `--resume`)

```bash
php artisan ops:queues-prune-redundant \
  --apply \
  --job="App\Jobs\ReconcileInventoryMeliOrderReservationsJob" \
  --chunk=500 \
  --max-delete=10000 \
  --start-id=0 \
  --end-id=5222259 \
  --sleep-ms=150 \
  --min-free-disk-mb=8192 \
  --disk-check-path="/var/lib/mysql" \
  --maintenance-window="MW-2026-1124-R2-01" \
  --manifest="/mnt/backups/r2/batch01.manifest.json" \
  --backup="/mnt/backups/r2/batch01.backup.ndjson" \
  --audit-log="/var/log/mrpoolhmo/r2-batch01.audit.ndjson"

# Si se interrumpe con SIGINT/SIGTERM o falla entre COMMIT y checkpoint:
php artisan ops:queues-prune-redundant \
  --apply --resume \
  --job="App\Jobs\ReconcileInventoryMeliOrderReservationsJob" \
  --chunk=500 \
  --max-delete=10000 \
  --start-id=0 \
  --end-id=5222259 \
  --sleep-ms=150 \
  --min-free-disk-mb=8192 \
  --disk-check-path="/var/lib/mysql" \
  --maintenance-window="MW-2026-1124-R2-01" \
  --manifest="/mnt/backups/r2/batch01.manifest.json" \
  --backup="/mnt/backups/r2/batch01.backup.ndjson" \
  --audit-log="/var/log/mrpoolhmo/r2-batch01.audit.ndjson"
```

---

## 4. Estrategia de Manifiestos, Checkpoints y Recuperación ante Caídas

1. **Inmutabilidad del manifiesto:** cada lote de hasta `10,000` candidatos genera un archivo JSON firmado (`manifest_sha256`) con `start_id`, `end_id` (límite superior de PK), `entries` ordenados por `id` ascendente y `payload_hash` SHA-256 por registro.
2. **Write-Ahead Intent (`batch_intent`):** antes de iniciar la micro-transacción de 500 filas, se escribe y sincroniza (`fflush`/`fsync`) el registro `batch_intent` con `batch_seq` y la lista exacta de IDs en el audit log NDJSON.
3. **Commit y Checkpoint Atómico:** tras el `COMMIT` en MariaDB, se escribe `batch_committed` (con conciliación de manifiesto vs respaldo vs eliminados vs restantes) y se actualiza el checkpoint (`<audit-log>.checkpoint.json`) mediante archivo temporal + `rename()`.
4. **Recuperación si hay caída entre `COMMIT` y checkpoint:** al invocar `--resume`, el servicio busca `batch_intent` sin `batch_committed`:
   - Si los IDs ya no están en `jobs`, registra `batch_reconciled_after_crash`, actualiza el checkpoint y continúa sin pérdida de trazabilidad.
   - Si todos los IDs siguen presentes, registra `batch_aborted` y re-ejecuta el lote.
   - Si falta solo una fracción (modificación parcial ajena), aborta inmediatamente por `concurrent_modification`.

---

## 5. Estimación de Recursos para Producción (1.6 GB RAM, 11 GB Libres)

| Parámetro | Estimación por corrida de 10,000 filas (`chunk=500`) | Total (~2.76M filas / ~277 corridas o lotes mayores posteriores) |
|---|---|---|
| **RAM proceso PHP** | ~35–60 MiB (lectura y escritura por lotes de 500 filas) | Acotada por corrida; sin acumulación en memoria |
| **Tamaño manifiesto JSON** | ~3.5–4.5 MiB por lote de 10,000 jobs | Almacenar fuera de `/` o comprimir/rotar tras validar cada ventana |
| **Tamaño respaldo NDJSON** | ~4–6 MiB sin comprimir (~0.8 MiB `.gz`) por 10,000 jobs | **Almacenar en volumen externo/remoto** (total sin comprimir ~1.1–1.4 GB; comprimido ~180–250 MB) |
| **Undo / Redo / Binlog MariaDB** | ~5–12 MiB por lote de 10,000 filas (20 micro-transacciones de 500) | Monitorear `SHOW BINARY LOGS` y `df -h` cada 50k–100k filas; guardrail aborta automáticamente si libre < 8 GiB |
| **Tiempo estimado** | ~6–15 s por corrida de 10,000 filas (con `--sleep-ms=150`) | Progresivo y pausable; nunca bloquea tabla completa |

---

## 6. Riesgos Residuales

1. **Crecimiento de binlogs MariaDB si `log_bin=ON`:** borrar 2.76M filas generará ~1.2–2.0 GB de binlogs en total; debe verificarse `SHOW BINARY LOGS` antes y durante la operación, manteniendo el guardrail `--min-free-disk-mb=8192` activo.
2. **Espacio interno `data_free` en `jobs.ibd`:** `DELETE` libera páginas internas para reutilización de InnoDB pero no encoge el archivo `.ibd` en disco. **NO ejecutar `OPTIMIZE TABLE`** en este servidor mientras el espacio libre sea limitado.
3. **Resolución de estado real en pedidos/promociones:** R2 solo poda duplicados redundantes y entrega el inventario diagnóstico; las categorías marcadas como `requires_manual_verification` (pedidos enviados sin reserva, movimientos manuales, multilínea, kits y estados remotos Beauty) deben conciliarse en la fase siguiente tras corregir los bugs identificados en la auditoría.
4. **Causa raíz de `Supervisor: FATAL`:** debe confirmarse y corregirse antes de reactivar workers.
