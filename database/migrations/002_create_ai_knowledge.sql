-- AI assistant knowledge per connection:
--   kind = 'note'    : free text written by the team (business meaning of tables/columns)
--   kind = 'example' : a question + the SQL that correctly answers it (few-shot examples)
CREATE TABLE ai_knowledge (
    id {id},
    connection_id {fk} NOT NULL,
    kind VARCHAR(20) NOT NULL,
    question TEXT NULL,
    sql_text LONGTEXT NULL,
    content LONGTEXT NULL,
    created_by {fk} NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY (connection_id) REFERENCES connections(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) {engine};
CREATE INDEX idx_ai_knowledge_conn ON ai_knowledge (connection_id, kind);
