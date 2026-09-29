<?php $txt = ['pgsql' => 'PG', 'mysql' => 'MY', 'sqlsrv' => 'MS', 'sqlite' => 'SL'][$driver] ?? '??'; ?>
<span class="driver-logo driver-<?= e($driver) ?> <?= !empty($small) ? 'sm' : '' ?>"><?= e($txt) ?></span>
