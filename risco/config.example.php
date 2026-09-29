<?php
/**
 * Configuração da página "Análise de risco de transferências".
 * Copie este ficheiro para config.php e altere os dados de ligação.
 */
return [
    // Título mostrado na página
    'title' => 'Análise de risco de transferências',

    // Password para abrir a página (recomendado se partilhar na rede). Vazio = sem password.
    'page_password' => '',

    // Ligação à base de dados PostgreSQL (a mesma que usa no pgAdmin)
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 5432,
        'database' => 'nome_da_base_de_dados',
        'user'     => 'postgres',
        'password' => 'a_sua_password',
        'schema'   => 'public',   // search_path
        'timeout'  => 300,        // segundos (statement_timeout); 0 = sem limite
    ],

    // Função a chamar: SELECT * FROM <function>(p1, p2, ...)
    'function' => 'analise_risco_transferencias_v2',

    // Parâmetros, pela ORDEM da função. Ajuste nomes/labels/tipos à assinatura real.
    // type: tipo PostgreSQL usado no cast (?::type) — evita ambiguidades.
    // input: number | text | date | month (lista Janeiro..Dezembro)
    // hidden: true = não aparece na página; usa sempre o 'default'
    'params' => [
        ['name' => 'valor_minimo', 'label' => 'Valor mínimo',   'type' => 'numeric', 'input' => 'number', 'default' => 500, 'hidden' => true],
        ['name' => 'limite',       'label' => 'Limite / Top',   'type' => 'integer', 'input' => 'number', 'default' => 20,  'hidden' => true],
        ['name' => 'mes_inicio',   'label' => 'Mês início',     'type' => 'integer', 'input' => 'month',  'default' => 1],
        ['name' => 'ano_inicio',   'label' => 'Ano início',     'type' => 'integer', 'input' => 'number', 'default' => 2026],
        ['name' => 'mes_fim',      'label' => 'Mês fim',        'type' => 'integer', 'input' => 'month',  'default' => 2],
        ['name' => 'ano_fim',      'label' => 'Ano fim',        'type' => 'integer', 'input' => 'number', 'default' => 2026],
    ],

    // Nome base do ficheiro descarregado
    'filename' => 'analise_risco_transferencias',
];
