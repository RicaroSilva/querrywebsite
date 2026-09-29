<?php
declare(strict_types=1);

namespace App\Modules\Queries;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Modules\Connections\ConnectionRepository;

final class QueryController
{
    private QueryRepository $queries;
    private FolderRepository $folders;

    public function __construct()
    {
        $this->queries = new QueryRepository();
        $this->folders = new FolderRepository();
    }

    private function page(string $mode, string $title, string $active): never
    {
        Response::html(View::render('queries/index', [
            'title'       => $title,
            'active'      => $active,
            'mode'        => $mode,
            'folders'     => $this->folders->all(),
            'connections' => (new ConnectionRepository())->all(),
            'tags'        => $this->queries->allTags(),
        ]));
    }

    public function index(Request $request): never
    {
        $this->page('all', 'Queries', 'queries');
    }

    public function favorites(Request $request): never
    {
        $this->page('favorites', 'Favoritos', 'favorites');
    }

    public function folders(Request $request): never
    {
        $this->page('folders', 'Pastas', 'folders');
    }

    public function list(Request $request): never
    {
        $rows = $this->queries->search([
            'search'        => $request->str('search'),
            'folder_id'     => $request->str('folder_id'),
            'connection_id' => $request->int('connection_id'),
            'tag'           => $request->str('tag'),
            'favorites'     => $request->bool('favorites'),
            'sort'          => $request->str('sort', 'updated'),
        ]);
        Response::json(['ok' => true, 'queries' => array_map([$this, 'present'], $rows)]);
    }

    public function show(Request $request): never
    {
        Response::json(['ok' => true, 'query' => $this->present($this->queries->findOrFail((int) $request->param('id')))]);
    }

    private function validated(Request $request): array
    {
        $d = Validator::validate($request->all(), [
            'name'          => 'required|string|max:190',
            'description'   => 'nullable|string|max:5000',
            'sql_text'      => 'required|string',
            'connection_id' => 'nullable|int',
            'folder_id'     => 'nullable|int',
            'tags'          => 'nullable|string|max:500',
        ]);
        if ($d['connection_id'] && !(new ConnectionRepository())->find($d['connection_id'])) {
            $d['connection_id'] = null;
        }
        if ($d['folder_id'] && !$this->folders->find($d['folder_id'])) {
            $d['folder_id'] = null;
        }
        return $d;
    }

    public function store(Request $request): never
    {
        $id = $this->queries->create($this->validated($request));
        Audit::log('query.create', 'saved_query', $id);
        Response::json(['ok' => true, 'query' => $this->present($this->queries->find($id))], 201);
    }

    public function update(Request $request): never
    {
        $id = (int) $request->param('id');
        $this->queries->findOrFail($id);
        $this->queries->update($id, $this->validated($request));
        Audit::log('query.update', 'saved_query', $id);
        Response::json(['ok' => true, 'query' => $this->present($this->queries->find($id))]);
    }

    public function destroy(Request $request): never
    {
        $id = (int) $request->param('id');
        $q = $this->queries->findOrFail($id);
        $this->queries->delete($id);
        Audit::log('query.delete', 'saved_query', $id, ['name' => $q['name']]);
        Response::json(['ok' => true]);
    }

    public function duplicate(Request $request): never
    {
        $id = $this->queries->duplicate((int) $request->param('id'));
        Response::json(['ok' => true, 'query' => $this->present($this->queries->find($id))], 201);
    }

    public function favorite(Request $request): never
    {
        $id = (int) $request->param('id');
        $this->queries->findOrFail($id);
        Response::json(['ok' => true, 'favorite' => $this->queries->toggleFavorite($id, (int) Auth::id())]);
    }

    public function folderList(Request $request): never
    {
        Response::json(['ok' => true, 'folders' => $this->folders->all()]);
    }

    public function folderStore(Request $request): never
    {
        $d = Validator::validate($request->all(), ['name' => 'required|string|max:120', 'parent_id' => 'nullable|int', 'color' => 'nullable|string|max:20']);
        $id = $this->folders->create($d['name'], $d['parent_id'] ?: null, $this->color($d['color']));
        Response::json(['ok' => true, 'folder' => $this->folders->find($id)], 201);
    }

    public function folderUpdate(Request $request): never
    {
        $id = (int) $request->param('id');
        if (!$this->folders->find($id)) {
            throw new HttpException(404);
        }
        $d = Validator::validate($request->all(), ['name' => 'required|string|max:120', 'parent_id' => 'nullable|int', 'color' => 'nullable|string|max:20']);
        $this->folders->update($id, $d['name'], $d['parent_id'] ?: null, $this->color($d['color']));
        Response::json(['ok' => true, 'folder' => $this->folders->find($id)]);
    }

    public function folderDestroy(Request $request): never
    {
        $this->folders->delete((int) $request->param('id'));
        Response::json(['ok' => true]);
    }

    private function color(?string $c): ?string
    {
        return $c && preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : null;
    }

    private function present(array $q): array
    {
        return [
            'id'                => (int) $q['id'],
            'name'              => $q['name'],
            'description'       => $q['description'],
            'sql_text'          => $q['sql_text'],
            'connection_id'     => $q['connection_id'] !== null ? (int) $q['connection_id'] : null,
            'connection_name'   => $q['connection_name'],
            'connection_driver' => $q['connection_driver'],
            'folder_id'         => $q['folder_id'] !== null ? (int) $q['folder_id'] : null,
            'folder_name'       => $q['folder_name'],
            'tags'              => QueryRepository::parseTags($q['tags']),
            'creator_name'      => $q['creator_name'],
            'updater_name'      => $q['updater_name'],
            'is_favorite'       => (bool) $q['is_favorite'],
            'run_count'         => (int) $q['run_count'],
            'last_run_at'       => $q['last_run_at'],
            'created_at'        => $q['created_at'],
            'updated_at'        => $q['updated_at'],
        ];
    }
}
