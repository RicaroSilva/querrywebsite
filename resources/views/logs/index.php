<div class="page">
    <form class="row mb" method="get" action="<?= e(url('/logs')) ?>">
        <div class="input-icon" style="width:320px"><?= icon('filter', 'sm') ?><input class="input" name="action" value="<?= e($action) ?>" placeholder="Filtrar ação (ex: auth., connection., export.)"></div>
        <button class="btn">Filtrar</button>
        <span class="muted small" style="margin-left:auto"><?= number_format($total, 0, ',', '.') ?> eventos</span>
    </form>
    <div class="card">
        <table class="table compact">
            <thead><tr><th>Data/hora</th><th>Utilizador</th><th>Ação</th><th>Entidade</th><th>Detalhes</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <?php $cls = str_contains($r['action'], 'failed') || str_contains($r['action'], 'locked') ? 'danger' : (str_contains($r['action'], 'delete') ? 'warning' : 'accent'); ?>
                <tr>
                    <td class="small" style="white-space:nowrap"><?= e(date('d/m/Y H:i:s', strtotime($r['created_at']))) ?></td>
                    <td class="small"><?= e($r['user_name'] ?? '—') ?></td>
                    <td><span class="badge <?= $cls ?>"><?= e($r['action']) ?></span></td>
                    <td class="small mono"><?= e(trim(($r['entity'] ?? '') . ' ' . ($r['entity_id'] ?? ''))) ?></td>
                    <td class="small mono muted" style="max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($r['meta']) ?>"><?= e($r['meta']) ?></td>
                    <td class="small mono muted"><?= e($r['ip']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6" class="empty">Sem eventos</td></tr><?php endif; ?>
            </tbody>
        </table>
        <div class="grid-foot" style="padding:10px 14px">
            <span>Página <?= (int) $page ?> de <?= (int) $pages ?></span><div class="spacer"></div>
            <?php if ($page > 1): ?><a class="btn sm" href="<?= e(url('/logs?page=' . ($page - 1) . '&action=' . urlencode($action))) ?>">Anterior</a><?php endif; ?>
            <?php if ($page < $pages): ?><a class="btn sm" href="<?= e(url('/logs?page=' . ($page + 1) . '&action=' . urlencode($action))) ?>">Seguinte</a><?php endif; ?>
        </div>
    </div>
</div>
