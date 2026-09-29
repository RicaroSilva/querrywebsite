<?php
declare(strict_types=1);

namespace App\Modules\Assistant;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Connections\ConnectionRepository;

final class AssistantController
{
    public function index(Request $request): never
    {
        Response::html(View::render('assistant/index', [
            'title'       => 'Assistente IA',
            'active'      => 'assistant',
            'connections' => (new ConnectionRepository())->all(),
            'ai'          => ['enabled' => (bool) config('ai.enabled'), 'model' => config('ai.model'),
                              'base_url' => config('ai.base_url'), 'send_results' => (bool) config('ai.send_results')],
            'boot'        => ['connection' => $request->int('connection') ?: null, 'question' => mb_substr($request->str('q'), 0, 2000) ?: null],
            'flush'       => true,
        ]));
    }

    public function ask(Request $request): never
    {
        if (!config('ai.enabled')) {
            throw new HttpException(422, 'O assistente IA está desativado (AI_ENABLED=false no .env).');
        }
        $question = trim($request->str('question'));
        $mode = in_array($request->str('mode'), ['ask', 'generate', 'run'], true) ? $request->str('mode') : 'ask';
        if ($question === '' || mb_strlen($question) > 2000) {
            throw new HttpException(422, 'Escreva uma pergunta (máx. 2000 caracteres).');
        }
        $sql = $mode === 'run' ? trim($request->str('sql')) : null;
        if ($mode === 'run' && ($sql === '' || strlen($sql) > 100000)) {
            throw new HttpException(422, 'SQL em falta.');
        }
        $model = $request->str('model');
        $model = $model !== '' && preg_match('/^[\w.:\/@+-]{1,120}$/', $model) ? $model : null;
        $history = [];
        foreach (array_slice((array) $request->input('history', []), -4) as $h) {
            if (is_array($h)) {
                $history[] = ['question' => mb_substr((string) ($h['question'] ?? ''), 0, 2000), 'sql' => mb_substr((string) ($h['sql'] ?? ''), 0, 20000)];
            }
        }
        $conn = (new ConnectionRepository())->findOrFail($request->int('connection_id'));
        $database = $request->str('database') ?: null;
        $userId = (int) Auth::id();

        Audit::log('assistant.ask', 'connection', $conn['id'], ['question' => mb_substr($question, 0, 300), 'mode' => $mode]);
        Session::release();
        set_time_limit(max(60, (int) config('ai.timeout') * 3 + (int) config('query.timeout') * 2));
        try {
            $r = (new AssistantService())->ask($conn, $database, $question, $history, $userId, $mode, $sql, $model);
            Response::json(['ok' => true] + $r);
        } catch (\RuntimeException $e) {
            Response::json(['ok' => false, 'error' => preg_replace('/^SQLSTATE\[[^\]]*\]\s*(\[[^\]]*\]\s*)*/', '', $e->getMessage())], 200);
        }
    }

    // ---------------------------------------------------------------- knowledge
    public function knowledge(Request $request): never
    {
        $conn = (new ConnectionRepository())->findOrFail($request->int('connection_id'));
        $repo = new KnowledgeRepository();
        $database = $request->str('database') ?: null;
        $tables = null;
        try {
            $tables = SchemaCatalog::load($conn, $database)->count();
        } catch (\Throwable) {
        }
        Response::json(['ok' => true, 'notes' => $repo->notes((int) $conn['id']), 'examples' => $repo->examples((int) $conn['id']),
            'tables' => $tables, 'can_teach' => Auth::can('assistant.teach')]);
    }

    public function saveNotes(Request $request): never
    {
        $conn = (new ConnectionRepository())->findOrFail($request->int('connection_id'));
        $notes = mb_substr((string) $request->input('notes', ''), 0, 30000);
        (new KnowledgeRepository())->saveNotes((int) $conn['id'], $notes);
        Audit::log('assistant.notes', 'connection', $conn['id'], ['chars' => mb_strlen($notes)]);
        Response::json(['ok' => true]);
    }

    public function addExample(Request $request): never
    {
        $conn = (new ConnectionRepository())->findOrFail($request->int('connection_id'));
        $q = trim(mb_substr($request->str('question'), 0, 2000));
        $sql = trim($request->str('sql'));
        if ($q === '' || $sql === '' || !\App\Modules\Execution\SqlSplitter::isReadOnly($sql)) {
            throw new HttpException(422, 'Exemplo inválido (é preciso a pergunta e um SELECT).');
        }
        $id = (new KnowledgeRepository())->addExample((int) $conn['id'], $q, $sql);
        Audit::log('assistant.example', 'connection', $conn['id'], ['id' => $id]);
        Response::json(['ok' => true, 'id' => $id]);
    }

    public function deleteExample(Request $request): never
    {
        (new KnowledgeRepository())->deleteExample($request->int('connection_id'), (int) $request->param('id'));
        Response::json(['ok' => true]);
    }

    /** Re-read the database structure (after schema changes). */
    public function refreshSchema(Request $request): never
    {
        $conn = (new ConnectionRepository())->findOrFail($request->int('connection_id'));
        $database = $request->str('database') ?: null;
        Session::release();
        set_time_limit(300);
        SchemaCatalog::forget($conn, $database);
        try {
            Response::json(['ok' => true, 'tables' => SchemaCatalog::load($conn, $database)->count()]);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => preg_replace('/^SQLSTATE\[[^\]]*\]\s*(\[[^\]]*\]\s*)*/', '', $e->getMessage())]);
        }
    }

    /** Ask the model to draft notes describing the database (user reviews before saving). */
    public function describe(Request $request): never
    {
        if (!config('ai.enabled')) {
            throw new HttpException(422, 'O assistente IA está desativado.');
        }
        $conn = (new ConnectionRepository())->findOrFail($request->int('connection_id'));
        $database = $request->str('database') ?: null;
        $model = preg_match('/^[\w.:\/@+-]{1,120}$/', $request->str('model')) ? $request->str('model') : null;
        Session::release();
        set_time_limit(max(120, (int) config('ai.timeout') * 2));
        try {
            Response::json(['ok' => true, 'notes' => (new AssistantService())->describeDatabase($conn, $database, $model)]);
        } catch (\RuntimeException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** Check that the configured AI endpoint answers and list its models. */
    public function status(Request $request): never
    {
        if (!config('ai.enabled')) {
            Response::json(['ok' => false, 'enabled' => false, 'error' => 'Assistente desativado (AI_ENABLED=false).']);
        }
        Session::release();
        try {
            $models = (new AiClient())->models();
            $model = (string) config('ai.model');
            Response::json(['ok' => true, 'enabled' => true, 'models' => $models, 'model' => $model,
                'model_found' => $model === '' ? false : in_array($model, $models, true)]);
        } catch (\RuntimeException $e) {
            Response::json(['ok' => false, 'enabled' => true, 'error' => $e->getMessage()]);
        }
    }
}
