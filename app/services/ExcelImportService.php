<?php
function parse_input_file(string $path): array
{
    if (class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
        $spreadsheet = PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        return $sheet->toArray();
    }

    $rows = [];
    if (($h = fopen($path, 'r')) !== false) {
        while (($data = fgetcsv($h, 10000, ',')) !== false) {
            $rows[] = $data;
        }
        fclose($h);
    }
    return $rows;
}

function validate_cartera_rows(array $rows): array
{
    $expected = ['nit','nombre_cliente','tipo_documento','numero_documento','fecha_emision','fecha_vencimiento','valor_original','saldo_actual','dias_mora','periodo','canal','regional','asesor_comercial','ejecutivo_cartera','uen','marca'];
    $errors = [];
    if (empty($rows)) {
        return ['ok' => false, 'errors' => [['fila' => 0, 'campo' => 'archivo', 'motivo' => 'Archivo vacío']], 'headers' => []];
    }
    $headers = array_map(fn($h) => strtolower(trim((string)$h)), $rows[0]);
    if ($headers !== $expected) {
        $errors[] = ['fila' => 1, 'campo' => 'columnas', 'motivo' => 'Estructura inválida. Orden esperado: ' . implode(', ', $expected)];
    }
    $dups = [];
    for ($i = 1; $i < count($rows); $i++) {
        $r = array_combine($expected, array_pad($rows[$i], count($expected), ''));
        $excelRow = $i + 1;
        foreach (['nit','numero_documento','fecha_vencimiento','saldo_actual'] as $field) {
            if (trim((string)$r[$field]) === '') {
                $errors[] = ['fila' => $excelRow, 'campo' => $field, 'motivo' => 'Campo crítico vacío'];
            }
        }
        $key = $r['nit'].'|'.$r['tipo_documento'].'|'.$r['numero_documento'];
        if (isset($dups[$key])) {
            $errors[] = ['fila' => $excelRow, 'campo' => 'clave', 'motivo' => 'Duplicado en archivo'];
        }
        $dups[$key] = true;
    }
    return ['ok' => empty($errors), 'errors' => $errors, 'headers' => $expected];
}
