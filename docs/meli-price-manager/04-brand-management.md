# Fase 4: administración de marcas y alias

Esta fase agrega una interfaz administrativa para mantener las reglas de clasificación de marcas de Meli Price Manager. No modifica precios, stock ni publicaciones de Mercado Libre y no realiza llamadas a su API.

## Pantalla y contexto de cuenta

La página React `resources/js/Pages/MeliPriceManager/Brands.jsx` se sirve mediante Inertia en `GET /meli-price-manager/brands`. La cuenta se elige con el parámetro `account`; solo aparecen las cuentas del usuario autenticado y una cuenta ajena se rechaza. Si no se indica una cuenta, se usa la marcada como predeterminada o, en su defecto, la primera disponible.

Las marcas se ordenan por `sort_order ASC, name ASC`. El controlador obtiene en la misma consulta los conteos de aliases, publicaciones categorizadas y publicaciones sugeridas. Los dos conteos de publicaciones están limitados a la cuenta seleccionada.

## Rutas

Todas las rutas requieren autenticación y usan el prefijo `/meli-price-manager`:

| Método | Ruta | Uso |
| --- | --- | --- |
| `GET` | `/brands` | Listado, detalle en panel y selector de cuenta |
| `POST` | `/brands` | Crear marca |
| `PUT` | `/brands/{brand}` | Editar marca |
| `PATCH` | `/brands/{brand}/status` | Activar o desactivar marca |
| `POST` | `/brands/{brand}/aliases` | Crear alias |
| `PUT` | `/aliases/{alias}` | Editar alias |
| `PATCH` | `/aliases/{alias}/status` | Activar o desactivar alias |
| `DELETE` | `/aliases/{alias}` | Eliminar alias con confirmación explícita |
| `POST` | `/brands/reclassification/preview` | Vista previa general |
| `POST` | `/brands/{brand}/reclassification/preview` | Vista previa iniciada desde una marca |
| `POST` | `/brands/reclassification/apply` | Aplicar la reclasificación confirmada |

Los controladores son `MeliBrandGroupController`, `MeliBrandAliasController` y `MeliBrandReclassificationController`, bajo `App\Http\Controllers\MeliPriceManager`.

## Marcas

Crear y editar una marca usa Form Requests separados. Se validan nombre, slug único, descripción opcional, estado booleano y orden entero. El nombre se recorta antes de validar.

Al crear, el slug se genera desde el nombre si no fue enviado. Al editar, el slug enviado es el valor autoritativo: cambiar el nombre no altera silenciosamente un slug existente. La pantalla conserva el slug actual hasta que el usuario lo edita de forma explícita.

Desactivar una marca conserva sus aliases y publicaciones asignadas. La interfaz pide confirmación si existen publicaciones categorizadas y el backend informa cuántas se preservaron. El clasificador de Fase 3 deja de usar grupos inactivos en ejecuciones futuras.

## Aliases y normalización

Los formularios permiten alias, tipo de coincidencia, prioridad entre 0 y 1000 y estado. Los tipos disponibles son:

- `exact`: la marca normalizada debe ser idéntica.
- `starts_with`: debe comenzar con el alias como frase completa.
- `contains`: debe contener el alias como frase completa.
- `manual`: no participa en clasificación automática.

`normalized_alias` nunca se toma del formulario. Los Form Requests lo recalculan en el backend con `MeliBrandNormalizer`, tanto al crear como al editar. Esto evita que un valor manipulado por el cliente omita las reglas de normalización.

La combinación `brand_group_id + normalized_alias` se valida antes de escribir. Por ello, diferencias de mayúsculas, acentos o separadores no permiten duplicar un alias equivalente dentro de la misma marca. La misma forma normalizada sí puede existir en marcas distintas; se guarda y se muestra una advertencia con las marcas en conflicto porque el clasificador puede producir una sugerencia ambigua.

La interfaz advierte cuando el alias normalizado tiene dos o tres caracteres. No lo bloquea: el clasificador existente mantiene la coincidencia por palabra completa para reducir falsos positivos.

Activar o desactivar un alias no elimina el registro. La eliminación exige una confirmación explícita; no elimina publicaciones. La llave `matched_brand_alias_id` queda en `null` por `nullOnDelete`, mientras `classification_metadata` conserva la evidencia histórica que ya estuviera almacenada. Se recomienda desactivar antes que eliminar.

Guardar una marca o un alias no ejecuta ninguna reclasificación automática.

## Vista previa y aplicación

La vista previa llama a `MeliBrandClassificationService::classifyAccount()` con `dryRun: true`. Procesa la cuenta seleccionada y devuelve:

- procesadas;
- categorizadas;
- sugeridas;
- sin categorizar;
- ignoradas;
- manuales preservadas;
- cambios potenciales.

El resumen se guarda temporalmente en sesión y solo se muestra para la cuenta que lo generó. La acción desde una marca conserva esa marca como contexto visual, pero evalúa la cuenta completa: una publicación sin categoría puede empezar a coincidir con el alias recién agregado.

Aplicar requiere confirmación explícita y reutiliza el mismo servicio con `dryRun: false`. Las clasificaciones cuyo origen empieza con `manual` y las publicaciones con estado `ignored` se omiten conforme a las reglas de Fase 3. Al terminar se descarta la vista previa almacenada.

## Validación y seguridad

Los Form Requests son:

- `StoreMeliBrandGroupRequest`
- `UpdateMeliBrandGroupRequest`
- `UpdateMeliBrandGroupStatusRequest`
- `StoreMeliBrandAliasRequest`
- `UpdateMeliBrandAliasRequest`
- `UpdateMeliBrandAliasStatusRequest`
- `DeleteMeliBrandAliasRequest`
- `PreviewMeliBrandReclassificationRequest`
- `ApplyMeliBrandReclassificationRequest`

Los controladores escriben listas explícitas de campos. Los formularios de marcas y aliases no pueden cambiar `brand_group_id`, `classification_status` ni `classification_source` de una publicación. Las solicitudes de preview y aplicación validan que `meli_account_id` pertenezca al usuario autenticado.
