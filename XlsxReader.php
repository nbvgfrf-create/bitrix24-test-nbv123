<?php

declare(strict_types=1);

class XlsxReader
{
    public function read(string $path): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP extension zip не установлена (нужен ZipArchive).');
        }
        if (!class_exists('SimpleXMLElement')) {
            throw new RuntimeException('PHP extension simplexml не установлена.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Не удалось открыть XLSX: ' . $path);
        }

        try {
            $sharedStrings = $this->loadSharedStrings($zip);
            $sheetPath = $this->findFirstSheet($zip);
            $sheetXml = $zip->getFromName($sheetPath);
            if ($sheetXml === false) {
                throw new RuntimeException('Не найден XML первого листа: ' . $sheetPath);
            }

            $xml = simplexml_load_string($sheetXml);
            if ($xml === false) {
                throw new RuntimeException('Не удалось разобрать XML листа XLSX.');
            }

            $ns = $xml->getNamespaces(true);
            $main = $ns[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $xml->registerXPathNamespace('m', $main);

            $rows = [];
            $rowNodes = $xml->xpath('//m:sheetData/m:row') ?: [];
            foreach ($rowNodes as $rowNode) {
                $cells = [];
                foreach ($rowNode->c as $cell) {
                    $ref = (string)$cell['r'];
                    $index = $this->columnIndex($ref);
                    $type = (string)$cell['t'];
                    $value = '';

                    if ($type === 'inlineStr') {
                        $value = trim(implode('', $this->extractTexts($cell->is->t ?? null)));
                    } else {
                        $raw = isset($cell->v) ? (string)$cell->v : '';
                        if ($type === 's' && $raw !== '') {
                            $value = $sharedStrings[(int)$raw] ?? '';
                        } elseif ($type === 'b') {
                            $value = $raw === '1';
                        } else {
                            $value = $raw;
                        }
                    }
                    $cells[$index] = $value;
                }

                if ($cells) {
                    $max = max(array_keys($cells));
                    $row = [];
                    for ($i = 0; $i <= $max; $i++) {
                        $row[] = $cells[$i] ?? '';
                    }
                    $rows[] = $row;
                }
            }
            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function loadSharedStrings(ZipArchive $zip): array
    {
        $xmlText = $zip->getFromName('xl/sharedStrings.xml');
        if ($xmlText === false) {
            return [];
        }

        $xml = simplexml_load_string($xmlText);
        if ($xml === false) {
            return [];
        }

        $ns = $xml->getNamespaces(true);
        $main = $ns[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $xml->registerXPathNamespace('m', $main);

        $result = [];
        $items = $xml->xpath('//m:si') ?: [];
        foreach ($items as $item) {
            $texts = $item->xpath('.//m:t') ?: [];
            $parts = [];
            foreach ($texts as $text) {
                $parts[] = (string)$text;
            }
            $result[] = implode('', $parts);
        }
        return $result;
    }

    private function findFirstSheet(ZipArchive $zip): string
    {
        $workbookText = $zip->getFromName('xl/workbook.xml');
        $relsText = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookText === false || $relsText === false) {
            throw new RuntimeException('Некорректный XLSX: отсутствуют workbook.xml или rels.');
        }

        $workbook = simplexml_load_string($workbookText);
        $rels = simplexml_load_string($relsText);
        if ($workbook === false || $rels === false) {
            throw new RuntimeException('Не удалось разобрать workbook XML.');
        }

        $ns = $workbook->getNamespaces(true);
        $main = $ns[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $workbook->registerXPathNamespace('m', $main);
        $relNs = $workbook->getDocNamespaces(true)['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $workbook->registerXPathNamespace('r', $relNs);
        $sheets = $workbook->xpath('//m:sheets/m:sheet') ?: [];
        if (!$sheets) {
            throw new RuntimeException('В XLSX нет листов.');
        }

        $first = $sheets[0];
        $rid = (string)$first->attributes($relNs)['id'];
        $relsNs = $rels->getNamespaces(true);
        $relMain = $relsNs[''] ?? 'http://schemas.openxmlformats.org/package/2006/relationships';
        $rels->registerXPathNamespace('p', $relMain);
        $relationNodes = $rels->xpath('//p:Relationship') ?: [];

        foreach ($relationNodes as $relation) {
            if ((string)$relation['Id'] === $rid) {
                $target = (string)$relation['Target'];
                if ($target !== '' && $target[0] === '/') {
                    return ltrim($target, '/');
                }
                return 'xl/' . ltrim($target, '/');
            }
        }

        throw new RuntimeException('Не найден relationship для первого листа.');
    }

    private function columnIndex(string $cellRef): int
    {
        if (!preg_match('/^([A-Z]+)/i', $cellRef, $m)) {
            throw new RuntimeException('Некорректная ссылка ячейки: ' . $cellRef);
        }

        $letters = strtoupper($m[1]);
        $index = 0;
        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return $index - 1;
    }

    private function extractTexts(?SimpleXMLElement $node): array
    {
        if ($node === null) {
            return [];
        }
        return [(string)$node];
    }
}
