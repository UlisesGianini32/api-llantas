# R2.1 — Recuperación Segura y Endurecida de Colas Laravel (`mrpoolhmo.com`)

**Rama:** `fix/production-queue-recovery-r2`  
**Commit base exacto (producción):** `11c33bded3f5ba711e11b1f9f65a7392dedbfab5`  
**Estado operativo asumido:** `INVENTORY_MELI_ORDER_RESERVATIONS_AUTOMATIC=false`, `MELI_BEAUTY_SCHEDULED_PRICES_SCHEDULER_ENABLED=false`, workers detenidos (`Supervisor: FATAL`), `log_bin=OFF`, `innodb_file_per_table=ON`, InnoDB buffer pool `256 MiB`, RAM `1.6 GB`, disco libre `11 GB`, último `jobs.id` observado `5222259`.

> **IMPORTANTE:** Esta entrega cubre exclusivamente **R2 / R2.1 (diagnóstico, respaldo verificable y poda controlada por micro-lotes)**. No modifica la lógica comercial de reservas, promociones Beauty ni Price Manager, no detiene ni modifica Supervisor automáticamente y **NO ejecuta acciones contra producción**.

---

## 1. Arquitectura de los Comandos (R2.1 Endurecido)

| Comando Artisan | Modo | Responsabilidad |
|---|---|---|
| `php artisan ops:queues-prune-redundant` | `--dry-run` (default) / `--apply` | Escaneo por cursor PK (`id > start_id AND id <= authorized_upper_bound`) acotado por `--max-scan` y `--max-delete`, separación explícita de `authorized_upper_bound` / `last_scanned_id` / `last_candidate_id` / `last_deleted_id` / `next_start_id` / `is_partial`, verificación de integridad + autenticidad independiente (`--expected-manifest-sha256` obligatorio + HMAC-SHA256 externo opcional), poda por micro-transacciones (máx. 500 filas; piloto inicial 100 filas), revalidación de las 7 columnas de `jobs`, barreras re-verificadas antes de cada micro-lote y recuperación fail-closed ante caídas. |
| `php artisan ops:queues-verify-backup` | Solo lectura (o `--export` fuera del repo) | Exporta filas completas (`id, queue, payload, attempts, reserved_at, available_at, created_at`) a NDJSON con permisos `0600` fuera del repositorio Git (sin sobrescritura accidental ni symlinks), valida hashes contra las 7 columnas del manifiesto y ejecuta prueba de restauración aislada en SQLite `:memory:` midiendo tiempo y pico de memoria. |
| `php artisan ops:queues-diagnose-reconstruction` | 100% solo lectura | Inventario en 11 categorías alineado al esquema real de producción (`inventory_movements.occurred_at`, `meli_beauty_scheduled_discount_items`, `meli_order_items.remote_line_key`, `inventory_channel_links`, `meli_accounts`) + anomalías de esquema (reglas Beauty eliminadas/modificadas, links ambiguos, cuentas inexistentes) + clasificación en 3 niveles (`reconstructible_candidates`, `ambiguous_entities`, `pending_human_review`). |

### Servicios internos (`app/Services/System/QueueRecovery/`)

- `QueueRecoveryPathGuard`: bloquea path traversal (`..`), enlaces simbólicos en cualquier nivel, escritura de respaldos dentro del repositorio Git o directorios públicos/versionados, y sobrescritura accidental de respaldos (`backup_already_exists`); aplica permisos `0600`/`0700` y escritura atómica con verificación completa de bytes (`fwrite` == longitud esperada), `fflush()` y `fsync()` obligatorio.
- `QueuePayloadInspector`: lista blanca estricta (`ReconcileInventoryMeliOrderReservationsJob` y `ProcessMeliBeautyScheduledPriceJob`), validación de JSON sin `unserialize()`, coincidencia exacta de `displayName`, `data.commandName` y clase serializada `O:<len>:"<FQCN>"`.
- `QueueRecoveryManifest` (`schema_version=2`):
  - **Modelo de seguridad explícito:** `manifest_sha256` es un **checksum de integridad** (detecta corrupción accidental), **NO** una firma criptográfica autenticada.
  - La autenticidad/aprobación del operador para `--apply` se exige mediante **`--expected-manifest-sha256=<hash>` obligatorio** entregado fuera de banda por el operador tras revisar el dry-run, y opcionalmente mediante **HMAC-SHA256 externo** (`QUEUE_RECOVERY_MANIFEST_HMAC_KEY`, `manifest_hmac_sha256`).
