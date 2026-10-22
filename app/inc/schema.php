<?php
declare(strict_types=1);

/*
 * Database structure. Each version adds tables/columns.
 * After each deploy, the app upgrades the database by itself on the next page load —
 * no manual SQL needed.
 */
const SCHEMA_VERSION = 6;

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
        2 => [
            // Each level is either filtered by the level above (linked = 1) or one independent list (linked = 0)
            "ALTER TABLE fin_levels ADD COLUMN linked TINYINT(1) NOT NULL DEFAULT 1",
        ],
        3 => [
            // Purchase Requests
            "CREATE TABLE IF NOT EXISTS pr (
                id INT AUTO_INCREMENT PRIMARY KEY,
                number VARCHAR(40) NOT NULL UNIQUE,
                requester_id INT NOT NULL,
                title VARCHAR(200) NOT NULL,
                needed_by DATE NULL,
                delivery_location VARCHAR(200) NOT NULL DEFAULT '',
                justification TEXT,
                currency CHAR(3) NOT NULL,
                status ENUM('draft','submitted','returned','approved','cancelled') NOT NULL DEFAULT 'draft',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                submitted_at DATETIME NULL,
                approved_at DATETIME NULL,
                KEY ix_status (status),
                CONSTRAINT fk_pr_req FOREIGN KEY (requester_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS pr_lines (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pr_id INT NOT NULL,
                line_no SMALLINT NOT NULL,
                item_id INT NOT NULL,
                specification VARCHAR(500) NOT NULL DEFAULT '',
                qty DECIMAL(14,3) NOT NULL,
                unit VARCHAR(30) NOT NULL,
                unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
                fin1_id INT NULL, fin2_id INT NULL, fin3_id INT NULL, fin4_id INT NULL,
                KEY ix_pr (pr_id),
                CONSTRAINT fk_prl_pr FOREIGN KEY (pr_id) REFERENCES pr(id) ON DELETE CASCADE,
                CONSTRAINT fk_prl_item FOREIGN KEY (item_id) REFERENCES items(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS pr_approvals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pr_id INT NOT NULL,
                approver_id INT NOT NULL,
                fin_value_id INT NULL,
                status ENUM('pending','approved','returned') NOT NULL DEFAULT 'pending',
                comment TEXT,
                decided_by INT NULL,
                decided_at DATETIME NULL,
                KEY ix_pr (pr_id), KEY ix_appr (approver_id, status),
                CONSTRAINT fk_pra_pr FOREIGN KEY (pr_id) REFERENCES pr(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS pr_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pr_id INT NOT NULL,
                user_id INT NULL,
                action VARCHAR(40) NOT NULL,
                comment TEXT,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY ix_pr (pr_id),
                CONSTRAINT fk_prh_pr FOREIGN KEY (pr_id) REFERENCES pr(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        4 => [
            // Approval thresholds: higher approval levels above the cost centre Budget Holder
            "CREATE TABLE IF NOT EXISTS approval_levels (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(80) NOT NULL,
                min_amount DECIMAL(14,2) NOT NULL,
                approver_user_id INT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                CONSTRAINT fk_al_user FOREIGN KEY (approver_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "ALTER TABLE pr_approvals ADD COLUMN stage TINYINT NOT NULL DEFAULT 1, ADD COLUMN level_name VARCHAR(80) NULL",
        ],
        5 => [
            // Supplier register
            "CREATE TABLE IF NOT EXISTS suppliers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(20) NOT NULL UNIQUE,
                name VARCHAR(190) NOT NULL,
                contact_name VARCHAR(120) NOT NULL DEFAULT '',
                email VARCHAR(190) NOT NULL DEFAULT '',
                phone VARCHAR(60) NOT NULL DEFAULT '',
                address VARCHAR(255) NOT NULL DEFAULT '',
                country VARCHAR(80) NOT NULL DEFAULT '',
                registration_no VARCHAR(80) NOT NULL DEFAULT '',
                status ENUM('active','inactive','blacklisted') NOT NULL DEFAULT 'active',
                notes TEXT,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY ix_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS supplier_categories (
                supplier_id INT NOT NULL,
                category_id INT NOT NULL,
                PRIMARY KEY (supplier_id, category_id),
                CONSTRAINT fk_sc_s FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
                CONSTRAINT fk_sc_c FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // Procurement methods by amount band
            "CREATE TABLE IF NOT EXISTS proc_methods (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(80) NOT NULL,
                max_amount DECIMAL(14,2) NULL,
                min_quotes TINYINT NOT NULL DEFAULT 1,
                sealed TINYINT(1) NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        6 => [
            "ALTER TABLE pr MODIFY status ENUM('draft','submitted','returned','approved','processing','cancelled') NOT NULL DEFAULT 'draft'",
            "CREATE TABLE IF NOT EXISTS rfq (
                id INT AUTO_INCREMENT PRIMARY KEY,
                number VARCHAR(40) NOT NULL UNIQUE,
                pr_id INT NOT NULL,
                title VARCHAR(200) NOT NULL,
                method_name VARCHAR(80) NOT NULL,
                min_quotes TINYINT NOT NULL DEFAULT 1,
                sealed TINYINT(1) NOT NULL DEFAULT 0,
                currency CHAR(3) NOT NULL,
                instructions TEXT,
                delivery_location VARCHAR(200) NOT NULL DEFAULT '',
                deadline DATETIME NULL,
                status ENUM('draft','open','closed','cancelled') NOT NULL DEFAULT 'draft',
                created_by INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sent_at DATETIME NULL,
                opened_at DATETIME NULL,
                opened_by INT NULL,
                KEY ix_pr (pr_id),
                CONSTRAINT fk_rfq_pr FOREIGN KEY (pr_id) REFERENCES pr(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS rfq_lines (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rfq_id INT NOT NULL,
                pr_line_id INT NULL,
                line_no SMALLINT NOT NULL,
                item_id INT NOT NULL,
                description VARCHAR(255) NOT NULL,
                specification VARCHAR(500) NOT NULL DEFAULT '',
                qty DECIMAL(14,3) NOT NULL,
                unit VARCHAR(30) NOT NULL,
                KEY ix_rfq (rfq_id),
                CONSTRAINT fk_rfql_rfq FOREIGN KEY (rfq_id) REFERENCES rfq(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS rfq_suppliers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rfq_id INT NOT NULL,
                supplier_id INT NOT NULL,
                token CHAR(48) NULL UNIQUE,
                invited_at DATETIME NULL,
                email_status VARCHAR(255) NULL,
                viewed_at DATETIME NULL,
                declined_at DATETIME NULL,
                UNIQUE KEY uq_rfq_sup (rfq_id, supplier_id),
                CONSTRAINT fk_rs_rfq FOREIGN KEY (rfq_id) REFERENCES rfq(id) ON DELETE CASCADE,
                CONSTRAINT fk_rs_sup FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS quotes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rfq_id INT NOT NULL,
                supplier_id INT NOT NULL,
                delivery_days SMALLINT NULL,
                validity_days SMALLINT NULL,
                notes TEXT,
                entered_by INT NULL,
                submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_quote (rfq_id, supplier_id),
                CONSTRAINT fk_q_rfq FOREIGN KEY (rfq_id) REFERENCES rfq(id) ON DELETE CASCADE,
                CONSTRAINT fk_q_sup FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS quote_lines (
                id INT AUTO_INCREMENT PRIMARY KEY,
                quote_id INT NOT NULL,
                rfq_line_id INT NOT NULL,
                unit_price DECIMAL(14,2) NULL,
                remarks VARCHAR(255) NOT NULL DEFAULT '',
                UNIQUE KEY uq_ql (quote_id, rfq_line_id),
                CONSTRAINT fk_ql_q FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
                CONSTRAINT fk_ql_l FOREIGN KEY (rfq_line_id) REFERENCES rfq_lines(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS rfq_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rfq_id INT NOT NULL,
                user_id INT NULL,
                supplier_id INT NULL,
                action VARCHAR(60) NOT NULL,
                comment TEXT,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY ix_rfq (rfq_id),
                CONSTRAINT fk_rh_rfq FOREIGN KEY (rfq_id) REFERENCES rfq(id) ON DELETE CASCADE
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
