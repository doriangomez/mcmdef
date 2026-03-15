<?php
declare(strict_types=1);

function export_xlsx(string $filename, array $headers, array $rows, array $numericColumns = []): void
{
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('La extensión ZipArchive no está disponible para generar XLSX');
    }

    $tmpPath = tempnam(sys_get_temp_dir(), 'xlsx_export_');
    if ($tmpPath === false) {
        http_response_code(500);
        exit('No fue posible crear el archivo temporal para exportar XLSX');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpPath, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmpPath);
        http_response_code(500);
        exit('No fue posible abrir el contenedor XLSX');
    }

    $sheetXml = build_xlsx_sheet_xml($headers, $rows, $numericColumns);

    $zip->addFromString('[Content_Types].xml', xlsx_content_types_xml());
    $zip->addFromString('_rels/.rels', xlsx_root_rels_xml());
    $zip->addFromString('xl/workbook.xml', xlsx_workbook_xml());
    $zip->addFromString('xl/_rels/workbook.xml.rels', xlsx_workbook_rels_xml());
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    $downloadName = preg_replace('/\.xlsx$/i', '', $filename) . '.xlsx';
    $size = filesize($tmpPath) ?: 0;

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . $size);

    readfile($tmpPath);
    @unlink($tmpPath);
}

function build_xlsx_sheet_xml(array $headers, array $rows, array $numericColumns): string
{
    $numericMap = [];
    foreach ($numericColumns as $columnName) {
        $numericMap[(string)$columnName] = true;
    }

    $xmlRows = [];

    $headerCells = [];
    foreach (array_values($headers) as $index => $header) {
        $cellRef = xlsx_column_name($index + 1) . '1';
        $headerCells[] = '<c r="' . $cellRef . '" t="inlineStr"><is><t>' . xlsx_escape((string)$header) . '</t></is></c>';
    }
    $xmlRows[] = '<row r="1">' . implode('', $headerCells) . '</row>';

    $rowNumber = 2;
    foreach ($rows as $row) {
        $cells = [];
        foreach (array_values($headers) as $index => $header) {
            $column = (string)$header;
            $value = $row[$column] ?? '';
            $cellRef = xlsx_column_name($index + 1) . $rowNumber;

            if (isset($numericMap[$column]) && $value !== '' && is_numeric($value)) {
                $cells[] = '<c r="' . $cellRef . '"><v>' . (string)(0 + $value) . '</v></c>';
                continue;
            }

            $cells[] = '<c r="' . $cellRef . '" t="inlineStr"><is><t>' . xlsx_escape((string)$value) . '</t></is></c>';
        }
        $xmlRows[] = '<row r="' . $rowNumber . '">' . implode('', $cells) . '</row>';
        $rowNumber++;
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>'
        . implode('', $xmlRows)
        . '</sheetData>'
        . '</worksheet>';
}

function xlsx_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsx_column_name(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(($index % 26) + 65) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function xlsx_content_types_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';
}

function xlsx_root_rels_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
}

function xlsx_workbook_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Análisis" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';
}

function xlsx_workbook_rels_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';
}
