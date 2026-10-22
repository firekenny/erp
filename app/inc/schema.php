<?php
declare(strict_types=1);

/*
 * Database structure. Each version adds tables/columns.
 * After each deploy, the app upgrades the database by itself on the next page load —
 * no manual SQL needed.
 */
const SCHEMA_VERSION = 1;

function schema_migrations(): array {
    return [
        1 => [
            "CREATE TABLE IF NOT EXISTS settings (
                k VARCHAR(64) PRIMARY KEY,
                v TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(190) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NULL,
                roles VARCHAR(255) NOT NULL DEFAULT '',
                active TINYINT(1) NOT NULL DEFAULT 1,
                invite_token VARCHAR(64) NULL,
                invite_expires DATETIME NULL,
                last_login DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS fin_levels (
                id INT AUTO_INCREMENT PRIMARY KEY,
                position TINYINT NOT NULL UNIQUE,
                name VARCHAR(80) NOT NULL,
                required TINYINT(1) NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS fin_values (
                id INT AUTO_INCREMENT PRIMARY KEY,
                level_id INT NOT NULL,
                parent_id INT NULL,
                code VARCHAR(60) NOT NULL,
                name VARCHAR(190) NOT NULL,
                approver_user_id INT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                UNIQUE KEY uq_level_code (level_id, code),
                KEY ix_parent (parent_id),
                CONSTRAINT fk_fv_level FOREIGN KEY (level_id) REFERENCES fin_levels(id) ON DELETE CASCADE,
                CONSTRAINT fk_fv_parent FOREIGN KEY (parent_id) REFERENCES fin_values(id) ON DELETE SET NULL,
                CONSTRAINT fk_fv_approver FOREIGN KEY (approver_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(12) NOT NULL UNIQUE,
                name VARCHAR(120) NOT NULL,
                default_type ENUM('stock','asset') NOT NULL DEFAULT 'stock',
                active TINYINT(1) NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(40) NOT NULL UNIQUE,
                description VARCHAR(255) NOT NULL,
                category_id INT NOT NULL,
                unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
                item_type ENUM('stock','asset','service') NOT NULL DEFAULT 'stock',
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_item_cat FOREIGN KEY (category_id) REFERENCES categories(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS audit_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                action VARCHAR(80) NOT NULL,
                details TEXT,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY ix_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
    ];
}

function run_migrations(PDO $pdo): void {
    $current = 0;
    try {
        $current = (int)$pdo->query("SELECT v FROM settings WHERE k = 'db_version'")->fetchColumn();
    } catch (Throwable $e) { $current = 0; }
    foreach (schema_migrations() as $ver => $stmts) {
        if ($ver <= $current) continue;
        foreach ($stmts as $sql) $pdo->exec($sql);
        $pdo->prepare("INSERT INTO settings (k, v) VALUES ('db_version', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([(string)$ver]);
    }
}
