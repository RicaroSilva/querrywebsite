-- Versão otimizada de analise_risco_transferencias (ver conversa/README).
-- 1) lê só os 2 meses pedidos; 2) perfil de risco apenas dos utilizadores do próprio IPC.
-- ATENÇÃO: o passo 2 corrige o LEFT JOIN users ON TRUE da original e pode mudar resultados.
-- Índice recomendado:  CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_transfers_from_date ON transfers (from_id, date);

CREATE OR REPLACE FUNCTION public.analise_risco_transferencias_v2(
    p_valor_minimo numeric DEFAULT 5000,
    p_percentagem_minima numeric DEFAULT 20,
    p_mes1 integer DEFAULT 1,
    p_ano1 integer DEFAULT 2026,
    p_mes2 integer DEFAULT 2,
    p_ano2 integer DEFAULT 2026)
RETURNS TABLE(ipc character varying, enumvalueid integer, relevancia character varying,
              anomes1 integer, mes1 integer, mes1transacoes bigint, mes1valor numeric,
              anomes2 integer, mes2 integer, mes2transacoes bigint, mes2valor numeric,
              diferencatransacoes integer, diferencavalor numeric,
              variacaotransacoespct numeric, variacaovalorpct numeric)
LANGUAGE sql
STABLE
AS $BODY$
WITH periodos AS (
    SELECT make_date(p_ano1, p_mes1, 1)                      AS ini1,
           make_date(p_ano1, p_mes1, 1) + interval '1 month' AS fim1,
           make_date(p_ano2, p_mes2, 1)                      AS ini2,
           make_date(p_ano2, p_mes2, 1) + interval '1 month' AS fim2
),
-- 1) Só lê as transferências dos 2 meses pedidos (antes: toda a história)
dados AS (
    SELECT ucfv_ipc.string_value AS ipc,
           CASE WHEN t.date >= p.ini1 AND t.date < p.fim1 THEN 1 ELSE 2 END AS periodo,
           COUNT(t.id)   AS total_transacoes,
           SUM(t.amount) AS valor_total
    FROM public.transfers t
    CROSS JOIN periodos p
    JOIN public.accounts acc_to ON acc_to.id = t.to_id
    JOIN public.users u_to      ON u_to.id = acc_to.user_id
    LEFT JOIN public.user_custom_field_values ucfv_ipc
           ON ucfv_ipc.owner_id = u_to.id AND ucfv_ipc.field_id = 26
    WHERE t.from_id = 1
      AND (   (t.date >= p.ini1 AND t.date < p.fim1)
           OR (t.date >= p.ini2 AND t.date < p.fim2))
    GROUP BY 1, 2
),
comparativo AS (
    SELECT m1.ipc,
           m1.total_transacoes AS mes1_transacoes, ROUND(m1.valor_total, 2) AS mes1_valor,
           m2.total_transacoes AS mes2_transacoes, ROUND(m2.valor_total, 2) AS mes2_valor,
           (m2.total_transacoes - m1.total_transacoes)           AS diferenca_transacoes,
           ROUND(m2.valor_total - m1.valor_total, 2)             AS diferenca_valor,
           ROUND((m2.total_transacoes::numeric - m1.total_transacoes)
                 / NULLIF(m1.total_transacoes, 0) * 100, 2)      AS variacao_transacoes_pct,
           ROUND((m2.valor_total - m1.valor_total)
                 / NULLIF(m1.valor_total, 0) * 100, 2)           AS variacao_valor_pct
    FROM dados m1
    JOIN dados m2 ON m2.ipc = m1.ipc AND m1.periodo = 1 AND m2.periodo = 2
),
-- 2) Perfil de risco (campo 43) dos utilizadores DESSE IPC (campo 26)
--    (antes: LEFT JOIN users ON TRUE cruzava cada linha com TODOS os utilizadores)
perfil AS (
    SELECT ipcv.string_value AS ipc,
           MAX(pr.enum_value_id) FILTER (WHERE pr.enum_value_id IS DISTINCT FROM 575) AS enum_value_id,
           BOOL_OR(pr.enum_value_id IS NULL OR pr.enum_value_id <> 575)             AS tem_perfil_valido
    FROM public.user_custom_field_values ipcv
    LEFT JOIN public.user_custom_field_values pr
           ON pr.owner_id = ipcv.owner_id AND pr.field_id = 43
    WHERE ipcv.field_id = 26
      AND ipcv.string_value IN (SELECT c.ipc FROM comparativo c)
    GROUP BY ipcv.string_value
)
SELECT c.ipc,
       pf.enum_value_id::int,
       CASE WHEN c.diferenca_valor >= 10000 AND c.variacao_valor_pct >= 25
            THEN 'Muito Relevante' ELSE 'Pouco Relevante' END::varchar,
       p_ano1, p_mes1, c.mes1_transacoes, c.mes1_valor,
       p_ano2, p_mes2, c.mes2_transacoes, c.mes2_valor,
       c.diferenca_transacoes::int, c.diferenca_valor,
       c.variacao_transacoes_pct, c.variacao_valor_pct
FROM comparativo c
LEFT JOIN perfil pf ON pf.ipc = c.ipc
WHERE c.diferenca_valor > p_valor_minimo
  AND c.variacao_valor_pct > p_percentagem_minima
  AND COALESCE(pf.tem_perfil_valido, true)
ORDER BY c.diferenca_valor DESC;
$BODY$;
