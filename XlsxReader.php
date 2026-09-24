```php
<?php

declare(strict_types=1);

class XlsxReader
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const PACKAGE_REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    public function read(string $path): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException(
                'PHP extension zip не установлена (нужен ZipArchive).'
            );
        }

        if (!class_exists('SimpleXMLElement')) {
            throw new RuntimeException(
                'PHP extension simplexml не установлена.'
            );
        }

        if (!is_file($path)) {
            throw new RuntimeException(
                'Файл XLSX не найден: ' . $path
            );
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException(
                'Не удалось открыть XLSX: ' . $path
            );
        }

        try {
            $sharedStrings = $this->loadSharedStrings($zip);

            $sheetPath = $this->findFirstSheet($zip);

            $sheetXml = $zip->getFromName($sheetPath);

            if ($sheetXml === false) {
                throw new RuntimeException(
                    'Не найден XML первого листа: ' . $sheetPath
                );
            }

            $xml = simplexml_load_string($sheetXml);

            if ($xml === false) {
                throw new RuntimeException(
                    'Не удалось разобрать XML листа XLSX.'
                );
            }

            $this->registerMainNamespace($xml);

            $rows = [];

            $rowNodes = $xml->xpath('//m:sheetData/m:row');

            if ($rowNodes === false) {
                $rowNodes = [];
            }

            foreach ($rowNodes as $rowNode) {
                $cells = [];

                foreach ($rowNode->c as $cell) {
                    $ref = (string)$cell['r'];

                    if ($ref === '') {
                        continue;
                    }

                    $index = $this->columnIndex($ref);

                    $type = (string)$cell['t'];

                    $value = '';

                    if ($type === 's') {
                        $raw = isset($cell->v)
                            ? (string)$cell->v
                            : '';

                        if ($raw !== '') {
                            $value = $sharedStrings[(int)$raw] ?? '';
                        }
                    } elseif ($type === 'inlineStr') {
                        $value = $this->readInlineString($cell);
                    } elseif ($type === 'b') {
                        $raw = isset($cell->v)
                            ? (string)$cell->v
                            : '';

                        $value = ($raw === '1');
                    } else {
                        $value = isset($cell->v)
                            ? (string)$cell->v
                            : '';
                    }

                    $cells[$index] = $value;
                }

                if (!$cells) {
                    continue;
                }

                $max = max(array_keys($cells));

                $row = [];

                for ($i = 0; $i <= $max; $i++) {
                    $row[] = $cells[$i] ?? '';
                }

                $rows[] = $row;
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

        $this->registerMainNamespace($xml);

        $result = [];

        $items = $xml->xpath('//m:si');

        if ($items === false) {
            return [];
        }

        foreach ($items as $item) {
            $texts = $item->xpath('.//m:t');

            if ($texts === false) {
                $texts = [];
            }

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
            throw new RuntimeException(
                'Некорректный XLSX: отсутствуют workbook.xml или rels.'
            );
        }

        $workbook = simplexml_load_string($workbookText);
        $rels = simplexml_load_string($relsText);

        if ($workbook === false || $rels === false) {
            throw new RuntimeException(
                'Не удалось разобрать workbook XML.'
            );
        }

        $this->registerMainNamespace($workbook);
        $this->registerRelationshipNamespace($workbook);

        $sheets = $workbook->xpath('//m:sheets/m:sheet');

        if ($sheets === false || !$sheets) {
            throw new RuntimeException(
                'В XLSX нет листов.'
            );
        }

        $first = $sheets[0];

        $attributes = $first->attributes(self::REL_NS);

        $rid = $attributes !== null
            ? (string)$attributes['id']
            : '';

        if ($rid === '') {
            throw new RuntimeException(
                'У первого листа XLSX отсутствует r:id.'
            );
        }

        $this->registerPackageRelationshipNamespace($rels);

        $relationNodes = $rels->xpath('//p:Relationship');

        if ($relationNodes === false) {
            $relationNodes = [];
        }

        foreach ($relationNodes as $relation) {
            if ((string)$relation['Id'] !== $rid) {
                continue;
            }

            $target = (string)$relation['Target'];

            if ($target === '') {
                break;
            }

            if ($target[0] === '/') {
                return ltrim($target, '/');
            }

            return 'xl/' . ltrim($target, '/');
        }

        throw new RuntimeException(
            'Не найден relationship для первого листа: ' . $rid
        );
    }

    private function registerMainNamespace(SimpleXMLElement $xml): void
    {
        $xml->registerXPathNamespace('m', self::MAIN_NS);
    }

    private function registerRelationshipNamespace(SimpleXMLElement $xml): void
    {
        $xml->registerXPathNamespace('r', self::REL_NS);
    }

    private function registerPackageRelationshipNamespace(SimpleXMLElement $xml): void
    {
        $xml->registerXPathNamespace('p', self::PACKAGE_REL_NS);
    }

    private function readInlineString(SimpleXMLElement $cell): string
    {
        if (!isset($cell->is)) {
            return '';
        }

        $this->registerMainNamespace($cell);

        $texts = $cell->xpath('.//m:t');

        if ($texts === false || !$texts) {
            return '';
        }

        $parts = [];

        foreach ($texts as $text) {
            $parts[] = (string)$text;
        }

        return implode('', $parts);
    }

    private function columnIndex(string $cellRef): int
    {
        if (!preg_match('/^([A-Z]+)/i', $cellRef, $m)) {
            throw new RuntimeException(
                'Некорректная ссылка ячейки: ' . $cellRef
            );
        }

        $letters = strtoupper($m[1]);

        $index = 0;

        for (
            $i = 0,
            $len = strlen($letters);
            $i < $len;
            $i++
        ) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }
}
```
