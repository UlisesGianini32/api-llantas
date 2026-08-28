# Fase 7F: regla fiscal histórica conservadora

## Arquitectura

La resolución se divide en cuatro responsabilidades:

1. `MeliHistoricalTaxDataService` consulta billing mediante GET-only y normaliza la respuesta.
2. `MeliHistoricalTaxObservationService` cruza hasta 60 orders locales recientes de la misma cuenta con billing, excluye atribuciones ambiguas y produce observaciones sanitizadas.
3. `MeliHistoricalTaxRuleDetector` prueba combinaciones candidatas y sólo acepta una coincidencia única y consistente.
4. `MeliHistoricalTaxRuleService` cachea el resultado seis horas por `meli_account_id`.

No existe tabla nueva ni persistencia del historial fiscal.

## Regla de aceptación

Se exigen al menos 5 orders válidas y 3 publicaciones distintas. Cada observación debe pertenecer a la cuenta, ser mono-item, tener pago aprobado, cero reembolso, venta bruta positiva e IVA e ISR positivos. `tax_status` es opcional porque no apareció en la respuesta real de MLM; si aparece con un estado incompatible se excluye la observación.

En Orders, `gross_price` ya es el total bruto de todas las unidades. Para evitar duplicar cantidades nunca se vuelve a multiplicar. Además, el importe debe coincidir dentro de un centavo con `unit_price × quantity` y con `total_amount` cuando exista. Las orders con descuentos o datos contradictorios se omiten conservadoramente porque esta muestra todavía no demuestra cuál de esos importes usa billing como base fiscal.

El detector no presupone 16%. Evalúa tasas candidatas explícitas de IVA incluido (`0`, `8`, `16`) y tasas candidatas de retención (`0.5`, `1`, `1.25`, `2`, `2.5`, `4`, `6`, `8`, `10`, `16`). Para cada combinación calcula:

```text
base = venta_bruta / (1 + iva_incluido / 100)
iva_estimado = base * retención_iva / 100
isr_estimado = base * retención_isr / 100
```

Cada importe estimado se compara en centavos contra el observado. Se permite una diferencia máxima de 1 centavo por impuesto y order. Una desviación material, cero coincidencias o varias combinaciones coincidentes produce `available=false` y `confidence=insufficient`.

Las tasas candidatas son un espacio interno de validación; no se presentan como tasas oficiales obtenidas de Mercado Libre. La evidencia se etiqueta `derived_from=historical_mercadolibre_billing`.

## Resultado de la muestra real

Las 7 ventas del fixture, correspondientes a 7 publicaciones distintas, determinan inequívocamente:

```text
vat_included_rate = 16.0
vat_withholding_rate = 8.0
income_tax_withholding_rate = 2.5
source = historical_account_tax_rule
confidence = high
```

Para un precio futuro de 699:

```text
base = 602.59
IVA = 48.21
ISR = 15.06
retenciones = 63.27
```

Con cargo por venta de 101.36 y envío de 70, el total estimado de cargos es 234.63 y el neto estimado es 464.37. IVA e ISR se redondean individualmente antes de sumarse.

## Prioridad y auditoría

La simulación usa estas fuentes en orden:

1. regla histórica de alta confianza de la cuenta;
2. perfil fiscal manual habilitado y vigente;
3. no disponible.

El perfil manual no se borra ni modifica: queda como respaldo. El token y las notas de auditoría conservan la fuente, confianza, cantidad de muestras, tasas derivadas, primera y última observación, evidencia, base, IVA, ISR y total utilizados. `tax_withholding` continúa siendo IVA+ISR y `estimated_net` continúa descontando esas retenciones.

La instantánea no incluye comprador, email, teléfono, dirección, RFC, documentos ni tokens OAuth. La fase no cambia la escritura de 7C: el único payload permitido para actualizar precio sigue siendo `{ "price": ... }`.
