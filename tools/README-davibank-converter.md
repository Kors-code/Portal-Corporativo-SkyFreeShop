# Conversor Davibank

Conversor aislado para transformar el CSV de ventas Davibank en un Excel con una hoja por `FECHA_ABONO`.

## Uso

```powershell
php tools\davibank-converter.php `
  --input="C:\Users\Usuario\Downloads\VENTAS DAVIBANK AL 14-07-2026.csv" `
  --output="tools\output\davibank_prueba_generada.xlsx" `
  --receipt-start=8695
```

## Reglas aplicadas

- Agrupa por `FECHA_ABONO`.
- Nombra cada hoja como `14 JULIO`, `15 JULIO`, etc.
- Incluye las ventas crudas correspondientes al dia.
- Excluye filas `VD` y `RD` con `VALOR_COMISION` igual a `0`.
- Llena solo el cuadro `RECIBO DE CAJA`.
- El numero inicial del recibo se ingresa manualmente con `--receipt-start`.

