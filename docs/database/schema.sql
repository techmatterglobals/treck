-- ============================================================================
-- Treck — Employee Productivity & PC Activity Monitoring System
-- Reference MySQL 8 schema (illustrative DDL).
--
-- This mirrors the design in docs/03-database-design.md. In the actual project
-- these tables are created via Laravel migrations. Package-managed tables
-- (Sanctum personal_access_tokens, Spatie roles/permissions) are omitted here.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- Organization & identity
-- ----------------------------------------------------------------------------

CREATE TABLE users (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(191) NOT NULL,
    email           VARCHAR(191) NOT NULL,
    email_verified_at TIMESTAMP NULL,
    password        VARCHAR(191) NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    remember_token  VARCHAR(100) NULL,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY users_email_unique (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE departments (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120) NOT NULL,
    manager_id  BIGINT UNSIGNED NULL,
    created_at  TIMESTAMP NULL,
    updated_at  TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY departments_name_unique (name),
    KEY departments_manager_id_foreign (manager_id),
    CONSTRAINT departments_manager_id_foreign
        FOREIGN KEY (manager_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE teams (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    department_id  BIGINT UNSIGNED NOT NULL,
    name           VARCHAR(120) NOT NULL,
    lead_id        BIGINT UNSIGNED NULL,
    created_at     TIMESTAMP NULL,
    updated_at     TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY teams_department_id_foreign (department_id),
    KEY teams_lead_id_foreign (lead_id),
    CONSTRAINT teams_department_id_foreign
        FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE CASCADE,
    CONSTRAINT teams_lead_id_foreign
        FOREIGN KEY (lead_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE employees (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED NOT NULL,
    department_id  BIGINT UNSIGNED NULL,
    team_id        BIGINT UNSIGNED NULL,
    employee_code  VARCHAR(40) NOT NULL,
    designation    VARCHAR(120) NULL,
    joined_on      DATE NULL,
    deleted_at     TIMESTAMP NULL,
    created_at     TIMESTAMP NULL,
    updated_at     TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY employees_user_id_unique (user_id),
    UNIQUE KEY employees_employee_code_unique (employee_code),
    KEY employees_department_id_foreign (department_id),
    KEY employees_team_id_foreign (team_id),
    CONSTRAINT employees_user_id_foreign
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT employees_department_id_foreign
        FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL,
    CONSTRAINT employees_team_id_foreign
        FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE devices (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id   BIGINT UNSIGNED NULL,
    device_uuid   VARCHAR(64) NOT NULL,
    hostname      VARCHAR(191) NULL,
    os            VARCHAR(60) NULL,
    agent_version VARCHAR(20) NULL,
    status        ENUM('online','idle','locked','offline') NOT NULL DEFAULT 'offline',
    last_seen_at  TIMESTAMP NULL,
    paired_at     TIMESTAMP NULL,
    deleted_at    TIMESTAMP NULL,
    created_at    TIMESTAMP NULL,
    updated_at    TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY devices_device_uuid_unique (device_uuid),
    KEY devices_employee_id_foreign (employee_id),
    CONSTRAINT devices_employee_id_foreign
        FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Productivity catalog
-- ----------------------------------------------------------------------------

CREATE TABLE productivity_categories (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name           VARCHAR(120) NOT NULL,
    default_rating ENUM('productive','unproductive','neutral') NOT NULL DEFAULT 'neutral',
    created_at     TIMESTAMP NULL,
    updated_at     TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY productivity_categories_name_unique (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE applications (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(191) NOT NULL,
    executable   VARCHAR(191) NULL,
    domain       VARCHAR(191) NULL,
    category_id  BIGINT UNSIGNED NULL,
    rating       ENUM('productive','unproductive','neutral') NULL,
    created_at   TIMESTAMP NULL,
    updated_at   TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY applications_category_id_foreign (category_id),
    KEY applications_executable_index (executable),
    KEY applications_domain_index (domain),
    CONSTRAINT applications_category_id_foreign
        FOREIGN KEY (category_id) REFERENCES productivity_categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Raw telemetry (high volume)
-- ----------------------------------------------------------------------------

CREATE TABLE work_sessions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id       BIGINT UNSIGNED NOT NULL,
    employee_id     BIGINT UNSIGNED NOT NULL,
    login_at        TIMESTAMP NOT NULL,
    logout_at       TIMESTAMP NULL,
    active_seconds  INT UNSIGNED NOT NULL DEFAULT 0,
    idle_seconds    INT UNSIGNED NOT NULL DEFAULT 0,
    end_reason      ENUM('logout','shutdown','timeout','reconciled') NULL,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY work_sessions_employee_login_index (employee_id, login_at),
    KEY work_sessions_device_login_index (device_id, login_at),
    CONSTRAINT work_sessions_device_id_foreign
        FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE,
    CONSTRAINT work_sessions_employee_id_foreign
        FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Highest-volume table: partition monthly by sampled_at and prune per retention.
-- NOTE: MySQL partitioned tables cannot carry foreign keys; referential
-- integrity for heartbeats is enforced at the application layer.
CREATE TABLE activity_heartbeats (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    work_session_id  BIGINT UNSIGNED NOT NULL,
    device_id        BIGINT UNSIGNED NOT NULL,
    sampled_at       TIMESTAMP NOT NULL,
    is_active        TINYINT(1) NOT NULL,
    idempotency_key  CHAR(36) NOT NULL,
    created_at       TIMESTAMP NULL,
    PRIMARY KEY (id, sampled_at),
    UNIQUE KEY heartbeats_device_key_unique (device_id, idempotency_key, sampled_at),
    KEY heartbeats_session_sampled_index (work_session_id, sampled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
PARTITION BY RANGE (TO_DAYS(sampled_at)) (
    PARTITION p2026_07 VALUES LESS THAN (TO_DAYS('2026-08-01')),
    PARTITION p2026_08 VALUES LESS THAN (TO_DAYS('2026-09-01')),
    PARTITION pmax     VALUES LESS THAN MAXVALUE
);

CREATE TABLE idle_periods (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    work_session_id  BIGINT UNSIGNED NOT NULL,
    started_at       TIMESTAMP NOT NULL,
    ended_at         TIMESTAMP NULL,
    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    created_at       TIMESTAMP NULL,
    updated_at       TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY idle_periods_session_index (work_session_id, started_at),
    CONSTRAINT idle_periods_work_session_id_foreign
        FOREIGN KEY (work_session_id) REFERENCES work_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- App usage: also monthly-partitioned; FK enforced in the application layer.
CREATE TABLE app_usage_logs (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    work_session_id  BIGINT UNSIGNED NOT NULL,
    application_id   BIGINT UNSIGNED NULL,
    raw_title        VARCHAR(191) NULL,
    started_at       TIMESTAMP NOT NULL,
    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    created_at       TIMESTAMP NULL,
    PRIMARY KEY (id, started_at),
    KEY app_usage_session_started_index (work_session_id, started_at),
    KEY app_usage_application_index (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
PARTITION BY RANGE (TO_DAYS(started_at)) (
    PARTITION p2026_07 VALUES LESS THAN (TO_DAYS('2026-08-01')),
    PARTITION p2026_08 VALUES LESS THAN (TO_DAYS('2026-09-01')),
    PARTITION pmax     VALUES LESS THAN MAXVALUE
);

-- ----------------------------------------------------------------------------
-- Derived aggregates (query-friendly)
-- ----------------------------------------------------------------------------

CREATE TABLE attendances (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id    BIGINT UNSIGNED NOT NULL,
    work_date      DATE NOT NULL,
    first_in_at    TIMESTAMP NULL,
    last_out_at    TIMESTAMP NULL,
    work_seconds   INT UNSIGNED NOT NULL DEFAULT 0,
    active_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    idle_seconds   INT UNSIGNED NOT NULL DEFAULT 0,
    status         ENUM('present','late','absent','half_day','on_leave') NOT NULL DEFAULT 'absent',
    is_corrected   TINYINT(1) NOT NULL DEFAULT 0,
    created_at     TIMESTAMP NULL,
    updated_at     TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY attendances_employee_date_unique (employee_id, work_date),
    CONSTRAINT attendances_employee_id_foreign
        FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE productivity_reports (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id          BIGINT UNSIGNED NOT NULL,
    period_type          ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'daily',
    period_date          DATE NOT NULL,
    active_seconds       INT UNSIGNED NOT NULL DEFAULT 0,
    productive_seconds   INT UNSIGNED NOT NULL DEFAULT 0,
    unproductive_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    neutral_seconds      INT UNSIGNED NOT NULL DEFAULT 0,
    productivity_score   DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    created_at           TIMESTAMP NULL,
    updated_at           TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY prod_reports_emp_type_date_unique (employee_id, period_type, period_date),
    CONSTRAINT productivity_reports_employee_id_foreign
        FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Cross-cutting
-- ----------------------------------------------------------------------------

CREATE TABLE screenshots (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id BIGINT UNSIGNED NOT NULL,
    device_id   BIGINT UNSIGNED NOT NULL,
    path        VARCHAR(255) NOT NULL,
    captured_at TIMESTAMP NOT NULL,
    created_at  TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY screenshots_employee_captured_index (employee_id, captured_at),
    CONSTRAINT screenshots_employee_id_foreign
        FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
    CONSTRAINT screenshots_device_id_foreign
        FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key`      VARCHAR(120) NOT NULL,
    value      JSON NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY settings_key_unique (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_logs (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED NULL,
    action         VARCHAR(80) NOT NULL,
    auditable_type VARCHAR(191) NULL,
    auditable_id   BIGINT UNSIGNED NULL,
    changes        JSON NULL,
    ip_address     VARCHAR(45) NULL,
    created_at     TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY audit_logs_user_id_foreign (user_id),
    KEY audit_logs_auditable_index (auditable_type, auditable_id),
    CONSTRAINT audit_logs_user_id_foreign
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
