-- Parameterised analyses: a PostgreSQL function (kind=function) or a SQL template (kind=query)
-- params: JSON list [{name, label, type, default, required, options, arg_type}]
CREATE TABLE analyses (
    id {id},
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    category VARCHAR(120) NULL,
    connection_id {fk} NULL,
    kind VARCHAR(20) NOT NULL DEFAULT 'query',
    function_name VARCHAR(255) NULL,
    sql_text LONGTEXT NULL,
    params TEXT NULL,
    created_by {fk} NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY (connection_id) REFERENCES connections(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) {engine};
