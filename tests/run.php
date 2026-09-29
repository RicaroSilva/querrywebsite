<?php
declare(strict_types=1);

/**
 * Dependency-free test runner:  php tests/run.php
 * Covers the security- and correctness-critical pure logic (no DB server needed;
 * integration tests against SQLite run in a temporary directory).
 */

putenv('APP_KEY=base64:' . base64_encode(str_repeat('k', 32)));
putenv('APP_DB_DRIVER=sqlite');
putenv('APP_DB_SQLITE_PATH=' . sys_get_temp_dir() . '/qd-test-' . getmypid() . '.sqlite');
require dirname(__DIR__) . '/app/bootstrap.php';

$passed = 0;
$failed = [];
function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "  \e[32m✔\e[0m $name\n";
    } catch (Throwable $e) {
        $failed[] = $name;
        echo "  \e[31m✘ $name\e[0m\n    " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}
function eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function ok(bool $cond, string $msg = 'assertion failed'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

use App\Core\Crypto;
use App\Core\HttpException;
use App\Core\Validator;
use App\Modules\Execution\Placeholders;
use App\Modules\Execution\SqlSplitter as Sp;

echo "SqlSplitter\n";
test('splits on semicolons and reports lines', function () {
    $r = Sp::split("SELECT 1;\n\nSELECT 2;\nSELECT 3");
    eq(3, count($r));
    eq([1, 3, 4], array_column($r, 'line'));
    eq('SELECT 2', $r[1]['sql']);
});
test('ignores semicolons in strings, identifiers and comments', function () {
    $r = Sp::split("SELECT 'a;b', \"c;d\" -- x;y\nFROM t /* ; */;SELECT 2");
    eq(2, count($r));
});
test('handles doubled quotes', function () {
    eq(2, count(Sp::split("SELECT 'it''s; ok';SELECT 1")));
});
test('handles PostgreSQL dollar quoting', function () {
    $sql = "CREATE FUNCTION f() RETURNS int AS \$body\$ BEGIN RETURN 1; END; \$body\$ LANGUAGE plpgsql;\nSELECT f();";
    eq(2, count(Sp::split($sql, 'semicolon', 'pgsql')));
});
test('handles MySQL DELIMITER and backslash escapes', function () {
    $sql = "SELECT 'a\\';b';\nDELIMITER //\nCREATE PROCEDURE p() BEGIN SELECT 1; SELECT 2; END//\nDELIMITER ;\nCALL p();";
    $r = Sp::split($sql, 'semicolon', 'mysql');
    eq(3, count($r));
    ok(str_contains($r[1]['sql'], 'SELECT 2;'), 'procedure body kept whole');
});
test('T-SQL batch mode splits only on GO', function () {
    $r = Sp::split("DECLARE @x int = 1;\nSELECT @x;\nGO\nSELECT 2\ngo", 'batch', 'sqlsrv');
    eq(2, count($r));
    eq(4, $r[1]['line']);
});
test('skips empty/comment-only statements', function () {
    eq(1, count(Sp::split("-- only comment;\n;;SELECT 1;")));
});
test('firstKeyword skips comments and parentheses', function () {
    eq('SELECT', Sp::firstKeyword("/* hi */ -- x\n (SELECT 1)"));
});

echo "Read-only classifier\n";
foreach ([
    'SELECT * FROM t' => true, 'with x as (select 1) select * from x' => true, 'SHOW TABLES' => true,
    'EXPLAIN SELECT 1' => true, "SELECT 'delete from t'" => true, 'PRAGMA table_info(t)' => true,
    'UPDATE t SET a=1' => false, 'DELETE FROM t' => false, 'WITH d AS (DELETE FROM t RETURNING *) SELECT * FROM d' => false,
    'SELECT * INTO new_t FROM t' => false, 'SELECT * FROM t FOR UPDATE' => false, 'EXPLAIN ANALYZE DELETE FROM t' => false,
    "SELECT set_config('default_transaction_read_only','off',false)" => false, 'PRAGMA query_only = 0' => false,
    'DROP TABLE t' => false, 'CALL p()' => false, "SELECT * FROM dblink('x','y')" => false, 'SET ROLE admin' => false,
] as $sql => $expected) {
    test(($expected ? 'allows ' : 'blocks ') . $sql, fn() => eq($expected, Sp::isReadOnly($sql)));
}

echo "Placeholders\n";
test('binds named placeholders as positional params', function () {
    [$sql, $p] = Placeholders::bind('SELECT * FROM t WHERE a >= {{start}} AND b = {{ name }} AND c = {{start}}',
        [['name' => 'start', 'type' => 'date']], ['start' => '2026-01-01', 'name' => "x' OR 1=1 --"]);
    eq('SELECT * FROM t WHERE a >= ? AND b = ? AND c = ?', $sql);
    eq(['2026-01-01', "x' OR 1=1 --", '2026-01-01'], $p);
});
test('casts numbers and rejects invalid dates', function () {
    [, $p] = Placeholders::bind('{{n}} {{d}} {{a}} {{z}}', [['name' => 'n', 'type' => 'number'], ['name' => 'd', 'type' => 'date']],
        ['n' => '42', 'd' => 'yesterday', 'a' => '7', 'z' => '007']);
    eq([42, null, 7, '007'], $p);
});
test('lists distinct names', fn() => eq(['a', 'b'], Placeholders::names('{{a}} {{b}} {{a}}')));

echo "Crypto\n";
test('encrypts and decrypts', function () {
    $c = Crypto::encrypt('s3cr3t');
    ok($c !== 's3cr3t' && !str_contains($c, 's3cr3t'));
    eq('s3cr3t', Crypto::decrypt($c));
});
test('uses a random nonce', fn() => ok(Crypto::encrypt('x') !== Crypto::encrypt('x')));
test('detects tampering', function () {
    $c = base64_decode(Crypto::encrypt('hello'));
    $c[strlen($c) - 1] = chr(ord($c[strlen($c) - 1]) ^ 1);
    try {
        Crypto::decrypt(base64_encode($c));
        throw new LogicException('no exception');
    } catch (RuntimeException) {
    }
});

echo "Validator\n";
test('validates and casts', function () {
    $d = Validator::validate(['name' => ' A ', 'n' => '5', 'f' => 'yes'], ['name' => 'required|string|max:5', 'n' => 'int', 'f' => 'bool']);
    eq(['name' => 'A', 'n' => 5, 'f' => true], $d);
});
test('reports errors per field', function () {
    try {
        Validator::validate(['email' => 'nope'], ['email' => 'required|email', 'role' => 'required|in:a,b']);
        throw new LogicException('no exception');
    } catch (HttpException $e) {
        eq(422, $e->status);
        eq(['email', 'role'], array_keys($e->errors));
    }
});

echo "Drivers\n";
test('rejects DSN injection in database names', function () {
    try {
        App\Modules\Drivers\AbstractPdoDriver::assertSafeDsnValue('db;host=evil', 'database');
        throw new LogicException('no exception');
    } catch (InvalidArgumentException) {
    }
});
test('quotes identifiers per dialect', function () {
    eq('"a""b"', (new App\Modules\Drivers\PostgresDriver([]))->quoteIdentifier('a"b'));
    eq('`a``b`', (new App\Modules\Drivers\MySqlDriver([]))->quoteIdentifier('a`b'));
    eq('[a]]b]', (new App\Modules\Drivers\SqlServerDriver([]))->quoteIdentifier('a]b'));
});
test('SQLite paths are confined to allowed directories', function () {
    try {
        App\Modules\Drivers\SqliteDriver::resolvePath('/etc/passwd');
        throw new LogicException('no exception');
    } catch (RuntimeException $e) {
        ok(!$e instanceof LogicException);
    }
});
test('PostgreSQL error parsing extracts line and column', function () {
    $e = new PDOException('x');
    $e->errorInfo = ['42703', 7, "ERROR:  column \"nme\" does not exist\nLINE 2:   nme\n          ^"];
    $err = (new App\Modules\Drivers\PostgresDriver([]))->parseError($e, "SELECT id,\n  nme\nFROM t");
    eq(2, $err['line']);
    eq(3, $err['column']);
    eq('42703', $err['code']);
});

echo "Integration (SQLite)\n";
test('migrations, execution, result paging and history', function () {
    $out = static fn() => null;
    (new App\Core\Migrator(App\Core\Database::get()))->run($out);
    $users = new App\Modules\Users\UserRepository();
    $uid = $users->create(['name' => 'T', 'email' => 't@example.com', 'password' => 'password-123', 'role' => 'admin']);
    ok(password_verify('password-123', $users->find($uid)['password_hash']), 'password hashed');

    $dir = storage_path('sqlite');
    $file = $dir . '/test-' . getmypid() . '.sqlite';
    $pdo = new PDO('sqlite:' . $file);
    $pdo->exec('CREATE TABLE n (i INTEGER, s TEXT)');
    $pdo->beginTransaction();
    for ($i = 1; $i <= 250; $i++) {
        $pdo->exec("INSERT INTO n VALUES ($i, 'row $i')");
    }
    $pdo->commit();
    $pdo = null;

    $db = App\Core\Database::get();
    $cid = $db->insert('connections', ['name' => 'T', 'driver' => 'sqlite', 'database_name' => $file, 'options' => '{}',
        'environment' => 'local', 'read_only' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $conn = (new App\Modules\Connections\ConnectionRepository())->find($cid);
    $r = (new App\Modules\Execution\QueryExecutor())->run($conn, "SELECT * FROM n ORDER BY i;\nUPDATE n SET s = 'x' WHERE i = 1;\nSELEC oops;",
        ['user_id' => $uid, 'max_rows' => 200, 'page_size' => 50]);
    eq('error', $r['status']);
    eq(['rows', 'command', 'error'], array_column($r['results'], 'type'));
    eq(200, $r['results'][0]['row_count']);
    ok($r['results'][0]['truncated'], 'truncated at max_rows');
    eq(50, count($r['results'][0]['rows']));
    eq(1, $r['results'][1]['affected']);
    eq(3, $r['results'][2]['error']['abs_line']);

    $page = App\Modules\Execution\ResultStore::query($r['results'][0]['result_id'], $uid, ['page' => 2, 'per_page' => 10, 'sort' => 0, 'dir' => 'desc', 'filters' => [0 => '>100']]);
    eq(100, $page['filtered']);
    eq(190, $page['rows'][0][0]);
    try {
        App\Modules\Execution\ResultStore::query($r['results'][0]['result_id'], $uid + 1, []);
        throw new LogicException('other user could read result');
    } catch (HttpException $e) {
        eq(403, $e->status);
    }
    $ro = (new App\Modules\Execution\QueryExecutor())->run($conn, 'DELETE FROM n', ['user_id' => $uid, 'read_only' => true]);
    eq('READ_ONLY', $ro['results'][0]['error']['code']);
    eq(2, (int) $db->value('SELECT COUNT(*) FROM query_history WHERE user_id = ?', [$uid]));
    @unlink($file);
});

echo "AI assistant\n";
test('extracts SQL from fenced blocks, JSON and plain replies', function () {
    [$sql, $exp] = App\Modules\Assistant\AssistantService::extractSql("Aqui está:\n```sql\nSELECT 1;\n```\nSimples.");
    eq('SELECT 1', $sql);
    ok(str_contains((string) $exp, 'Simples'));
    eq('SELECT 2', App\Modules\Assistant\AssistantService::extractSql('{"sql":"SELECT 2","explanation":"x"}')[0]);
    eq('WITH a AS (SELECT 1) SELECT * FROM a', App\Modules\Assistant\AssistantService::extractSql("WITH a AS (SELECT 1) SELECT * FROM a;\n\nfeito")[0]);
    eq(null, App\Modules\Assistant\AssistantService::extractSql('Não sei.')[0]);
});
test('blocks writes, auto-fixes SQL errors and answers', function () {
    $db = App\Core\Database::get();
    $file = storage_path('sqlite') . '/ai-' . getmypid() . '.sqlite';
    $pdo = new PDO('sqlite:' . $file);
    $pdo->exec("CREATE TABLE sales (person TEXT, amount NUMERIC); INSERT INTO sales VALUES ('Ana', 10), ('Rui', 30), ('Ana', 5)");
    $pdo = null;
    $cid = $db->insert('connections', ['name' => 'AI', 'driver' => 'sqlite', 'database_name' => $file, 'options' => '{}',
        'environment' => 'local', 'read_only' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $conn = (new App\Modules\Connections\ConnectionRepository())->find($cid);
    $fake = new class () extends App\Modules\Assistant\AiClient {
        public array $calls = [];
        public function __construct() {}
        public function chat(array $messages, string $model, float $temperature = 0.1): string
        {
            $this->calls[] = $messages;
            $n = count($this->calls);
            return match ($n) {
                1 => "```sql\nDELETE FROM sales\n```",                         // refused (write)
                2 => "```sql\nSELECT person, SUM(amont) FROM sales GROUP BY 1\n```", // SQL error → fed back
                3 => "```sql\nSELECT person, SUM(amount) AS total FROM sales GROUP BY person ORDER BY total DESC LIMIT 1\n```",
                default => 'Foi o Rui, com 30.',
            };
        }
    };
    $uid = (int) $db->value('SELECT MIN(id) FROM users');
    $r = (new App\Modules\Assistant\AssistantService($fake))->ask($conn, null, 'Quem mais faturou?', [], $uid, 'ask', null, 'm');
    eq('Foi o Rui, com 30.', $r['answer']);
    eq([['Rui', 30]], $r['result']['rows']);
    eq(2, count($r['attempts']));
    ok(str_contains($r['attempts'][0]['error'], 'read-only'), 'write refused');
    ok(str_contains($fake->calls[0][0]['content'], "TABLE sales\n  (person TEXT, amount NUMERIC)"), 'schema in prompt');
    ok(str_contains(end($fake->calls)[1]['content'], "Rui\t30"), 'result rows sent for the answer');
    eq(3, (int) (new PDO('sqlite:' . $file))->query('SELECT COUNT(*) FROM sales')->fetchColumn(), 'data untouched');
    @unlink($file);
});

echo "Analyses\n";
test('function call includes only filled parameters (others use DEFAULT)', function () {
    $svc = new App\Modules\Analyses\AnalysisService();
    $a = ['kind' => 'function', 'function_name' => 'analise_risco_transferencias', 'params' => [
        ['name' => 'p_valor_minimo', 'type' => 'number', 'arg_type' => 'numeric', 'default' => ''],
        ['name' => 'p_mes1', 'type' => 'integer', 'arg_type' => 'integer', 'default' => ''],
        ['name' => 'p_ano1', 'type' => 'integer', 'arg_type' => 'integer', 'default' => '2026'],
    ]];
    [$sql, $p] = $svc->build($a, ['p_valor_minimo' => '500,5', 'p_mes1' => ''], 'pgsql');
    eq('SELECT * FROM "analise_risco_transferencias"("p_valor_minimo" => ?::numeric, "p_ano1" => ?::integer)', $sql);
    eq([500.5, 2026], $p);
    [$sql] = $svc->build(['kind' => 'function', 'function_name' => 'f', 'params' => []], [], 'pgsql');
    eq('SELECT * FROM "f"()', $sql);
});
test('rejects bad values and function names', function () {
    $svc = new App\Modules\Analyses\AnalysisService();
    try {
        $svc->build(['kind' => 'function', 'function_name' => 'f', 'params' => [['name' => 'p_x', 'type' => 'number']]], ['p_x' => '1); DROP TABLE t; --'], 'pgsql');
        throw new LogicException('accepted');
    } catch (App\Core\HttpException $e) {
        eq(422, $e->status);
    }
    try {
        App\Modules\Analyses\AnalysisService::qualified('f(); DROP TABLE t');
        throw new LogicException('accepted');
    } catch (App\Core\HttpException) {
    }
});
test('query templates bind {{params}} and drop empty [[optional]] blocks', function () {
    $svc = new App\Modules\Analyses\AnalysisService();
    $a = ['kind' => 'query', 'sql_text' => "SELECT * FROM t WHERE a >= {{min}} [[AND d >= {{desde}}]] [[AND s = {{estado}}]]",
        'params' => [['name' => 'min', 'type' => 'number', 'default' => '10'], ['name' => 'desde', 'type' => 'date'], ['name' => 'estado', 'type' => 'text']]];
    [$sql, $p] = $svc->build($a, ['estado' => "x' OR 1=1"], 'pgsql');
    eq('SELECT * FROM t WHERE a >= ?  AND s = ?', $sql);
    eq([10, "x' OR 1=1"], $p);
});

@unlink((string) getenv('APP_DB_SQLITE_PATH'));
echo "\n" . ($failed ? "\e[31m" . count($failed) . " failed\e[0m, " : '') . "\e[32m$passed passed\e[0m\n";
exit($failed ? 1 : 0);
