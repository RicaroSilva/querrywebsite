<?php
declare(strict_types=1);

/**
 * Demo content so the platform is usable right after installation:
 *  - storage/sqlite/demo.sqlite (a small sales database: customers, products, orders)
 *  - a "Demo — Vendas" SQLite connection
 *  - folders + saved queries (Clientes, Vendas, Reports)
 *  - a "Vendas Mensais" report with KPIs, charts, filter and table
 * Runs only when the application has no connections yet. Set SEED_DEMO=false to skip.
 */

use App\Core\Auth;
use App\Core\Database;
use App\Modules\Queries\FolderRepository;
use App\Modules\Queries\QueryRepository;
use App\Modules\Reports\ReportRepository;
use App\Modules\Users\UserRepository;

if (env('SEED_DEMO', true) === false) {
    return;
}
$db = Database::get();
if ((int) $db->value('SELECT COUNT(*) FROM connections') > 0) {
    echo "• Demo data skipped (connections already exist)\n";
    return;
}

// --- 1. demo SQLite database -------------------------------------------------
$path = storage_path('sqlite/demo.sqlite');
@unlink($path);
$demo = new PDO('sqlite:' . $path);
$demo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$demo->exec(<<<SQL
CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE, country TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL);
CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, category TEXT NOT NULL, price NUMERIC NOT NULL);
CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL REFERENCES customers(id), status TEXT NOT NULL,
    created_at TEXT NOT NULL);
CREATE TABLE order_items (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL REFERENCES orders(id),
    product_id INTEGER NOT NULL REFERENCES products(id), quantity INTEGER NOT NULL, unit_price NUMERIC NOT NULL);
CREATE INDEX idx_orders_customer ON orders(customer_id);
CREATE INDEX idx_orders_date ON orders(created_at);
CREATE INDEX idx_items_order ON order_items(order_id);
CREATE VIEW v_order_totals AS
    SELECT o.id AS order_id, o.customer_id, o.created_at, o.status, SUM(i.quantity * i.unit_price) AS total
    FROM orders o JOIN order_items i ON i.order_id = o.id GROUP BY o.id;
SQL);

mt_srand(42);
$first = ['Ana', 'João', 'Maria', 'Pedro', 'Inês', 'Rui', 'Sofia', 'Tiago', 'Beatriz', 'Miguel', 'Carla', 'Luís', 'Marta', 'Hugo', 'Rita', 'Nuno', 'Emma', 'Lucas', 'Chloé', 'Liam'];
$last = ['Silva', 'Santos', 'Ferreira', 'Pereira', 'Oliveira', 'Costa', 'Rodrigues', 'Martins', 'Sousa', 'Fernandes', 'Gomes', 'Lopes', 'Dubois', 'Müller', 'García'];
$countries = ['Portugal' => 50, 'Espanha' => 15, 'França' => 12, 'Alemanha' => 8, 'Brasil' => 10, 'Reino Unido' => 5];
$pick = static function (array $weights) {
    $r = mt_rand(1, array_sum($weights));
    foreach ($weights as $k => $w) {
        if (($r -= $w) <= 0) {
            return $k;
        }
    }
    return array_key_first($weights);
};

