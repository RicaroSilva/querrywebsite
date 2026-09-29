# Análise de risco de transferências

Página simples onde se escolhem os meses/anos e se vê o resultado de:

```sql
SELECT * FROM analise_risco_transferencias_v2(500, 20, <mês início>, <ano início>, <mês fim>, <ano fim>);
```

O resultado aparece numa tabela (ordenar clicando no cabeçalho, filtrar) e pode ser descarregado em
**CSV** (`;`, vírgula decimal, abre direto no Excel PT) ou **Excel (.xlsx)**.

## Como funciona a partilha

A página corre **no PC que tem acesso à base de dados** (o seu). Os colegas só abrem um link no
browser e não instalam nada. A password da base de dados fica apenas no seu PC.

```
colega (browser) ──► http://<IP do seu PC>:8080 ──► o seu PC (PHP) ──► PostgreSQL
```

## Instalação (uma vez, no seu PC — sem XAMPP)

1. Descarregue o PHP para Windows: https://windows.php.net/download → **VS16 x64 Non Thread Safe** (zip).
   Extraia-o para uma pasta `php` dentro desta pasta (`risco\php\php.exe`) ou para `C:\php`.
   Não é preciso configurar o `php.ini`.
2. Dê duplo clique em **`iniciar.bat`**. Da primeira vez ele cria o `config.php` e abre-o no Bloco de
   Notas: preencha host, porta, base de dados, utilizador e password (os mesmos do pgAdmin) e, de
   preferência, uma `page_password`. Guarde e volte a abrir o `iniciar.bat`.
3. A janela mostra o link para enviar aos colegas, ex.: `http://192.168.1.50:8080`.
   **Não feche a janela** enquanto quiser que a página funcione.
4. Da primeira vez o Windows pergunta se permite o acesso na rede (Firewall) — clique **Permitir**
   (redes privadas).

Os colegas têm de estar na mesma rede (escritório ou VPN). Para acesso de fora da rede é preciso
alojar isto num servidor ou usar um túnel (ex.: Cloudflare Tunnel).

## Configuração (`config.php`)

- `title` — título da página.
- `page_password` — se preenchida, o browser pede uma password para abrir a página (qualquer utilizador).
- `db` — dados de ligação, `schema` (search_path) e `timeout` em segundos.
- `function` — nome da função a chamar.
- `params` — um item por parâmetro, **pela ordem da função**: `label`, `type` (tipo PostgreSQL usado
  no cast), `input` (`number`, `text`, `date`, `month`), `default`, e `hidden => true` para um valor
  fixo que não aparece na página (por omissão o 500 e o 20). Campo vazio envia `NULL`.

A consulta corre numa transação só-leitura e os valores são enviados como parâmetros (sem SQL injection).
O `config.php` está no `.gitignore` para a password não ir para o Git.
