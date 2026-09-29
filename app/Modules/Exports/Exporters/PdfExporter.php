<?php
declare(strict_types=1);

namespace App\Modules\Exports\Exporters;

/**
 * Minimal dependency-free PDF table writer (A4 landscape, Helvetica).
 * Intended for printable/shareable result snapshots: capped at MAX_ROWS rows
 * and long cell values are truncated. Use CSV/XLSX for full data.
 */
final class PdfExporter extends Exporter
{
    public const MAX_ROWS = 5000;
    private const W = 842.0, H = 595.0, M = 28.0, FS = 7.0, RH = 11.0;

    private array $rows = [];
    private bool $truncated = false;

    public function extension(): string
    {
        return 'pdf';
    }

    public function contentType(): string
    {
        return 'application/pdf';
    }

    public function begin(array $columns): void
    {
        $this->columns = $columns;
    }

    public function row(array $row): void
    {
        if (count($this->rows) >= self::MAX_ROWS) {
            $this->truncated = true;
            return;
        }
        $this->rows[] = array_map([self::class, 'scalar'], $row);
    }

    private static function enc(string $s): string
    {
        $s = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        $s = $s === false ? '' : $s;
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => ' ', "\n" => ' ', "\t" => ' ']);
    }

    private static function fit(string $s, float $width, float $fs): string
    {
        $max = max(1, (int) floor($width / ($fs * 0.5)));
        return mb_strlen($s) > $max ? mb_substr($s, 0, max(1, $max - 1)) . '…' : $s;
    }

    public function end(): void
    {
        $names = array_column($this->columns, 'name');
        $n = max(1, count($names));
        $avail = self::W - 2 * self::M;

        // Column widths proportional to content length (sampled), bounded
        $len = array_map(static fn($h) => max(4, min(40, mb_strlen((string) $h))), $names);
        foreach (array_slice($this->rows, 0, 200) as $r) {
            foreach ($r as $i => $v) {
                $len[$i] = max($len[$i] ?? 4, min(40, mb_strlen($v)));
            }
        }
        $total = array_sum($len) ?: 1;
        $widths = array_map(static fn($l) => $avail * $l / $total, $len);

        $title = (string) ($this->options['title'] ?? 'QueryDeck export');
        $subtitle = sprintf('%s · %d linhas%s', date('Y-m-d H:i'), count($this->rows),
            $this->truncated ? ' (limitado a ' . self::MAX_ROWS . ' linhas no PDF)' : '');

        $perPage = (int) floor((self::H - 2 * self::M - 40) / self::RH);
        $chunks = $this->rows ? array_chunk($this->rows, $perPage) : [[]];
        $pages = [];
        foreach ($chunks as $p => $chunk) {
            $y = self::H - self::M;
            $s = "BT /F2 11 Tf " . self::M . " $y Td (" . self::enc($title) . ") Tj ET\n";
            $y -= 13;
            $s .= "0.45 0.47 0.55 rg BT /F1 7 Tf " . self::M . " $y Td (" . self::enc($subtitle) . ") Tj ET 0 0 0 rg\n";
            $y -= 16;
            // header background
            $s .= sprintf("0.91 0.92 0.95 rg %.2F %.2F %.2F %.2F re f 0 0 0 rg\n", self::M, $y - 3, $avail, self::RH);
            $x = self::M;
            foreach ($names as $i => $h) {
                $s .= sprintf("BT /F2 %.1F Tf %.2F %.2F Td (%s) Tj ET\n", self::FS, $x + 2, $y, self::enc(self::fit((string) $h, $widths[$i] - 4, self::FS)));
                $x += $widths[$i];
            }
            foreach ($chunk as $k => $row) {
                $y -= self::RH;
                if ($k % 2 === 1) {
                    $s .= sprintf("0.97 0.97 0.98 rg %.2F %.2F %.2F %.2F re f 0 0 0 rg\n", self::M, $y - 3, $avail, self::RH);
                }
                $x = self::M;
                foreach ($names as $i => $_) {
                    $s .= sprintf("BT /F1 %.1F Tf %.2F %.2F Td (%s) Tj ET\n", self::FS, $x + 2, $y,
                        self::enc(self::fit($row[$i] ?? '', $widths[$i] - 4, self::FS)));
                    $x += $widths[$i];
                }
            }
            $s .= sprintf("0.45 0.47 0.55 rg BT /F1 7 Tf %.2F %.2F Td (%s) Tj ET\n", self::W - self::M - 60, self::M - 12,
                self::enc('Página ' . ($p + 1) . ' / ' . count($chunks)));
            $pages[] = $s;
        }

        // Assemble PDF objects
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $next = 5;
        $pageObjs = [];
        foreach ($pages as $content) {
            $pageId = $next++;
            $contentId = $next++;
            $kids[] = "$pageId 0 R";
            $pageObjs[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.0F %.0F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::W, self::H, $contentId);
            $pageObjs[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects += $pageObjs;
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
    }
}
