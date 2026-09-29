<?php
declare(strict_types=1);

namespace App\Modules\Exports\Exporters;

/**
 * Dependency-free XLSX (Office Open XML) writer.
 * Rows are streamed to a temporary sheet file (constant memory), then zipped.
 */
final class XlsxExporter extends Exporter
{
    private const MAX_ROWS = 1048575; // Excel limit minus header
    private $sheet;
    private string $sheetPath;
    private int $rowNum = 0;

    public function extension(): string
    {
        return 'xlsx';
    }

    public function contentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function begin(array $columns): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('A extensão PHP "zip" é necessária para exportar XLSX.');
        }
        $this->columns = $columns;
        $this->sheetPath = tempnam(sys_get_temp_dir(), 'qdx');
        $this->sheet = fopen($this->sheetPath, 'wb');
        fwrite($this->sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetData>');
        $this->xmlRow(array_column($columns, 'name'), true);
    }

    public function row(array $row): void
    {
        if ($this->rowNum > self::MAX_ROWS) {
            return;
        }
        $this->xmlRow($row, false);
    }

    private static function colName(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private function xmlRow(array $values, bool $header): void
    {
        $r = ++$this->rowNum;
        $xml = '<row r="' . $r . '">';
        foreach (array_values($values) as $i => $v) {
            $ref = self::colName($i) . $r;
            if ($v === null) {
                continue;
            }
            $style = $header ? ' s="1"' : '';
            // Numbers as numbers — but keep identifiers like "00123" or long codes as text
            if (!$header && (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?(0|[1-9]\d{0,14})(\.\d+)?$/', $v)))) {
                $xml .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
            } elseif (!$header && is_bool($v)) {
                $xml .= '<c r="' . $ref . '" t="b"><v>' . ($v ? 1 : 0) . '</v></c>';
            } else {
                $text = mb_substr(self::scalar($v), 0, 32767);
                $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
                $xml .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">'
                    . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</t></is></c>';
            }
        }
        fwrite($this->sheet, $xml . '</row>');
    }

    public function end(): void
    {
        $cols = count($this->columns);
        $last = self::colName(max(0, $cols - 1)) . max(1, $this->rowNum);
        fwrite($this->sheet, '</sheetData><autoFilter ref="A1:' . $last . '"/></worksheet>');
        fclose($this->sheet);

        $zipPath = tempnam(sys_get_temp_dir(), 'qdz');
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '</Relationships>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>QueryDeck</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>');
        $sheetName = htmlspecialchars(mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', '', (string) ($this->options['sheet'] ?? 'Resultados')) ?: 'Resultados', 0, 31), ENT_XML1);
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $sheetName . '" sheetId="1" r:id="rId1"/></sheets>'
            . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . $sheetName . '\'!$A$1:$' . preg_replace('/\d+$/', '', $last) . '$' . max(1, $this->rowNum) . '</definedName></definedNames></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8EAF0"/></patternFill></fill></fills>'
            . '<borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="2"><xf/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/></cellXfs></styleSheet>');
        $zip->addFile($this->sheetPath, 'xl/worksheets/sheet1.xml');
        $zip->close();

        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        @unlink($this->sheetPath);
    }
}
