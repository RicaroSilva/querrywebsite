<?php
declare(strict_types=1);

use App\Core\Router;
use App\Modules\Analyses\AnalysisController;
use App\Modules\Assistant\AssistantController;
use App\Modules\Auth\AuthController;
use App\Modules\Connections\ConnectionController;
use App\Modules\Dashboard\DashboardController;
use App\Modules\Editor\EditorController;
use App\Modules\Execution\ExecutionController;
use App\Modules\Explorer\ExplorerController;
use App\Modules\Exports\ExportController;
use App\Modules\History\HistoryController;
use App\Modules\Logs\LogController;
use App\Modules\Queries\QueryController;
use App\Modules\Reports\ReportController;
use App\Modules\Settings\SettingsController;
use App\Modules\Users\UserController;

/**
 * Route table. Pages render HTML; /api/* return JSON.
 * Every non-GET route is CSRF-protected by the router.
 */
return static function (Router $r): void {
    // Authentication
    $r->get('/login', [AuthController::class, 'showLogin'], ['guest' => true, 'auth' => false]);
    $r->post('/login', [AuthController::class, 'login'], ['guest' => true, 'auth' => false]);
    $r->post('/logout', [AuthController::class, 'logout']);

    // Dashboard
    $r->get('/', [DashboardController::class, 'index'], ['can' => 'dashboard.view']);

    // Connections
    $r->get('/connections', [ConnectionController::class, 'index'], ['can' => 'connections.view']);
    $r->get('/api/drivers', [ConnectionController::class, 'drivers'], ['can' => 'connections.view']);
    $r->get('/api/connections', [ConnectionController::class, 'list'], ['can' => 'connections.view']);
    $r->post('/api/connections/test', [ConnectionController::class, 'test'], ['can' => 'connections.manage']);
    $r->post('/api/connections', [ConnectionController::class, 'store'], ['can' => 'connections.manage']);
    $r->get('/api/connections/{id}', [ConnectionController::class, 'show'], ['can' => 'connections.view']);
    $r->put('/api/connections/{id}', [ConnectionController::class, 'update'], ['can' => 'connections.manage']);
    $r->delete('/api/connections/{id}', [ConnectionController::class, 'destroy'], ['can' => 'connections.manage']);

    // Explorer (database object browser)
    $r->get('/explorer', [ExplorerController::class, 'index'], ['can' => 'connections.view']);
    $r->get('/api/connections/{id}/tree', [ExplorerController::class, 'tree'], ['can' => 'connections.view']);
    $r->get('/api/connections/{id}/object', [ExplorerController::class, 'object'], ['can' => 'connections.view']);
    $r->get('/api/connections/{id}/completion', [ExplorerController::class, 'completion'], ['can' => 'connections.view']);
    $r->get('/api/connections/{id}/databases', [ExplorerController::class, 'databases'], ['can' => 'connections.view']);

    // SQL editor + execution
    $r->get('/editor', [EditorController::class, 'index'], ['can' => 'queries.execute']);
    $r->post('/api/execute', [ExecutionController::class, 'execute'], ['can' => 'queries.execute']);
    $r->post('/api/execute/{exec}/cancel', [ExecutionController::class, 'cancel'], ['can' => 'queries.execute']);
    $r->get('/api/results/{rid}', [ExecutionController::class, 'page'], ['can' => 'queries.execute']);
    $r->get('/api/results/{rid}/copy', [ExecutionController::class, 'copy'], ['can' => 'queries.execute']);

    // Exports (streamed downloads)
    $r->post('/export', [ExportController::class, 'export'], ['can' => 'export.run']);

    // Analyses (parameterised functions / queries with a form)
    $r->get('/analyses', [AnalysisController::class, 'index'], ['can' => 'analyses.run']);
    $r->get('/api/analyses', [AnalysisController::class, 'list'], ['can' => 'analyses.run']);
    $r->post('/api/analyses', [AnalysisController::class, 'store'], ['can' => 'analyses.manage']);
    $r->put('/api/analyses/{id}', [AnalysisController::class, 'update'], ['can' => 'analyses.manage']);
    $r->delete('/api/analyses/{id}', [AnalysisController::class, 'destroy'], ['can' => 'analyses.manage']);
    $r->post('/api/analyses/{id}/run', [AnalysisController::class, 'run'], ['can' => 'analyses.run']);
    $r->post('/api/analyses/{id}/preview', [AnalysisController::class, 'preview'], ['can' => 'analyses.run']);
    $r->get('/api/connections/{cid}/functions', [AnalysisController::class, 'functions'], ['can' => 'analyses.manage']);
    $r->get('/api/connections/{cid}/function', [AnalysisController::class, 'describeFunction'], ['can' => 'analyses.manage']);

    // AI assistant (natural language → read-only SQL → answer)
    $r->get('/assistant', [AssistantController::class, 'index'], ['can' => 'assistant.use']);
    $r->post('/api/assistant/ask', [AssistantController::class, 'ask'], ['can' => 'assistant.use']);
    $r->get('/api/assistant/status', [AssistantController::class, 'status'], ['can' => 'assistant.use']);
    $r->get('/api/assistant/knowledge', [AssistantController::class, 'knowledge'], ['can' => 'assistant.use']);
    $r->post('/api/assistant/knowledge', [AssistantController::class, 'saveNotes'], ['can' => 'assistant.teach']);
    $r->post('/api/assistant/examples', [AssistantController::class, 'addExample'], ['can' => 'assistant.teach']);
    $r->delete('/api/assistant/examples/{id}', [AssistantController::class, 'deleteExample'], ['can' => 'assistant.teach']);
    $r->post('/api/assistant/refresh-schema', [AssistantController::class, 'refreshSchema'], ['can' => 'assistant.use']);
    $r->post('/api/assistant/describe', [AssistantController::class, 'describe'], ['can' => 'assistant.teach']);

    // Saved queries, folders, favourites
    $r->get('/queries', [QueryController::class, 'index'], ['can' => 'queries.view']);
    $r->get('/favorites', [QueryController::class, 'favorites'], ['can' => 'queries.view']);
    $r->get('/folders', [QueryController::class, 'folders'], ['can' => 'queries.view']);
    $r->get('/api/queries', [QueryController::class, 'list'], ['can' => 'queries.view']);
    $r->post('/api/queries', [QueryController::class, 'store'], ['can' => 'queries.manage']);
    $r->get('/api/queries/{id}', [QueryController::class, 'show'], ['can' => 'queries.view']);
    $r->put('/api/queries/{id}', [QueryController::class, 'update'], ['can' => 'queries.manage']);
    $r->delete('/api/queries/{id}', [QueryController::class, 'destroy'], ['can' => 'queries.manage']);
    $r->post('/api/queries/{id}/duplicate', [QueryController::class, 'duplicate'], ['can' => 'queries.manage']);
    $r->post('/api/queries/{id}/favorite', [QueryController::class, 'favorite'], ['can' => 'queries.view']);
    $r->get('/api/folders', [QueryController::class, 'folderList'], ['can' => 'queries.view']);
    $r->post('/api/folders', [QueryController::class, 'folderStore'], ['can' => 'queries.manage']);
    $r->put('/api/folders/{id}', [QueryController::class, 'folderUpdate'], ['can' => 'queries.manage']);
    $r->delete('/api/folders/{id}', [QueryController::class, 'folderDestroy'], ['can' => 'queries.manage']);

    // History
    $r->get('/history', [HistoryController::class, 'index'], ['can' => 'history.view']);
    $r->get('/api/history', [HistoryController::class, 'list'], ['can' => 'history.view']);
    $r->get('/api/history/{id}', [HistoryController::class, 'show'], ['can' => 'history.view']);
    $r->delete('/api/history', [HistoryController::class, 'clear'], ['can' => 'history.view']);

    // Reports & dashboards
    $r->get('/reports', [ReportController::class, 'index'], ['can' => 'reports.view']);
    $r->get('/reports/{id}', [ReportController::class, 'show'], ['can' => 'reports.view']);
    $r->post('/api/reports', [ReportController::class, 'store'], ['can' => 'reports.manage']);
    $r->post('/api/reports/from-query/{qid}', [ReportController::class, 'fromQuery'], ['can' => 'reports.manage']);
    $r->get('/api/reports/{id}', [ReportController::class, 'get'], ['can' => 'reports.view']);
    $r->put('/api/reports/{id}', [ReportController::class, 'update'], ['can' => 'reports.manage']);
    $r->delete('/api/reports/{id}', [ReportController::class, 'destroy'], ['can' => 'reports.manage']);
    $r->post('/api/reports/{id}/duplicate', [ReportController::class, 'duplicate'], ['can' => 'reports.manage']);
    $r->post('/api/reports/{id}/widgets', [ReportController::class, 'widgetStore'], ['can' => 'reports.manage']);
    $r->put('/api/reports/{id}/widgets/{wid}', [ReportController::class, 'widgetUpdate'], ['can' => 'reports.manage']);
    $r->delete('/api/reports/{id}/widgets/{wid}', [ReportController::class, 'widgetDestroy'], ['can' => 'reports.manage']);
    $r->post('/api/reports/{id}/reorder', [ReportController::class, 'reorder'], ['can' => 'reports.manage']);
    $r->post('/api/reports/{id}/widgets/{wid}/data', [ReportController::class, 'widgetData'], ['can' => 'reports.view']);

    // Settings, users, audit logs
    $r->get('/settings', [SettingsController::class, 'index']);
    $r->post('/api/settings/preferences', [SettingsController::class, 'preferences']);
    $r->post('/api/settings/password', [SettingsController::class, 'password']);
    $r->post('/api/settings/profile', [SettingsController::class, 'profile']);
    $r->get('/api/users', [UserController::class, 'list'], ['can' => 'users.manage']);
    $r->post('/api/users', [UserController::class, 'store'], ['can' => 'users.manage']);
    $r->put('/api/users/{id}', [UserController::class, 'update'], ['can' => 'users.manage']);
    $r->get('/logs', [LogController::class, 'index'], ['can' => 'logs.view']);
};
