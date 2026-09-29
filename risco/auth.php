<?php
// Proteção opcional da página: se 'page_password' estiver definido no config.php, o browser pede
// utilizador/password (o utilizador pode ser qualquer um).
if (($pw = (string)($config['page_password'] ?? '')) !== '') {
    if (!hash_equals($pw, (string)($_SERVER['PHP_AUTH_PW'] ?? ''))) {
        header('WWW-Authenticate: Basic realm="Analise", charset="UTF-8"');
        http_response_code(401);
        exit('Acesso restrito.');
    }
}
