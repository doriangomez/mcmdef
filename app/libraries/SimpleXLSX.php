<?php

declare(strict_types=1);

namespace Shuchkin;

use DOMDocument;
use DOMXPath;
use ZipArchive;

final class SimpleXLSX
{
    private static string $parseError = '';

    /** @var array<int, array<int, string>> */
    private array $rows = [];

    public static function parse(string $filename): self|false
    {
        $xlsx = new self();
        if (!$xlsx->load($filename)) {
            return false;
        }

        return $xlsx;
    }

    public static function parseError(): string
    {
        return self::$parseError;
    }

    /** @return array<int, array<int, string>> */
    public function rows(): array
    {
        return $this->rows;
    }

    private function load(string $filename): bool
    {
        if (!is_file($filename)) {
            self::$parseError = 'Archivo no encontrado';
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($filename) !== true) {
            self::$parseError = 'No se pudo abrir el archivo XLSX';
            return false;
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml === false) {
            $zip->close();
            self::$parseError = 'No se encontró la hoja principal del XLSX';
            return false;
        }

        $parsedRows = $this->parseSheetRows($sheetXml, $sharedStrings);
        $zip->close();

        if ($parsedRows === null) {
            self::$parseError = 'No se pudo interpretar el contenido del XLSX';
            return false;
        }

        $this->rows = $parsedRows;
        self::$parseError = '';
        return true;
    }

    /** @return array<int, string> */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml)) {
            return [];
        }

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $values = [];
        foreach ($xpath->query('//x:si') ?: [] as $siNode) {
            $textParts = [];
            foreach ($xpath->query('.//x:t', $siNode) ?: [] as $textNode) {
                $textParts[] = $textNode->textContent;
            }
            $values[] = implode('', $textParts);
        }

        return $values;
    }

    /** @param array<int, string> $sharedStrings
     *  @return array<int, array<int, string>>|null
     */
    private function parseSheetRows(string $sheetXml, array $sharedStrings): ?array
    {
        $doc = new DOMDocument();
        if (!@$doc->loadXML($sheetXml)) {
            return null;
        }

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $rows = [];
        $maxCol = 0;
        foreach ($xpath->query('//x:sheetData/x:row') ?: [] as $rowNode) {
            $row = [];
            foreach ($xpath->query('./x:c', $rowNode) ?: [] as $cellNode) {
                $ref = $cellNode->attributes?->getNamedItem('r')?->nodeValue ?? '';
                $colIndex = $this->columnIndexFromRef($ref);
                if ($colIndex < 0) {
                    continue;
                }
                $type = $cellNode->attributes?->getNamedItem('t')?->nodeValue ?? '';
                $valueNode = $xpath->query('./x:v', $cellNode)->item(0);
                $rawValue = $valueNode?->textContent ?? '';

                $value = $rawValue;
                if ($type === 's') {
                    $value = $sharedStrings[(int)$rawValue] ?? '';
                }

                $inline = $xpath->query('./x:is/x:t', $cellNode)->item(0);
                if ($inline) {
                    $value = $inline->textContent;
                }

                $row[$colIndex] = trim((string)$value);
                if ($colIndex > $maxCol) {
                    $maxCol = $colIndex;
                }
            }

            if ($row === []) {
                continue;
            }

            ksort($row);
            $rows[] = $row;
        }

        foreach ($rows as &$row) {
            for ($i = 0; $i <= $maxCol; $i++) {
                if (!array_key_exists($i, $row)) {
                    $row[$i] = '';
                }
            }
            ksort($row);
            $row = array_values($row);
        }

        return $rows;
    }

    private function columnIndexFromRef(string $ref): int
    {
        if ($ref === '' || !preg_match('/^([A-Z]+)\d+$/', strtoupper($ref), $match)) {
            return -1;
        }

        $letters = $match[1];
        $index = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }
}