- `QueueRecoveryBackupService` (`schema_version=2`): exporta las 7 columnas completas con `row_sha256` y `payload_hash`, exige coincidencia de las 7 columnas contra el manifiesto, verifica integridad + restauración aislada en SQLite `:memory:`, y en `restoreToConnection()` verifica colisiones de PK **antes** de insertar (aborta con `restore_pk_collision` sin sobrescribir jamás trabajos nuevos).
- `QueueWorkerDetector`: modo **fail-closed**. Aborta si la inspección de procesos no está disponible (`worker_inspection_unavailable`) o si existen `/proc/<pid>/cmdline` ilegibles (`worker_inspection_insufficient_permissions`). Detecta `queue:work`, `queue:listen`, `horizon`, `schedule:run`, `schedule:work` y `meli:beauty-scheduled-prices`. Valida además el archivo JSON sentinel de ventana de mantenimiento (`--maintenance-lock-file`) antes del preflight y **antes de cada micro-lote**.
- `QueueRecoveryAuditLog` & `QueueRecoveryCheckpoint`: bitácora NDJSON con `fsync` estricto por registro. **Nunca ignora** líneas NDJSON corruptas o truncadas (aborta con `audit_log_corrupt`). Distingue `batch_intent`, `batch_committed`, `batch_checkpoint_saved`, `batch_reconciled_operator_confirmed` y `batch_aborted`.
- `QueuePruneService`: orquesta `dryRun()` y `apply()` revalidando las 7 columnas + `row_sha256` bajo `SELECT ... FOR UPDATE` en cada micro-transacción y re-verificando **todas** las barreras operativas (automatizaciones, sentinel de mantenimiento, workers activos y espacio en disco >= 8 GiB) antes de cada micro-lote.

---

## 2. Barreras Operativas Fail-Closed (Preflight + Antes de Cada Micro-Lote)

Antes de ejecutar una sola sentencia `DELETE` y **nuevamente antes de cada micro-lote**, `--apply` exige:

1. `INVENTORY_MELI_ORDER_RESERVATIONS_AUTOMATIC === false` **y** `MELI_BEAUTY_SCHEDULED_PRICES_SCHEDULER_ENABLED === false`.
2. Ventana operativa autorizada (`--maintenance-window`) **y** archivo sentinel fail-closed activo (`--maintenance-lock-file`) que coincida con `window_id`, `incident_id`, `workers_halted_confirmed: true` y `expires_at` vigente en UTC.
3. Inspección completa de procesos sin entradas `/proc/*/cmdline` ilegibles y **cero** procesos activos de `queue:work`, `queue:listen`, `horizon`, `schedule:run`, `schedule:work` o `meli:beauty-scheduled-prices`.
4. Mínimo **8 GiB** (`--min-free-disk-mb=8192`) libres en todos los filesystems relevantes (incluyendo `/var/lib/mysql` y destino de respaldo/logs).
5. Coincidencia exacta de conexión, base de datos, tabla y esquema `jobs` respecto al manifiesto.
6. Manifiesto generado por `--dry-run` aprobado explícitamente por el operador mediante `--expected-manifest-sha256=<hash>` (y HMAC externo si está configurado) + coincidencia exacta de `--job`.
7. Respaldo NDJSON completo almacenado **fuera del repositorio Git** con permisos `0600` (y preferiblemente copiado/verificado en almacenamiento externo seguro antes de `--apply`).
8. Verificación de hashes de las 7 columnas del respaldo contra el manifiesto **y** prueba de restauración aislada exitosa en SQLite `:memory:`.
9. Revalidación de las **7 columnas** (`id`, `queue`, `payload`, `attempts`, `reserved_at`, `available_at`, `created_at`) + `payload_hash` + `row_sha256` + clase exacta bajo `FOR UPDATE` dentro de cada micro-transacción, respetando `id <= min(authorized_upper_bound, last_scanned_id)`.
10. Bloqueo exclusivo (`flock` + MariaDB `GET_LOCK`) para impedir ejecuciones simultáneas.

