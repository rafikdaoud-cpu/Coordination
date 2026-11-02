<?php

namespace OCA\Coordination\Vendor;

use ZipArchive;
use SimpleXMLElement;
use Exception;

/**
 * Lightweight Zero-dependency XLSX reader for PHP / Nextcloud.
 */
class SimpleXLSX {
    private array $sharedStrings = [];
    private array $sheets = [];

    public static function parse(string $filename): ?self {
        $xlsx = new self();
        if ($xlsx->load($filename)) {
            return $xlsx;
        }
        return null;
    }

    public function load(string $filename): bool {
        if (!file_exists($filename)) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($filename) !== true) {
            return false;
        }

        if ($zip->numFiles > 200) { $zip->close(); return false; }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            $size = (int)($entry['size'] ?? 0);
            $total += $size;
            if ($size > 16*1024*1024 || $total > 32*1024*1024) { $zip->close(); return false; }
        }
        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedStringsXml !== false) {
            $xml = simplexml_load_string($sharedStringsXml);
            if ($xml && isset($xml->si)) {
                foreach ($xml->si as $val) {
                    if (isset($val->t)) {
                        $this->sharedStrings[] = (string)$val->t;
                    } elseif (isset($val->r)) {
                        $text = '';
                        foreach ($val->r as $r) {
                            $text .= (string)$r->t;
                        }
                        $this->sharedStrings[] = $text;
                    } else {
                        $this->sharedStrings[] = '';
                    }
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml === false) {
            $zip->close();
            return false;
        }

        $xml = simplexml_load_string($sheetXml);
        $zip->close();

        if (!$xml || !isset($xml->sheetData->row)) {
            return false;
        }

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $rowData = [];
            foreach ($row->c as $cell) {
                $ref = (string)$cell['r'];
                if (!preg_match('/^[A-Za-z]{1,3}[1-9][0-9]*$/D',$ref)) return false;
                $colLetters = preg_replace('/[0-9]/', '', $ref);
                $colIndex = $this->letterToColumn($colLetters);
                // This import has at most 256 columns; never expand arbitrary cell references.
                if ($colIndex < 0 || $colIndex > 255) return false;

                $val = '';
                $type = (string)$cell['t'];

                if ($type === 's') {
                    $sIndex = (int)$cell->v;
                    $val = $this->sharedStrings[$sIndex] ?? '';
                } elseif ($type === 'b') {
                    $val = ((string)$cell->v === '1') ? 'VRAI' : 'FAUX';
                } elseif (isset($cell->v)) {
                    $val = (string)$cell->v;
                } elseif (isset($cell->is->t)) {
                    $val = (string)$cell->is->t;
                }

                $rowData[$colIndex] = trim($val);
            }

            if (!empty($rowData)) {
                $maxCol = max(array_keys($rowData));
                $fullRow = [];
                for ($i = 0; $i <= $maxCol; $i++) {
                    $fullRow[$i] = $rowData[$i] ?? '';
                }
                $rows[] = $fullRow;
                // Header + 2,000 data rows + one lookahead; the controller rejects overflow.
                if (count($rows) >= 2002) break;
            }
        }

        $this->sheets[0] = $rows;
        return true;
    }

    public function rows(int $sheetIndex = 0): array {
        return $this->sheets[$sheetIndex] ?? [];
    }

    private function letterToColumn(string $letters): int {
        $num = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $num = $num * 26 + (ord(strtoupper($letters[$i])) - ord('A') + 1);
        }
        return $num - 1;
    }
}
