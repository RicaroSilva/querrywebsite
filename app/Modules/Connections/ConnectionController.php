<?php
declare(strict_types=1);

namespace App\Modules\Connections;

use App\Core\Audit;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Drivers\DriverFactory;
use App\Modules\Drivers\SqliteDriver;

final class ConnectionController
{
    private ConnectionRepository $repo;

    public function __construct()
    {
        $this->repo = new ConnectionRepository();
    }

    public function index(Request $request): never
    {
        Response::html(View::render('connections/index', [
            'title'        => 'Conexões',
            'active'       => 'databases',
            'connections'  => $this->repo->all(),
            'drivers'      => DriverFactory::catalog(),
            'environments' => ConnectionRepository::ENVIRONMENTS,
        ]));
    }

    public function drivers(Request $request): never
    {
        Response::json(['ok' => true, 'drivers' => DriverFactory::catalog(), 'environments' => ConnectionRepository::ENVIRONMENTS]);
    }

    public function list(Request $request): never
    {
        Response::json(['ok' => true, 'connections' => $this->repo->all()]);
    }

    public function show(Request $request): never
    {
        Response::json(['ok' => true, 'connection' => ConnectionRepository::toPublic($this->repo->findOrFail((int) $request->param('id')))]);
    }

    public function test(Request $request): never
    {
        $existing = $request->int('id') ? $this->repo->findOrFail($request->int('id')) : null;
        $row = $this->repo->normalise($request->all(), $existing);
        $this->checkSqlitePath($row);
        $t0 = microtime(true);
        try {
            $driver = DriverFactory::fromConnection($row);
            $version = $driver->serverVersion();
            $driver->prepareSession(10, (bool) $row['read_only']);
            $ms = (int) round((microtime(true) - $t0) * 1000);
            Audit::log('connection.test', 'connection', $existing['id'] ?? null, ['driver' => $row['driver'], 'ok' => true]);
            Response::json(['ok' => true, 'message' => 'Ligação estabelecida com sucesso.', 'version' => $version, 'latency_ms' => $ms]);
        } catch (\Throwable $e) {
            Audit::log('connection.test', 'connection', $existing['id'] ?? null, ['driver' => $row['driver'], 'ok' => false]);
            $msg = preg_replace('/^SQLSTATE\[[^\]]*\]\s*(\[[^\]]*\]\s*)*/', '', $e->getMessage());
            Response::json(['ok' => false, 'error' => 'Falha na ligação: ' . $msg], 200);
        }
    }

    public function store(Request $request): never
    {
        $row = $this->repo->normalise($request->all());
        $this->checkSqlitePath($row);
        $id = $this->repo->create($row);
        Audit::log('connection.create', 'connection', $id, ['name' => $row['name'], 'driver' => $row['driver']]);
        Response::json(['ok' => true, 'connection' => ConnectionRepository::toPublic($this->repo->find($id))], 201);
    }

    public function update(Request $request): never
    {
        $id = (int) $request->param('id');
        $existing = $this->repo->findOrFail($id);
        $row = $this->repo->normalise($request->all(), $existing);
        $this->checkSqlitePath($row);
        $this->repo->update($id, $row);
        Audit::log('connection.update', 'connection', $id, ['name' => $row['name'],
            'password_changed' => $row['password_enc'] !== $existing['password_enc']]);
        Response::json(['ok' => true, 'connection' => ConnectionRepository::toPublic($this->repo->find($id))]);
    }

    public function destroy(Request $request): never
    {
        $id = (int) $request->param('id');
        $c = $this->repo->findOrFail($id);
        $this->repo->delete($id);
        Audit::log('connection.delete', 'connection', $id, ['name' => $c['name']]);
        Response::json(['ok' => true]);
    }

    private function checkSqlitePath(array $row): void
    {
        if ($row['driver'] !== 'sqlite') {
            return;
        }
        try {
            SqliteDriver::resolvePath((string) $row['database_name']);
        } catch (\RuntimeException $e) {
            throw new HttpException(422, $e->getMessage(), ['database_name' => $e->getMessage()]);
        }
    }
}
