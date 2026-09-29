-- QueryDeck application schema (portable between MySQL/MariaDB and SQLite).
-- Tokens replaced by the migrator:
--   {id}     auto-increment primary key
--   {fk}     integer type for foreign keys
--   {engine} table options (MySQL only)

CREATE TABLE users (
    id {id},
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(30) NOT NULL DEFAULT 'editor',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    preferences TEXT NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE (email)
) {engine};

-- Future RBAC (v1 uses users.role + config/permissions.php)
CREATE TABLE roles (
    id {id},
    name VARCHAR(50) NOT NULL,
    label VARCHAR(120) NOT NULL,
    UNIQUE (name)
) {engine};

CREATE TABLE permissions (
    id {id},
    name VARCHAR(100) NOT NULL,
    label VARCHAR(190) NULL,
    UNIQUE (name)
) {engine};

CREATE TABLE role_permissions (
    role_id {fk} NOT NULL,
    permission_id {fk} NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) {engine};

CREATE TABLE login_attempts (
    id {id},
    email VARCHAR(190) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL
) {engine};
CREATE INDEX idx_login_attempts ON login_attempts (email, ip, attempted_at);

-- External database connections. Passwords are encrypted (libsodium, APP_KEY).
CREATE TABLE connections (
    id {id},
    name VARCHAR(120) NOT NULL,
    driver VARCHAR(30) NOT NULL,
    host VARCHAR(255) NULL,
    port INT NULL,
    database_name VARCHAR(255) NULL,
    username VARCHAR(190) NULL,
    password_enc TEXT NULL,
    options TEXT NULL,
    environment VARCHAR(30) NOT NULL DEFAULT 'development',
    color VARCHAR(20) NULL,
    read_only TINYINT(1) NOT NULL DEFAULT 0,
    created_by {fk} NULL,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) {engine};

-- Future per-connection access control
CREATE TABLE connection_user (
    connection_id {fk} NOT NULL,
    user_id {fk} NOT NULL,
    access_level VARCHAR(20) NOT NULL DEFAULT 'read',
    PRIMARY KEY (connection_id, user_id),
    FOREIGN KEY (connection_id) REFERENCES connections(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) {engine};

CREATE TABLE folders (
    id {id},
    name VARCHAR(120) NOT NULL,
    parent_id {fk} NULL,
    color VARCHAR(20) NULL,
    created_by {fk} NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) {engine};

CREATE TABLE saved_queries (
    id {id},
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    sql_text LONGTEXT NOT NULL,
    connection_id {fk} NULL,
    folder_id {fk} NULL,
    tags VARCHAR(500) NULL,
    created_by {fk} NULL,
    updated_by {fk} NULL,
    run_count INT NOT NULL DEFAULT 0,
    last_run_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY (connection_id) REFERENCES connections(id) ON DELETE SET NULL,
    FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) {engine};
CREATE INDEX idx_saved_queries_folder ON saved_queries (folder_id);

CREATE TABLE query_favorites (
    user_id {fk} NOT NULL,
    query_id {fk} NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, query_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (query_id) REFERENCES saved_queries(id) ON DELETE CASCADE
) {engine};

CREATE TABLE query_history (
    id {id},
    user_id {fk} NULL,
    connection_id {fk} NULL,
    connection_name VARCHAR(120) NULL,
    saved_query_id {fk} NULL,
    sql_text LONGTEXT NOT NULL,
    status VARCHAR(20) NOT NULL,
    error_message TEXT NULL,
    duration_ms INT NOT NULL DEFAULT 0,
    row_count INT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (connection_id) REFERENCES connections(id) ON DELETE SET NULL,
    FOREIGN KEY (saved_query_id) REFERENCES saved_queries(id) ON DELETE SET NULL
) {engine};
CREATE INDEX idx_history_user_date ON query_history (user_id, created_at);

CREATE TABLE reports (
    id {id},
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    filters TEXT NULL,
    created_by {fk} NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) {engine};

CREATE TABLE report_widgets (
    id {id},
    report_id {fk} NOT NULL,
    type VARCHAR(30) NOT NULL,
    title VARCHAR(190) NULL,
    saved_query_id {fk} NULL,
    connection_id {fk} NULL,
    sql_text LONGTEXT NULL,
    config TEXT NULL,
    width INT NOT NULL DEFAULT 6,
    position INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
    FOREIGN KEY (saved_query_id) REFERENCES saved_queries(id) ON DELETE SET NULL,
    FOREIGN KEY (connection_id) REFERENCES connections(id) ON DELETE SET NULL
) {engine};

CREATE TABLE audit_logs (
    id {id},
    user_id {fk} NULL,
    action VARCHAR(80) NOT NULL,
    entity VARCHAR(60) NULL,
    entity_id VARCHAR(60) NULL,
    meta TEXT NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) {engine};
CREATE INDEX idx_audit_date ON audit_logs (created_at);

CREATE TABLE settings (
    name VARCHAR(100) NOT NULL PRIMARY KEY,
    value TEXT NULL,
    updated_at DATETIME NOT NULL
) {engine};
