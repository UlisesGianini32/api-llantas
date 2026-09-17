# Fase 7E: investigación fiscal histórica read-only

Fecha de investigación: 2026-08-27. Evidencia real validada en servidor: 2026-08-28.

## Evidencia validada

La infraestructura estrictamente read-only se ejecutó en servidor contra la cuenta MLM real. La única ruta de billing utilizada fue:

```text
GET /billing/integration/group/ML/order/details
```

La respuesta normalizada produjo 7 orders útiles, 14 detalles fiscales y 7 publicaciones distintas. Cada order de esta muestra fue mono-item. Los movimientos observados fueron `mov_detail=tax_withholding`, con `mov_financial_entity=iva` o `isr`.

No se guardaron respuestas crudas, credenciales ni datos del comprador. Los fixtures conservan exclusivamente importes e identificadores ficticios.

| Venta bruta | IVA observado | ISR observado | IVA/base | ISR/base |
| ---: | ---: | ---: | ---: | ---: |
| 1001.28 | 69.05 | 21.58 | 7.9996% | 2.5001% |
| 199.00 | 13.72 | 4.29 | 7.9976% | 2.5007% |
| 356.00 | 24.55 | 7.67 | 7.9994% | 2.4992% |
| 298.00 | 20.55 | 6.42 | 7.9993% | 2.4991% |
| 229.00 | 15.79 | 4.94 | 7.9984% | 2.5024% |
| 660.00 | 45.52 | 14.22 | 8.0005% | 2.4993% |
| 735.00 | 50.69 | 15.84 | 8.0001% | 2.4999% |

La relación consistente para esta cuenta fue una base `venta bruta / 1.16`, IVA retenido de 8% sobre esa base e ISR retenido de 2.5%. Es una regla derivada de billing histórico, no una tasa oficial devuelta por Mercado Libre ni una constante global.

## Estructura y límites del dato

`payment_info.tax_details` documenta identificadores e importes (`original_amount`, `refunded_amount`, `mov_detail`, `mov_financial_entity` y, en algunas respuestas, `tax_status`), pero no entrega una tasa ni una base gravable. La respuesta real de MLM no incluyó `tax_status`; por eso su ausencia es válida. Un estado presente que indique cancelación, rechazo, reembolso, reversa o anulación invalida la observación. El normalizador no inventa campos ausentes y conserva `attribution_scope=order_payment`.

La capa de observaciones cruza billing con `meli_orders.raw`, siempre por `meli_account_id`. La documentación oficial de Orders define `gross_price` como el total bruto original de todas las unidades, mediante `(unit_price + discounts.full) × quantity`; nunca se multiplica nuevamente por `quantity`. Para inferencia fiscal sólo se acepta cuando es consistente, dentro de un centavo, con `unit_price × quantity` y con `total_amount` si está disponible. Una diferencia por descuento o un payload inconsistente se excluye hasta validar qué importe constituye la base de retención para ese caso. Si falta `gross_price`, se usa `unit_price × quantity`. No se usa `payment.shipping_cost` ni `payments.taxes_amount`.

Referencia oficial: `https://developers.mercadolibre.com.mx/gestiona-ventas`.

## Garantía de lectura

`MeliHistoricalTaxDataService` usa `MeliAccountApiClient::getReadOnly()`. Esa vía no hace refresh OAuth preventivo ni posterior a 401. Todas las consultas fiscales son GET y fallan de forma segura si el token no es utilizable. No hay persistencia fiscal nueva ni migraciones.

## Alcance pendiente

La regla detectada sólo se habilita para la cuenta cuya muestra la sustenta. No demuestra que las tasas sean universales ni que apliquen a otra cuenta, jurisdicción o contexto fiscal.

Durante la investigación se observó además una venta de 699 con `sale_fee=97.86`, mientras una simulación posterior de `listing_prices` reportó aproximadamente 101.36. Esa diferencia queda pendiente de investigación y no cambia esta fase: la comisión preventiva continúa viniendo de `listing_prices`.