### Barrera operativa recomendada contra reactivación accidental de workers (sin modificar Supervisor automáticamente)

Antes de abrir la ventana de mantenimiento, el operador crea manualmente el archivo sentinel fuera del repositorio (con permisos `0600`, expiración acotada de p. ej. 2 horas):

```bash
install -d -m 0700 /var/run/mrpoolhmo-maintenance
cat <<'EOF' > /var/run/mrpoolhmo-maintenance/queue-recovery.lock.json
{
  "window_id": "MW-2026-1125-R21-PILOT",
  "incident_id": "INC-2026-1125-R21-PILOT01",
  "workers_halted_confirmed": true,
  "approved_by": "operador-oncall",
  "expires_at": "2026-11-25T06:00:00Z"
}
EOF
chmod 0600 /var/run/mrpoolhmo-maintenance/queue-recovery.lock.json
```

Opcionalmente, el wrapper operativo de arranque de workers en producción puede verificar `[ ! -f /var/run/mrpoolhmo-maintenance/queue-recovery.lock.json ]` antes de lanzar `queue:work` para impedir reactivaciones accidentales mientras el sentinel exista. Si el operador borra el sentinel o este expira durante `--apply`, el siguiente micro-lote aborta inmediatamente en modo fail-closed.

---

## 3. Procedimiento Revisado para Primer Piloto de Producción (Máx. 1,000 Candidatos, Micro-Lotes de 100)

> **NO ejecutar hasta obtener autorización explícita de ventana de mantenimiento y aprobación final de R2.1.**  
> Mantener **ambas automatizaciones en `false`** y **todos los workers detenidos** durante todo el procedimiento.

### Paso 1: Verificar prerequisitos del servidor (solo lectura)

```bash
# Confirmar workers detenidos (Supervisor FATAL / STOPPED y sin queue:work / cron activos)
supervisorctl status
ps -ef | grep -E "queue:work|queue:listen|horizon|schedule:run|meli:beauty" | grep -v grep

# Confirmar configuración de automatizaciones deshabilitadas
php artisan tinker --execute='var_dump(config("inventory.meli_order_reservations.automatic"), config("meli_price_manager.beauty_scheduled_prices.scheduler_enabled"));'

# Confirmar espacio libre (>= 8 GiB, actualmente ~11 GB)
df -h / /var/lib/mysql /mnt/backups-externos
```

### Paso 2: Dry-Run del Piloto #1 (`max-delete=1000`, `chunk=100`, `max-scan=50000`, `end-id=5222259`)

```bash
# Opcional pero recomendado: clave HMAC externa exportada solo en la sesión del operador
# export QUEUE_RECOVERY_MANIFEST_HMAC_KEY="<clave-aleatoria-32-bytes-fuera-del-repo>"

php artisan ops:queues-prune-redundant \
  --dry-run \
  --job="App\Jobs\ReconcileInventoryMeliOrderReservationsJob" \
  --chunk=100 \
  --max-delete=1000 \
  --max-scan=50000 \
  --start-id=0 \
  --end-id=5222259 \
  --incident="INC-2026-1125-R21-PILOT01" \
  --manifest="/mnt/backups-externos/r2/pilot01.manifest.json" \
  --backup-out="/mnt/backups-externos/r2/pilot01.backup.ndjson" \
  --audit-log="/var/log/mrpoolhmo/r2-pilot01.audit.ndjson"
```

Revise la tabla de salida y **anote explícitamente**:
- `Checksum manifest_sha256` (para pasarlo manualmente en `--expected-manifest-sha256`)
- `authorized_upper_bound` (`5222259`)
- `last_scanned_id` y `next_start_id` (para la siguiente ventana **después** de completar el piloto)
- `is_partial` y `stop_reason`

### Paso 3: Verificación independiente del respaldo + copia externa + diagnóstico de reconstrucción (solo lectura)

