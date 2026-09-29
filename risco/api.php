<?php
/**
 * Backend da página: executa SELECT * FROM <função>(...) e devolve JSON, CSV ou XLSX.
 *   api.php?action=meta                 -> parâmetros configurados
 *   api.php?action=run&p[nome]=..       -> resultado em JSON
 *   api.php?action=csv|xlsx&p[nome]=..  -> download
 */
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    fail('Falta o ficheiro config.php (copie config.example.php para config.php).', 500);
}
$config = require $configFile;
require __DIR__ . '/auth.php';
$action = $_GET['action'] ?? 'run';

if ($action === 'meta') {
    json([
        'title'  => $config['title'] ?? 'Análise',
        'params' => array_values(array_map(
            fn($p) => array_intersect_key($p, array_flip(['name', 'label', 'input', 'default', 'min', 'max'])),
            array_filter($config['params'], fn($p) => empty($p['hidden']))
        )),
    ]);
}

try {
    [$columns, $rows, $sql, $ms] = runQuery($config, (array)($_GET['p'] ?? []));
} catch (PDOException $e) {
    fail('Erro da base de dados: ' . $e->getMessage(), 500);
} catch (InvalidArgumentException $e) {
    fail($e->getMessage(), 422);
}

$file = ($config['filename'] ?? 'resultado') . '_' . date('Ymd_His');
switch ($action) {
    case 'run':
        json(['columns' => $columns, 'rows' => $rows, 'sql' => $sql, 'ms' => $ms, 'count' => count($rows)]);
    case 'csv':
        sendCsv($columns, $rows, $file . '.csv');
    case 'xlsx':
        sendXlsx($columns, $rows, $file . '.xlsx');
    default:
        fail('Ação desconhecida.', 400);
}

// ---------------------------------------------------------------------------

function runQuery(array $config, array $input): array
{
    $fn = (string)$config['function'];
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $fn)) {
        throw new InvalidArgumentException('Nome de função inválido na configuração.');
    }
    $placeholders = [];
    $values = [];
    $shown = [];
    foreach ($config['params'] as $p) {
        // parâmetros "hidden" usam sempre o valor fixo do config (não podem ser alterados na página)
        $raw = !empty($p['hidden']) ? (string)($p['default'] ?? '')
            : (isset($input[$p['name']]) ? trim((string)$input[$p['name']]) : '');
        $type = preg_match('/^[a-z][a-z0-9_ ]*(\[\])?$/i', $p['type'] ?? '') ? $p['type'] : null;
        if ($raw === '') {
            $placeholders[] = 'NULL' . ($type ? "::$type" : '');
            $shown[] = 'NULL';
            continue;
        }
        if (in_array($p['input'] ?? '', ['number', 'month'], true) && !is_numeric($raw)) {
            throw new InvalidArgumentException(sprintf('"%s" tem de ser um número.', $p['label'] ?? $p['name']));
        }
        $placeholders[] = '?' . ($type ? "::$type" : '');
        $values[] = $raw;
        $shown[] = is_numeric($raw) ? $raw : "'" . str_replace("'", "''", $raw) . "'";
    }

    $db = $config['db'];
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%d;dbname=%s', $db['host'], $db['port'] ?? 5432, $db['database']),
        $db['user'],
        $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM]
    );
    if (!empty($db['schema'])) {
        $pdo->exec('SET search_path TO ' . $pdo->quote($db['schema']) . ', public');
    }
    $pdo->exec('SET statement_timeout = ' . ((int)($db['timeout'] ?? 300) * 1000));

    $sql = sprintf('SELECT * FROM %s(%s)', $fn, implode(', ', $placeholders));
    $t = microtime(true);
    $pdo->beginTransaction();
    $pdo->exec('SET TRANSACTION READ ONLY');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);

    $columns = [];
    for ($i = 0; $i < $stmt->columnCount(); $i++) {
        $m = $stmt->getColumnMeta($i);
        $columns[] = ['name' => $m['name'], 'type' => $m['native_type'] ?? ''];
    }
    $rows = $stmt->fetchAll();
    $pdo->commit();

    $shownSql = sprintf('SELECT * FROM %s(%s);', $fn, implode(', ', $shown));
    return [$columns, $rows, $shownSql, (int)round((microtime(true) - $t) * 1000)];
}

function isNumericType(string $t): bool
{
    return in_array(strtolower($t), ['int2', 'int4', 'int8', 'numeric', 'float4', 'float8', 'money'], true);
}

function sendCsv(array $columns, array $rows, string $name): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM para o Excel ler UTF-8
    fputcsv($out, array_column($columns, 'name'), ';', '"', '');
    foreach ($rows as $row) {
        foreach ($row as $i => $v) {
            if ($v === null) {
                $row[$i] = '';
            } elseif (is_bool($v)) {
                $row[$i] = $v ? 'true' : 'false';
            } elseif (isNumericType($columns[$i]['type']) && is_numeric($v)) {
                $row[$i] = str_replace('.', ',', (string)$v); // vírgula decimal (Excel PT)
            } elseif (is_string($v) && preg_match('/^[=+\-@\t\r]/', $v)) {
                $row[$i] = "'" . $v; // proteção contra formula injection
            }
        }
        fputcsv($out, $row, ';', '"', '');
    }
    exit;
}

function sendXlsx(array $columns, array $rows, string $name): never
{
    $x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $col = function (int $n): string {
        $s = '';
        for ($n++; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }
        return $s;
    };
    $cell = function ($ref, $v, bool $num, int $style = 0) use ($x) {
        $s = $style ? " s=\"$style\"" : '';
        if ($v === null || $v === '') return "<c r=\"$ref\"$s/>";
        if (is_bool($v)) return "<c r=\"$ref\" t=\"b\"$s><v>" . ($v ? 1 : 0) . '</v></c>';
        if ($num && is_numeric($v)) return "<c r=\"$ref\"$s><v>$v</v></c>";
        return "<c r=\"$ref\" t=\"inlineStr\"$s><is><t xml:space=\"preserve\">" . $x($v) . '</t></is></c>';
    };

    $sheet = '<row r="1">';
    foreach ($columns as $i => $c) $sheet .= $cell($col($i) . '1', $c['name'], false, 1);
    $sheet .= '</row>';
    foreach ($rows as $r => $row) {
        $rn = $r + 2;
        $sheet .= "<row r=\"$rn\">";
        foreach ($row as $i => $v) $sheet .= $cell($col($i) . $rn, $v, isNumericType($columns[$i]['type']));
        $sheet .= '</row>';
    }
    $last = $col(max(count($columns) - 1, 0)) . (count($rows) + 1);

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Resultado" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf/><xf fontId="1" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>' . $sheet . '</sheetData><autoFilter ref="A1:' . $last . '"/></worksheet>');
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

function json(array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail(string $msg, int $code): never
{
    http_response_code($code);
    json(['error' => $msg]);
}
