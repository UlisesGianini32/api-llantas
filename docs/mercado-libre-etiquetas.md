# Etiquetas Mercado Libre

Ruta: `/mercado-libre/etiquetas`. El módulo está disponible para usuarios `admin` y `operations`.

## Objetivo y formatos

La pantalla procesa los TXT ZPL descargados de Mercado Libre para dos tipos de trabajo:

- Productos, por ejemplo `Envio-76771745-Etiquetas-de-productos.txt`.
- Bultos, cajas o guías, por ejemplo `Envio-76771745-Etiquetas-de-bultos.txt`.

El tipo se detecta primero por el nombre, sin distinguir mayúsculas. Si el nombre no es concluyente, se buscan marcadores inequívocos en el contenido. Un archivo ambiguo se rechaza; nunca se imprime automáticamente. El número de envío se extrae del patrón `Envio-{número}` y, si no existe, la UI muestra una referencia corta del hash.

## Flujo TXT/ZPL

1. El servidor valida extensión `.txt`, MIME de texto/binario, máximo 5 MB, máximo 500 bloques ZPL y máximo 10,000 etiquetas físicas.
2. `MeliLabelParser` separa únicamente bloques completos `^XA...^XZ`. Rechaza texto/comandos fuera de ellos, bloques anidados o truncados.
3. En archivos de productos, el primer parámetro de `^PQ` es la cantidad física solicitada por Mercado Libre. Se valida y se conserva sin convertirlo a una copia. Si falta, la cantidad es uno.
4. Cada producto se normaliza a formato horizontal 2 × 1 para 203 dpi mediante `^PW406` y `^LL203`. Los comandos existentes se reemplazan una sola vez; no se agrega `^POR` ni se alteran `^LH`, `^FO`, `^FT`, `^FB`, `^FD`, `^FH`, SKU, códigos o gráficos.
5. En archivos de bultos, cada bloque sigue representando una guía: cualquier `^PQ` compatible se reemplaza por `^PQ1,0,1,Y` y, si falta, se agrega antes de `^XZ`. No se aplican las dimensiones 2 × 1.
6. Se calcula SHA-256 sobre los bytes del archivo original. El TXT y el ZPL no se guardan permanentemente.
7. El navegador recibe cada bloque normalizado en Base64 y lo envía, sólo después de pulsar **Imprimir**, como comando RAW mediante QZ Tray.

No existe conversión a PDF, PNG, imagen o HTML. QZ envía cada bloque una sola vez, en secuencia y con `copies: 1`. En productos, sólo el `^PQ` incluido en el ZPL gobierna las copias físicas; nunca se multiplica mediante la configuración QZ.

## Bloques ZPL y etiquetas físicas

Los conteos tienen significados distintos:

- `zpl_blocks_count`: productos/diseños o guías diferentes enviados a QZ.
- `physical_labels_count`: suma de los `^PQ` de productos; en bultos coincide con los bloques.
- `labels_count`: columna histórica conservada como número de bloques para compatibilidad.

Por ejemplo, el lote real con 15 bloques y cantidades `4, 12, 12, 6, 6, 12, 6, 20, 20, 12, 12, 12, 12, 12, 6` contiene 164 etiquetas físicas. La UI muestra ambos valores y el progreso avanza 4/164, 16/164, etc., cuando QZ acepta cada bloque.

## QZ Tray e impresoras

La configuración compartida usa los endpoints `/qz/certificate` y `/qz/sign` y los archivos existentes:

```text
storage/app/private/qz/digital-certificate.txt
storage/app/private/qz/private-key.pem
```

No deben regenerarse ni moverse. La pantalla muestra si QZ está conectado, detecta todas las impresoras instaladas y permite elegir cualquiera. Prefiere `4BARCODE 4B-2054A` cuando existe y recuerda la elección en `localStorage`; no hay una impresora global obligatoria.

QZ confirma que el trabajo llegó a la cola de impresión, no que salió físicamente. Si una llamada falla, el lote se detiene, identifica el bloque y lo marca como incierto. Antes de reintentar se debe revisar la impresora, porque el spooler pudo aceptar todas las copias indicadas por ese `^PQ` antes de la desconexión. El retry excluye los bloques ya confirmados.

## Historial y reimpresión

La tabla `meli_label_prints` guarda sólo metadatos: envío, tipo, nombre original, hash, bloques ZPL, etiquetas físicas, impresora, estado, usuario y fechas. No guarda ZPL. Una migración aditiva conserva `labels_count` y hace backfill de registros anteriores hacia los dos conteos explícitos; como el flujo anterior forzaba una copia por bloque, ambos valores históricos son iguales y correctos.

- Al analizar se crea un registro `analyzed`; esto no significa que se haya impreso.
- Sólo después de que todas las promesas de impresión QZ terminan se registra `printed` y `printed_at`.
- Un fallo durante el envío se registra como `failed`, con un mensaje breve y sin contenido ZPL.
- Una carga cuyo SHA-256 ya tiene una impresión exitosa muestra los detalles previos y exige pulsar **Reimprimir** antes de habilitar el envío. El servidor vuelve a exigir esa confirmación al registrar el resultado.

Productos y bultos pueden coexistir bajo el mismo número de envío. El historial es compartido entre operadores para que la protección también funcione entre equipos. Como no se almacena el payload, la tabla muestra “Cargar nuevamente el TXT para reimprimir” y no presenta un botón inoperante.

## Solución de problemas

- **QZ desconectado:** abrir QZ Tray, aceptar la conexión firmada y volver a detectar impresoras.
- **Impresora ausente:** comprobar que Windows la tenga instalada y encendida; después volver a detectar. La selección recordada sólo se usa si sigue instalada.
- **Tipo no identificado:** usar el TXT original de Mercado Libre con el nombre completo; no renombrarlo a un nombre genérico.
- **ZPL inválido o más de una copia:** no editar manualmente el TXT. El parser rechaza `^PQ` no compatible en vez de arriesgar copias adicionales.
- **Fallo durante una etiqueta:** revisar salida y cola física antes de usar el reintento. La etiqueta fallida se considera incierta.
- **Historial no guardado después de completar QZ:** no repetir hasta comprobar físicamente la impresora; el navegador muestra una advertencia específica.

## Despliegue y pruebas

Esta versión requiere agregar y completar los conteos explícitos del historial:

```bash
php artisan migrate --force
```

Pruebas relevantes:

```bash
php artisan test --filter=MeliLabel
node --test tests/Unit/meliLabelPrinting.test.js
npm run build
```

La prueba física recomendada comienza con un solo bloque de producto. En una copia temporal del TXT, usar `^PQ1,0,1,Y` exclusivamente para validar `^PW406`, `^LL203`, orientación horizontal, dimensiones, códigos, nombre y SKU. No usar el lote de 164 para esta comprobación inicial. Después se restaura el `^PQ` real y se valida el lote completo observando 15 productos/diseños, 164 etiquetas y progreso acumulado. También conviene verificar un lote pequeño de bultos y los flujos AMS principal/secundaria, KAMO, Zebra, 4BARCODE y RawBT; su configuración QZ, handlers y formatos no cambian.
