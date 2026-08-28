# Fase 1: Fundación de autopartes

## Arquitectura implementada

Se crea un módulo nuevo con un dominio mínimo para importar archivos Excel de autopartes sin tocar la lógica de llantas ni de Mercado Libre.

### Tablas creadas

- automotive_part_imports
- automotive_part_import_rows
- automotive_parts
- automotive_part_stock_movements

### Relaciones principales

- Un import tiene muchas filas (`automotive_part_import_rows`).
- Una fila puede apuntar a un catálogo canónico (`automotive_parts`) cuando se normaliza.
- Un catálogo canónico tiene varios movimientos de stock (`automotive_part_stock_movements`).
- Cada producto canónico registra el último import que lo actualizó.

## Flujo de importación

1. El usuario sube un archivo .xls/.xlsx desde la UI.
2. Se crea un registro de importación y se dispara un job en cola.
3. El job procesa el archivo con `maatwebsite/excel`.
4. Se detecta la fila de cabecera, se normaliza cada fila y se genera un `source_key` determinístico.
5. Se guardan todas las filas originales.
6. Si la `source_key` ya existe en el mismo archivo, se marca el registro como duplicado, pero no se elimina.
7. Si el producto canónico existe, se actualiza el stock según el cambio real del `Qty`.
8. Se registra movimiento de stock solo cuando el qty cambia.
9. Se deja evidencia de duplicados, campos incompletos y errores por fila.

## Decisiones clave

- Los valores originales se conservan en columnas `*_raw` y en `normalized_payload`.
- Los números de parte se tratan como texto para no perder ceros.
- Las conversiones a centímetros y kilogramos se aplican con factores exactos: `2.54` y `0.45359237`.
- Los años se validan como enteros razonables y los vacíos quedan en `null`.
- La lógica no publica ni sincroniza con Mercado Libre ni con otros módulos existentes.

## Cómo ejecutar pruebas

Se recomienda ejecutar localmente:

```bash
php artisan test --filter=AutomotivePartNormalizerTest
```

Y, si el entorno local está operativo:

```bash
php artisan test
```

## Qué falta para fases posteriores

- IA para normalización semántica.
- Enriquecimiento de compatibilidad model-year.
- Sincronización con inventario externo y Mercado Libre.
- Flujo de aprobación y revisión de registros con datos incompletos.
- Dashboard más detallado para errores de importación y control de calidad.