$demo->beginTransaction();
$ins = $demo->prepare('INSERT INTO customers (id, name, email, country, active, created_at) VALUES (?,?,?,?,?,?)');
for ($i = 1; $i <= 600; $i++) {
    $name = $first[array_rand($first)] . ' ' . $last[array_rand($last)];
    $email = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', str_replace(' ', '.', $name))) . ".$i@example.com";
    $ins->execute([$i, $name, $email, $pick($countries), mt_rand(1, 100) <= 85 ? 1 : 0,
        date('Y-m-d H:i:s', strtotime('-' . mt_rand(0, 720) . ' days'))]);
}
$catalog = [
    'Software' => ['Licença CRM Pro' => 49, 'Licença BI Suite' => 89, 'Add-on Analytics' => 19, 'API Gateway' => 29, 'Backup Cloud' => 12],
    'Hardware' => ['Monitor 27"' => 229, 'Teclado Mecânico' => 99, 'Rato Ergonómico' => 45, 'Dock USB-C' => 139, 'Webcam 4K' => 119],
    'Serviços' => ['Consultoria (hora)' => 85, 'Formação SQL' => 350, 'Suporte Premium' => 199, 'Migração de Dados' => 1200],
    'Acessórios' => ['Cabo HDMI' => 9, 'Mochila Tech' => 59, 'Suporte Portátil' => 35, 'Headset' => 79],
];
$pid = 0;
$prices = [];
$insP = $demo->prepare('INSERT INTO products (id, name, category, price) VALUES (?,?,?,?)');
foreach ($catalog as $cat => $items) {
    foreach ($items as $pname => $price) {
        $insP->execute([++$pid, $pname, $cat, $price]);
        $prices[$pid] = $price;
    }
}
$insO = $demo->prepare('INSERT INTO orders (id, customer_id, status, created_at) VALUES (?,?,?,?)');
$insI = $demo->prepare('INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (?,?,?,?)');
for ($o = 1; $o <= 4000; $o++) {
    $days = (int) floor(pow(mt_rand(0, 1000) / 1000, 1.4) * 540); // more recent orders are more frequent
    $insO->execute([$o, mt_rand(1, 600), $pick(['paid' => 80, 'shipped' => 12, 'refunded' => 3, 'pending' => 5]),
        date('Y-m-d H:i:s', strtotime("-$days days -" . mt_rand(0, 86399) . ' seconds'))]);
    $lines = mt_rand(1, 4);
    for ($l = 0; $l < $lines; $l++) {
        $p = mt_rand(1, $pid);
        $insI->execute([$o, $p, $prices[$p] > 300 ? 1 : mt_rand(1, 5), $prices[$p]]);
    }
}
$demo->commit();
$demo = null;

// --- 2. connection, folders, queries ----------------------------------------
$admin = (new UserRepository())->findByEmail((string) config('app.test_user.email'));
if ($admin) {
    Auth::login($admin); // attribute ownership (CLI context, no real session cookie)
}
$connId = $db->insert('connections', [
    'name' => 'Demo — Vendas', 'driver' => 'sqlite', 'host' => null, 'port' => null,
    'database_name' => 'storage/sqlite/demo.sqlite', 'username' => null, 'password_enc' => null,
    'options' => '{}', 'environment' => 'local', 'color' => '#46d2c3', 'read_only' => 0,
    'created_by' => $admin['id'] ?? null, 'created_at' => now(), 'updated_at' => now(),
]);

$folders = new FolderRepository();
$fClientes = $folders->create('Clientes', null, '#7c9cff');
$fVendas = $folders->create('Vendas', null, '#3ecf8e');
$fReports = $folders->create('Reports', null, '#f2b64c');

$queries = new QueryRepository();
$q = static fn(string $name, string $sql, int $folder, string $tags, string $desc = '') =>
    $queries->create(['name' => $name, 'description' => $desc, 'sql_text' => $sql, 'connection_id' => $connId, 'folder_id' => $folder, 'tags' => $tags]);

