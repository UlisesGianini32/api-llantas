# Fase 5: bandeja de publicaciones pendientes

Esta fase agrega una bandeja administrativa para resolver publicaciones `uncategorized`, `suggested` e, intencionalmente, restaurar las `ignored`. No actualiza precios, stock, marca reportada, atributos ni publicaciones de Mercado Libre.

## Pantalla y contexto de cuenta

La página React `resources/js/Pages/MeliPriceManager/Uncategorized.jsx` se sirve en:

```text
GET /meli-price-manager/uncategorized
```

Todas las rutas requieren autenticación. El selector muestra únicamente las cuentas del usuario. Si se envía una cuenta ajena, el listado responde 404; las acciones validan además que el item pertenezca a la cuenta seleccionada.

La navegación de Meli Price Manager ofrece accesos separados para `Marcas y alias` y `Pendientes de clasificación`.

## Listado, filtros y rendimiento

La vista predeterminada combina `uncategorized` y `suggested`, pero nunca mezcla publicaciones `categorized`. También existen filtros independientes para:

- todos los pendientes;
- sin categorizar;
- sugeridos;
- ignorados;
- búsqueda libre en título, SKU, MLM o marca ML;
- SKU;
- MLM;
- marca ML;
- categoría ML;
- precio mínimo y máximo.

El listado se pagina en servidor y permite 25, 50 o 100 filas. La consulta selecciona solo los campos necesarios y carga anticipadamente `brandGroup`, `suggestedBrandGroup` y `matchedBrandAlias`, evitando N+1. Los conteos del encabezado se calculan por estado y cuenta.

La tabla muestra imagen —o un placeholder—, SKU, título, MLM, marca original y normalizada, estado, sugerencia, confianza, fuente, alias coincidente, metadata de auditoría, categoría, precio, stock y acciones. `permalink` se utiliza directamente para abrir la publicación; no se construyen URLs ni se realizan consultas adicionales a Mercado Libre.

## Acciones individuales

Las acciones están en `MeliItemClassificationActionController` y las transiciones en `MeliItemClassificationActionService`.

### Aceptar sugerencia

Mueve `suggested_brand_group_id` a `brand_group_id`, limpia la sugerencia y guarda:

```text
classification_status = categorized
classification_source = manual_suggestion
classification_confidence = 1.0000
```

La confianza anterior, fuente anterior, sugerencia, usuario y fecha quedan en `classification_metadata.manual_decisions`. Se eligió confianza `1.0000` porque existe confirmación humana; la confianza automática anterior permanece auditable.

### Asignar otra marca

Solo permite marcas activas y guarda:

```text
classification_status = categorized
classification_source = manual_assignment
classification_confidence = 1.0000
```

La sugerencia anterior se limpia, pero su contexto se conserva en metadata. `meli_brand`, `raw_item` y `raw_attributes` no se modifican.

### Ignorar y restaurar

Ignorar requiere confirmación y establece:

```text
classification_status = ignored
classification_source = manual_ignored
brand_group_id = null
suggested_brand_group_id = null
```

Restaurar es explícito y devuelve el item a `uncategorized`, limpiando marca asignada, sugerencia, alias coincidente, fuente y confianza. El historial de decisiones se conserva en metadata.

Todas las fuentes creadas por decisiones humanas comienzan con `manual`, por lo que el clasificador de Fase 3 las protege. El estado `ignored` también continúa protegido.

## Crear alias y asignar

El formulario propone `meli_brand` cuando existe, sin extraer palabras del título. Solicita marca destino, alias, tipo, prioridad y estado.

`normalized_alias` se ignora si viene del cliente y se calcula en el Form Request mediante `MeliBrandNormalizer`. La transacción:

1. busca un alias normalizado equivalente dentro de la marca;
2. lo reutiliza si ya existe o crea uno nuevo;
3. asigna manualmente el item actual.

No se ejecuta una reclasificación masiva. La pantalla mantiene un enlace a la vista de marcas, desde donde puede usarse el preview de Fase 4.

Si el alias existe en otra marca, la primera solicitud se rechaza con los nombres en conflicto. El usuario debe confirmar expresamente el riesgo para continuar. Esto permite casos legítimos sin ocultar la posibilidad de resultados ambiguos.

## Crear marca desde un item

El formulario permite nombre, slug opcional, descripción, estado y orden. El slug se genera desde el nombre cuando se omite. Opcionalmente crea un alias con la marca reportada por Mercado Libre.

Crear marca, crear el alias opcional y asignar el item se ejecutan en una sola transacción. Ningún otro item se reclasifica.

## Acciones masivas

La selección de la página admite hasta 200 IDs por operación y siempre requiere confirmación. Las acciones son:

- asignar marca: usa `manual_bulk_assignment` y la misma marca activa para todos;
- aceptar sugerencias: cada item recibe su propio `suggested_brand_group_id` y usa `manual_bulk_suggestion`;
- ignorar: usa `manual_bulk_ignored`;
- volver a pendientes: solo acepta items ignorados.

Antes de iniciar la transacción se valida que todos los IDs existan en la cuenta seleccionada. La operación vuelve a bloquear y verificar los registros dentro de la transacción, evitando escrituras parciales o cambios entre la validación y la ejecución. Si una aceptación masiva incluye un item sin sugerencia válida, no se modifica ninguno.

## Form Requests y seguridad

Los requests específicos son:

- `AcceptMeliItemSuggestionRequest`
- `AssignMeliItemBrandRequest`
- `CreateMeliItemAliasRequest`
- `CreateMeliItemBrandRequest`
- `IgnoreMeliItemRequest`
- `RestoreMeliItemRequest`
- `BulkMeliItemClassificationRequest`

Los requests individuales heredan la validación común de `MeliItemAccountRequest`. Cada solicitud comprueba:

- que `meli_account_id` pertenece al usuario autenticado;
- que el item de la ruta pertenece a esa cuenta;
- que el estado permite la acción;
- que la marca seleccionada existe y está activa;
- que tipos, prioridades, booleanos, confirmaciones e IDs sean válidos.

Los controladores escriben exclusivamente campos de clasificación previstos. `current_price`, `available_quantity`, `meli_brand`, `raw_item` y `raw_attributes` nunca forman parte de los cambios.

## Relación con Fases 3 y 4

La Fase 3 sigue siendo la única implementación del clasificador automático. Esta bandeja no duplica ni ejecuta ese motor al guardar.

La Fase 4 continúa administrando reglas y proporcionando preview/dry-run. Después de crear un alias, el operador puede navegar a `Marcas y alias`, revisar el efecto potencial y aplicar una reclasificación de forma explícita.
