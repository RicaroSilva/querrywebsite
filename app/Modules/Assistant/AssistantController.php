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
