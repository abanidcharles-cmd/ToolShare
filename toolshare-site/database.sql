-- ToolShare database setup (MySQL 5.7+ / MariaDB 10.4+)
-- Run:  mysql -u root -p < database.sql
-- Creates two databases:
--   event_management_db  accounts + participant registry
--   toolshare_db         equipment, requests, loans, reservations, logs

CREATE DATABASE IF NOT EXISTS event_management_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE event_management_db;

CREATE TABLE IF NOT EXISTS users_table (
    user_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username   VARCHAR(100) NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,                 -- bcrypt hash from password_hash()
    role       ENUM('participant','custodian') NOT NULL DEFAULT 'participant',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS participants (
    participant_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NULL UNIQUE,
    first_name     VARCHAR(100) NOT NULL,
    last_name      VARCHAR(100) NOT NULL,
    email          VARCHAR(255) NOT NULL UNIQUE,
    gender         VARCHAR(30)  NOT NULL,
    birthdate      DATE NOT NULL,
    city           VARCHAR(150) NOT NULL DEFAULT '',
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_participants_user FOREIGN KEY (user_id) REFERENCES users_table(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_participants_name (last_name, first_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS registrations (
    registration_id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id  INT NOT NULL,
    registered_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_registrations_participant FOREIGN KEY (participant_id) REFERENCES participants(participant_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- First custodian account. Username: custodian   Password: ChangeMe123!   (change it after first login)
INSERT INTO users_table (username, password, role)
SELECT 'custodian', '$2y$10$V81fyXAtqRJC4qyTzAc/PumOz5Zt8huAK.APSX1seICMXMOV0i3W2', 'custodian'
WHERE NOT EXISTS (SELECT 1 FROM users_table WHERE username = 'custodian');

INSERT INTO participants (user_id, first_name, last_name, email, gender, birthdate, city)
SELECT user_id, 'Community', 'Custodian', 'custodian@example.com', 'Other', '1990-01-01', 'Barangay'
FROM users_table WHERE username = 'custodian'
  AND NOT EXISTS (SELECT 1 FROM participants WHERE email = 'custodian@example.com');

-- ------------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS toolshare_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE toolshare_db;

CREATE TABLE IF NOT EXISTS categories (
    category_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL UNIQUE,
    description   VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tools (
    tool_id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id         INT UNSIGNED NOT NULL,
    tool_name           VARCHAR(150) NOT NULL,
    description         TEXT NOT NULL,
    quantity            INT UNSIGNED NOT NULL DEFAULT 1,
    condition_status    VARCHAR(40) NOT NULL DEFAULT 'Good',
    availability_status VARCHAR(40) NOT NULL DEFAULT 'Available',
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tools_category FOREIGN KEY (category_id) REFERENCES categories(category_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_tools_name (tool_name),
    INDEX idx_tools_category (category_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS borrow_requests (
    request_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    tool_id        INT UNSIGNED NOT NULL,
    request_date   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    borrow_date    DATE NOT NULL,
    due_date       DATE NOT NULL,
    request_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    CONSTRAINT fk_requests_tool FOREIGN KEY (tool_id) REFERENCES tools(tool_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_requests_user (user_id),
    INDEX idx_requests_dates (tool_id, borrow_date, due_date, request_status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS borrow_transactions (
    transaction_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id         INT UNSIGNED NOT NULL UNIQUE,
    actual_return_date DATE NULL,
    transaction_status VARCHAR(30) NOT NULL DEFAULT 'borrowed',
    CONSTRAINT fk_transactions_request FOREIGN KEY (request_id) REFERENCES borrow_requests(request_id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tool_condition_logs (
    condition_log_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_id     INT UNSIGNED NOT NULL,
    recorded_by        INT UNSIGNED NOT NULL,
    condition_before   VARCHAR(80) NOT NULL,
    condition_after    VARCHAR(80) NOT NULL,
    damage_description TEXT NOT NULL,
    recorded_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_condition_transaction FOREIGN KEY (transaction_id) REFERENCES borrow_transactions(transaction_id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservations (
    reservation_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id            INT UNSIGNED NOT NULL,
    tool_id            INT UNSIGNED NOT NULL,
    reservation_date   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    start_date         DATE NOT NULL,
    end_date           DATE NOT NULL,
    reservation_status VARCHAR(30) NOT NULL DEFAULT 'reserved',
    CONSTRAINT fk_reservations_tool FOREIGN KEY (tool_id) REFERENCES tools(tool_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_reservations_dates (tool_id, start_date, end_date, reservation_status),
    INDEX idx_reservations_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
    notification_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id           INT UNSIGNED NOT NULL,
    transaction_id    INT UNSIGNED NULL,
    message           VARCHAR(255) NOT NULL,
    notification_type VARCHAR(40) NOT NULL DEFAULT 'info',
    is_read           TINYINT(1) NOT NULL DEFAULT 0,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_transaction FOREIGN KEY (transaction_id) REFERENCES borrow_transactions(transaction_id) ON UPDATE CASCADE ON DELETE SET NULL,
    UNIQUE KEY uq_notifications_reminder (user_id, transaction_id, notification_type),
    INDEX idx_notifications_user (user_id, is_read)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activity_logs (
    activity_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    action      VARCHAR(80) NOT NULL,
    description VARCHAR(255) NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_user (user_id, created_at)
) ENGINE=InnoDB;

INSERT IGNORE INTO categories (category_name, description) VALUES
 ('Hand Tools', 'Manual tools for household and repair projects.'),
 ('Power Tools', 'Powered tools for construction and maintenance.'),
 ('Garden Equipment', 'Tools and equipment for gardening and outdoor work.'),
 ('Safety Equipment', 'Protective gear for safe tool use.'),
 ('Other', 'Community equipment in other categories.');