$qAtivos = $q('Clientes ativos', "SELECT\n    id,\n    name,\n    email,\n    country,\n    created_at\nFROM customers\nWHERE active = 1\nORDER BY created_at DESC;", $fClientes, 'clientes, crm', 'Todos os clientes ativos, mais recentes primeiro.');
$q('Novos clientes (30 dias)', "SELECT id, name, email, country, created_at\nFROM customers\nWHERE created_at >= date('now', '-30 days')\nORDER BY created_at DESC;", $fClientes, 'clientes, growth');
$qPais = $q('Clientes por país', "SELECT country AS pais, COUNT(*) AS clientes\nFROM customers\nGROUP BY country\nORDER BY clientes DESC;", $fClientes, 'clientes, geo');
$qMensal = $q('Vendas mensais', "SELECT strftime('%Y-%m', created_at) AS mes,\n       COUNT(*) AS encomendas,\n       ROUND(SUM(total), 2) AS revenue\nFROM v_order_totals\nWHERE status <> 'refunded'\n  AND created_at >= {{start_date}}\nGROUP BY mes\nORDER BY mes;", $fVendas, 'vendas, revenue, mensal', 'Revenue por mês (usa o filtro start_date nos relatórios).');
$qTop = $q('Top produtos', "SELECT p.name AS produto,\n       SUM(i.quantity) AS quantidade,\n       ROUND(SUM(i.quantity * i.unit_price), 2) AS revenue\nFROM order_items i\nJOIN products p ON p.id = i.product_id\nJOIN orders o ON o.id = i.order_id\nWHERE o.status <> 'refunded'\n  AND o.created_at >= {{start_date}}\nGROUP BY p.name\nORDER BY revenue DESC\nLIMIT 10;", $fVendas, 'vendas, produtos');
$qCat = $q('Revenue por categoria', "SELECT p.category AS categoria, ROUND(SUM(i.quantity * i.unit_price), 2) AS revenue\nFROM order_items i\nJOIN products p ON p.id = i.product_id\nJOIN orders o ON o.id = i.order_id\nWHERE o.status <> 'refunded' AND o.created_at >= {{start_date}}\nGROUP BY p.category\nORDER BY revenue DESC;", $fVendas, 'vendas, categorias');
$qKpi = $q('KPIs de vendas', "SELECT ROUND(SUM(total), 2) AS total_vendas,\n       COUNT(DISTINCT customer_id) AS clientes,\n       ROUND(AVG(total), 2) AS ticket_medio,\n       COUNT(*) AS encomendas\nFROM v_order_totals\nWHERE status <> 'refunded' AND created_at >= {{start_date}};", $fReports, 'kpi, dashboard');
$q('Performance — encomendas por estado', "SELECT status, COUNT(*) AS encomendas, ROUND(SUM(total), 2) AS total\nFROM v_order_totals\nGROUP BY status\nORDER BY encomendas DESC;", $fReports, 'performance');
$queries->toggleFavorite($qAtivos, (int) ($admin['id'] ?? 0));
$queries->toggleFavorite($qMensal, (int) ($admin['id'] ?? 0));

// --- 3. report --------------------------------------------------------------
$reports = new ReportRepository();
$rid = $reports->create(['name' => 'Vendas Mensais', 'description' => 'Visão geral de vendas: KPIs, evolução mensal, produtos e categorias.',
    'filters' => [['name' => 'start_date', 'label' => 'Desde', 'type' => 'date', 'default' => date('Y-m-d', strtotime('-12 months')), 'options' => []]]]);
$w = static fn(array $d) => $reports->addWidget($rid, $d);
$w(['type' => 'kpi', 'title' => 'Total de vendas', 'saved_query_id' => $qKpi, 'width' => 3, 'config' => ['value_col' => 'total_vendas', 'format' => 'currency', 'subtitle' => 'excluindo reembolsos']]);
$w(['type' => 'kpi', 'title' => 'Nº de clientes', 'saved_query_id' => $qKpi, 'width' => 3, 'config' => ['value_col' => 'clientes', 'format' => 'number', 'subtitle' => 'com pelo menos 1 encomenda']]);
$w(['type' => 'kpi', 'title' => 'Ticket médio', 'saved_query_id' => $qKpi, 'width' => 3, 'config' => ['value_col' => 'ticket_medio', 'format' => 'currency', 'decimals' => 2]]);
$w(['type' => 'kpi', 'title' => 'Encomendas', 'saved_query_id' => $qKpi, 'width' => 3, 'config' => ['value_col' => 'encomendas', 'format' => 'number']]);
$w(['type' => 'area', 'title' => 'Revenue mensal', 'saved_query_id' => $qMensal, 'width' => 8, 'config' => ['label_col' => 'mes', 'value_cols' => ['revenue']]]);
$w(['type' => 'doughnut', 'title' => 'Revenue por categoria', 'saved_query_id' => $qCat, 'width' => 4, 'config' => ['label_col' => 'categoria', 'value_cols' => ['revenue']]]);
$w(['type' => 'bar', 'title' => 'Top produtos (revenue)', 'saved_query_id' => $qTop, 'width' => 6, 'config' => ['label_col' => 'produto', 'value_cols' => ['revenue'], 'horizontal' => true]]);
$w(['type' => 'table', 'title' => 'Top produtos', 'saved_query_id' => $qTop, 'width' => 6, 'config' => []]);
$w(['type' => 'text', 'title' => 'Notas', 'width' => 12, 'config' => ['text' => "## Como ler este relatório\nOs valores excluem encomendas **reembolsadas**. Altere o filtro *Desde* para mudar o período. Cada componente usa uma query guardada como fonte de dados."]]);

echo "• Demo data created: connection \"Demo — Vendas\", 8 saved queries, report \"Vendas Mensais\"\n";
