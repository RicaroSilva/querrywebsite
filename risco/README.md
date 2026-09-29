# Análise de risco de transferências

Página simples que executa, na sua base de dados PostgreSQL (a mesma que abre no pgAdmin):

```sql
SELECT * FROM analise_risco_transferencias_v2(500, 20, 1, 2026, 2, 2026);
```

Os 6 valores podem ser alterados no formulário. O resultado aparece numa tabela (ordenar clicando no
cabeçalho, filtrar) e pode ser descarregado em **CSV** (`;`, vírgula decimal — abre direto no Excel PT)
ou **Excel (.xlsx)**.

## Requisitos

PHP 8.1+ com as extensões `pdo_pgsql` e `zip` (o XAMPP já traz ambas; no `php.ini` tire o `;` de
`extension=pdo_pgsql` e `extension=zip` se estiverem comentadas).

## Instalação

1. Copie esta pasta `risco/` para o servidor (ex.: `C:\xampp\htdocs\risco`).
2. Copie `config.example.php` para `config.php` e preencha host, porta, base de dados, utilizador e
   password (os mesmos da ligação no pgAdmin).
3. Abra `http://localhost/risco/`.

Sem XAMPP/Apache basta, dentro da pasta: `php -S localhost:8080` e abrir `http://localhost:8080`.

## Configuração (`config.php`)

- `db` — dados de ligação, `schema` (search_path) e `timeout` em segundos.
- `function` — nome da função a chamar.
- `params` — um item por parâmetro, **pela ordem da função**: `label` (texto no formulário), `type`
  (tipo PostgreSQL usado no cast, ex. `numeric`, `integer`, `date`), `input` (`number`, `text`, `date`)
  e `default`. Campo vazio envia `NULL`.

A consulta corre numa transação só-leitura e os valores são enviados como parâmetros (sem SQL injection).
O `config.php` está no `.gitignore` para a password não ir para o Git.
