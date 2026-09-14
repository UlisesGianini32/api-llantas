# Etiquetas Mercado Libre

Ruta: `/mercado-libre/etiquetas`. El módulo está disponible para usuarios `admin` y `operations`.

## Objetivo y formatos

La pantalla procesa los TXT ZPL descargados de Mercado Libre para dos tipos de trabajo:

- Productos, por ejemplo `Envio-76771745-Etiquetas-de-productos.txt`.
- Bultos, cajas o guías, por ejemplo `Envio-76771745-Etiquetas-de-bultos.txt`.

El tipo se detecta primero por el nombre, sin distinguir mayúsculas. Si el nombre no es concluyente, se buscan marcadores inequívocos en el contenido. Un archivo ambiguo se rechaza; nunca se imprime automáticamente. El número de envío se extrae del patrón `Envio-{número}` y, si no existe, la UI muestra una referencia corta del hash.

## Flujo TXT/ZPL

1. El servidor valida extensión `.txt`, MIME de texto/binario, máximo 5 MB y máximo 500 etiquetas.
2. `MeliLabelParser` separa únicamente bloques completos `^XA...^XZ`. Rechaza texto/comandos fuera de ellos, bloques anidados o truncados.
3. Cada `^PQ` compatible se reemplaza por `^PQ1,0,1,Y`. Si falta `^PQ`, se agrega inmediatamente antes de `^XZ`; los datos y gráficos restantes no se modifican.
4. Se calcula SHA-256 sobre los bytes del archivo original. El TXT y el ZPL no se guardan permanentemente.
5. El navegador recibe cada bloque normalizado en Base64 y lo envía, sólo después de pulsar **Imprimir**, como comando RAW mediante QZ Tray.

No existe conversión a PDF, PNG, imagen o HTML. QZ envía una etiqueta por llamada, en secuencia y con `copies: 1`.

## QZ Tray e impresoras

La configuración compartida usa los endpoints `/qz/certificate` y `/qz/sign` y los archivos existentes:

```text
storage/app/private/qz/digital-certificate.txt
storage/app/private/qz/private-key.pem
```

No deben regenerarse ni moverse. La pantalla muestra si QZ está conectado, detecta todas las impresoras instaladas y permite elegir cualquiera. Prefiere `4BARCODE 4B-2054A` cuando existe y recuerda la elección en `localStorage`; no hay una impresora global obligatoria.

QZ confirma que el trabajo llegó a la cola de impresión, no que salió físicamente. Si una llamada falla, el lote se detiene, muestra la etiqueta exacta y la marca como no confirmada. Antes de reintentar se debe revisar la impresora, porque el spooler pudo aceptar el trabajo antes de la desconexión.

## Historial y reimpresión

La tabla `meli_label_prints` guarda sólo metadatos: envío, tipo, nombre original, hash, cantidad, impresora, estado, usuario y fechas. No guarda ZPL.

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

Esta versión requiere crear el historial:

```bash
php artisan migrate
```

Pruebas relevantes:

```bash
php artisan test --filter=MeliLabel
node --test tests/Unit/meliLabelPrinting.test.js
npm run build
```

La prueba física recomendada comienza con un TXT que contenga una sola etiqueta y continúa con un lote pequeño de productos y otro de bultos. Confirmar cantidad, una sola copia, códigos legibles, progreso e historial antes de procesar lotes reales grandes. También conviene verificar AMS principal/secundaria, KAMO, Zebra, 4BARCODE y RawBT después del despliegue; comparten la configuración QZ pero conservan sus handlers y formatos.