```bash
# Verificar hashes SHA-256 de las 7 columnas y prueba de restauración aislada
php artisan ops:queues-verify-backup \
  --manifest="/mnt/backups-externos/r2/pilot01.manifest.json" \
  --expected-manifest-sha256="<PEGAR_HASH_APROBADO_DEL_PASO_2>" \
  --backup="/mnt/backups-externos/r2/pilot01.backup.ndjson"

# Copiar el respaldo verificado a almacenamiento externo seguro y confirmar sha256sum
sha256sum /mnt/backups-externos/r2/pilot01.backup.ndjson /mnt/backups-externos/r2/pilot01.manifest.json

# Diagnóstico 100% solo lectura de pedidos/reglas afectados en el piloto
php artisan ops:queues-diagnose-reconstruction \
  --manifest="/mnt/backups-externos/r2/pilot01.manifest.json" \
  --expected-manifest-sha256="<PEGAR_HASH_APROBADO_DEL_PASO_2>" \
  --output="/mnt/backups-externos/r2/pilot01.diagnosis.json"
```

### Paso 4: Ejecución del Piloto #1 (`--apply`, máx. 1,000 filas, micro-transacciones de 100)

```bash
php artisan ops:queues-prune-redundant \
  --apply \
  --job="App\Jobs\ReconcileInventoryMeliOrderReservationsJob" \
  --chunk=100 \
  --max-delete=1000 \
  --start-id=0 \
  --end-id=5222259 \
  --sleep-ms=200 \
  --min-free-disk-mb=8192 \
  --disk-check-path="/var/lib/mysql" \
  --disk-check-path="/mnt/backups-externos" \
  --maintenance-window="MW-2026-1125-R21-PILOT" \
  --maintenance-lock-file="/var/run/mrpoolhmo-maintenance/queue-recovery.lock.json" \
  --manifest="/mnt/backups-externos/r2/pilot01.manifest.json" \
  --expected-manifest-sha256="<PEGAR_HASH_APROBADO_DEL_PASO_2>" \
  --backup="/mnt/backups-externos/r2/pilot01.backup.ndjson" \
  --audit-log="/var/log/mrpoolhmo/r2-pilot01.audit.ndjson"
```

### Paso 5: Avance Seguro de Cursor a la Siguiente Ventana (NUNCA usar `authorized_upper_bound` si `is_partial=true`)

Una vez completado el Piloto #1 (`status=completed`, `remaining_in_manifest=0`), use exactamente el valor **`next_start_id`** (= `last_scanned_id` del manifiesto anterior) como `--start-id` de la ventana #2, manteniendo el mismo `--end-id=5222259` (`authorized_upper_bound`):

```bash
# Ejemplo: si pilot01 terminó con next_start_id=1842, la ventana 2 inicia en --start-id=1842 --end-id=5222259
php artisan ops:queues-prune-redundant \
  --dry-run \
  --job="App\Jobs\ReconcileInventoryMeliOrderReservationsJob" \
  --chunk=500 \
  --max-delete=10000 \
  --max-scan=50000 \
  --start-id=1842 \
  --end-id=5222259 \
  --incident="INC-2026-1125-R21-WIN02" \
  --manifest="/mnt/backups-externos/r2/win02.manifest.json" \
  --backup-out="/mnt/backups-externos/r2/win02.backup.ndjson" \
  --audit-log="/var/log/mrpoolhmo/r2-win02.audit.ndjson"
```

### Paso 6: Cierre de Ventana

Al concluir las ventanas autorizadas, elimine `/var/run/mrpoolhmo-maintenance/queue-recovery.lock.json`. **Mantenga las automatizaciones en `false` y los workers detenidos** hasta completar la conciliación post-poda y las correcciones permanentes de Fase 4.

---

## 4. Protocolo de Recuperación ante Caídas e Interrupciones

Cada micro-lote ejecuta estrictamente:

`preflight/barreras → batch_intent (fsync) → SELECT FOR UPDATE (7 cols + row_sha256) → DELETE → COMMIT → batch_committed (fsync) → checkpoint atómico (fsync) → batch_checkpoint_saved (fsync)`

Comportamiento de `--resume` según el punto exacto de fallo:

| Punto de fallo | Estado en `jobs` y audit log | Comportamiento de `--resume` |
|---|---|---|
| Antes de `batch_intent` | Sin intent; filas presentes | Continúa normalmente desde el último checkpoint |
| Después de `batch_intent`, antes de `COMMIT` | 100% de las filas del lote siguen en `jobs` | Registra `batch_aborted` (`intent_not_committed_before_crash`) y re-ejecuta el lote |
| Después de `batch_intent`, **desaparición parcial** | Faltan solo algunas filas del lote sin `batch_committed` | **ABORTA SIEMPRE** (`unresolved_partial_batch_intent`) y exige investigación manual |
| Después de `COMMIT`, antes de `batch_committed` | 0 filas presentes, sin `batch_committed` en audit log | **ABORTA POR DEFECTO** (`unresolved_ambiguous_batch_intent`). **Nunca asume automáticamente que R2 borró las filas.** Solo tras investigar BD/logs, el operador puede confirmar explícitamente ese `batch_seq` con `--resume --confirm-reconcile-batch=<N>` |
| Después de `batch_committed`, antes del checkpoint | `batch_committed` persistido con `fsync`; 0 filas presentes | Reconcilia automáticamente el checkpoint, registra `batch_checkpoint_saved` y continúa |
| Última línea NDJSON truncada o corrupta | JSON inválido en audit log | **ABORTA SIEMPRE** (`audit_log_corrupt`) en modo fail-closed |
| Disco lleno o fallo de `fsync()` | Error de escritura/sincronización | **ABORTA** (`io_write_failed` / `io_sync_failed`); archivos atómicos temporales no sobrescriben el checkpoint válido previo |

---

## 5. Mediciones Reales de Recursos (Benchmark Aislado con 100,000 Filas Sintéticas) y Notas MariaDB

Mediciones obtenidas en `OpsQueuesPruneRedundantVolumeTest` sobre 100,000 filas sintéticas en base SQLite en disco (39.17 MiB):

| Perfil | `dry-run` | Manifiesto JSON | Respaldo NDJSON | Restauración aislada SQLite | `--apply` total | Micro-transacción (prom / máx) | Pico RAM PHP |
|---|---:|---:|---:|---:|---:|---:|---:|
| **Piloto (`1,000` filas, `chunk=100`)** | 65.74 ms | 702.9 KiB (719,732 B) | 721.7 KiB (739,039 B) | 25.17 ms | 167.89 ms (10 lotes) | 3.23 ms / 4.18 ms | ≤ 112 MiB |
| **Ventana (`10,000` filas, `chunk=500`)** | 531.60 ms | 6.85 MiB (7,185,283 B) | 7.05 MiB (7,397,699 B) | 234.59 ms | 815.59 ms (20 lotes) | 11.99 ms / 13.50 ms | ≤ 112 MiB |

### Consideraciones específicas de MariaDB en producción (`1.6 GB RAM`, `256 MiB` buffer pool, `log_bin=OFF`, `innodb_file_per_table=ON`)

1. **`log_bin=OFF` confirmado en producción:** no se requieren consultas `SHOW BINARY LOGS` ni existe crecimiento de binary logs por estas eliminaciones. Sí debe vigilarse el uso de `undo` / `ibtmp1` / `ib_logfile*` y espacio libre con `df -h` + el guardrail automático `--min-free-disk-mb=8192`.
2. **`DELETE` NO encoge `jobs.ibd` en disco:** InnoDB marca las páginas eliminadas como espacio interno reutilizable (`data_free`), pero el archivo `jobs.ibd` (~990 MB) mantiene su tamaño en el sistema de archivos.
3. **PROHIBIDO ejecutar `OPTIMIZE TABLE jobs`:** con solo 11 GB libres y 1.6 GB de RAM (`256 MiB` buffer pool), `OPTIMIZE TABLE` reconstruye la tabla completa, genera alto I/O y riesgo de agotamiento de disco o bloqueo prolongado.
4. **Volumen acumulado de respaldos:** 10,000 filas ocupan ~7.05 MiB sin comprimir (~1.0 MiB con `gzip`). Para ~2.76M filas, los respaldos sin comprimir sumarían ~1.95 GB; **escribirlos directamente en almacenamiento externo** o comprimir/transferir externamente después de cada ventana para no consumir los 11 GB locales.
5. **Recompresión / truncado futuro:** cualquier compactación física de `jobs.ibd` solo podrá evaluarse en una ventana futura dedicada, después de vaciar el backlog y verificar espacio libre y respaldos completos.
