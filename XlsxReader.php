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
            throw new RuntimeException('PHP extension zip не установлена (нужен ZipArchive).');
        }

        if (!class_exists('SimpleXMLElement')) {
            throw new RuntimeException('PHP extension simplexml не установлена.');
        }

        if (!is_file($path)) {
            throw new RuntimeException('Файл XLSX не найден: ' . $path);
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

            $main = $xml->children(self::MAIN_NS);
            $sheetData = $main->sheetData;

            if (!isset($sheetData)) {
                throw new RuntimeException('В первом листе XLSX отсутствует sheetData.');
            }

            $rows = [];

            foreach ($sheetData->row as $rowNode) {
                $cells = [];

                foreach ($rowNode->children(self::MAIN_NS) as $cell) {
                    if ($cell->getName() !== 'c') {
                        continue;
                    }

                    $attributes = $cell->attributes();
                    $ref = isset($attributes['r']) ? (string)$attributes['r'] : '';

                    if ($ref === '') {
                        continue;
                    }

                    $index = $this->columnIndex($ref);
                    $type = isset($attributes['t']) ? (string)$attributes['t'] : '';
                    $value = '';
                    $cellMain = $cell->children(self::MAIN_NS);

                    if ($type === 's') {
                        $raw = isset($cellMain->v) ? (string)$cellMain->v : '';

                        if ($raw !== '') {
                            $value = $sharedStrings[(int)$raw] ?? '';
                        }
                    } elseif ($type === 'inlineStr') {
                        $value = $this->readInlineString($cell);
                    } elseif ($type === 'b') {
                        $raw = isset($cellMain->v) ? (string)$cellMain->v : '';
                        $value = ($raw === '1');
                    } else {
                        $value = isset($cellMain->v) ? (string)$cellMain->v : '';
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

        $main = $xml->children(self::MAIN_NS);
        $result = [];

        foreach ($main->si as $item) {
            $parts = [];
            $itemMain = $item->children(self::MAIN_NS);

            foreach ($itemMain->t as $text) {
                $parts[] = (string)$text;
            }

            foreach ($itemMain->r as $run) {
                $runMain = $run->children(self::MAIN_NS);
                if (isset($runMain->t)) {
                    $parts[] = (string)$runMain->t;
                }
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

        $workbookMain = $workbook->children(self::MAIN_NS);
        $sheets = $workbookMain->sheets;

        if (!isset($sheets->sheet) || count($sheets->sheet) === 0) {
            throw new RuntimeException('В XLSX нет листов.');
        }

        $first = $sheets->sheet[0];
        $sheetAttributes = $first->attributes(self::REL_NS);
        $rid = isset($sheetAttributes['id']) ? (string)$sheetAttributes['id'] : '';

        if ($rid === '') {
            throw new RuntimeException('У первого листа XLSX отсутствует r:id.');
        }

        $relsMain = $rels->children(self::PACKAGE_REL_NS);

        foreach ($relsMain->Relationship as $relation) {
            $attributes = $relation->attributes();
            $id = isset($attributes['Id']) ? (string)$attributes['Id'] : '';

            if ($id !== $rid) {
                continue;
            }

            $target = isset($attributes['Target']) ? (string)$attributes['Target'] : '';

            if ($target === '') {
                break;
            }

            if ($target[0] === '/') {
                return ltrim($target, '/');
            }

            return 'xl/' . ltrim($target, '/');
        }

        throw new RuntimeException('Не найден relationship для первого листа: ' . $rid);
    }

    private function readInlineString(SimpleXMLElement $cell): string
    {
        $cellMain = $cell->children(self::MAIN_NS);

        if (!isset($cellMain->is)) {
            return '';
        }

        $parts = [];
        $inlineMain = $cellMain->is->children(self::MAIN_NS);

        foreach ($inlineMain->t as $text) {
            $parts[] = (string)$text;
        }

        foreach ($inlineMain->r as $run) {
            $runMain = $run->children(self::MAIN_NS);
            if (isset($runMain->t)) {
                $parts[] = (string)$runMain->t;
            }
        }

        return implode('', $parts);
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
}
