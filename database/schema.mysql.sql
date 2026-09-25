-- MySQL schema (production). Run this against your MySQL instance.
-- After creating the app's DB user, grant only INSERT on audit_log
-- (no UPDATE/DELETE) so the audit trail is append-only:
--   GRANT SELECT, INSERT ON identity_vault.audit_log TO 'app_user'@'%';

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_number_hash CHAR(64) NOT NULL UNIQUE,
    dha_id_mock VARCHAR(64),
    full_name VARCHAR(255) NOT NULL,
    dob DATE NOT NULL,
    phone_encrypted VARBINARY(255) NOT NULL,
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE biometric_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    template_type ENUM('face','voice') NOT NULL,
    template_vector VARBINARY(4096) NOT NULL,
    algorithm_version VARCHAR(32) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE consents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL,
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    revoked_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_consents_user (user_id, expires_at)
);

CREATE TABLE dha_verifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(64) NOT NULL,
    query_type ENUM('realtime','batch') NOT NULL,
    match_result ENUM('match','no_match','error') NOT NULL,
    error_code VARCHAR(64),
    raw_response LONGTEXT,
    cost_cents INT UNSIGNED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    bank_partner_id VARCHAR(64) NOT NULL,
    amount_cents BIGINT UNSIGNED,
    currency CHAR(3) NOT NULL DEFAULT 'ZAR',
    risk_score DECIMAL(5,2),
    decision ENUM('approved','rejected','flagged') NOT NULL,
    decision_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE behavior_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    device_fingerprint VARCHAR(255),
    ip_country VARCHAR(2),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE rate_limit_hits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket_key VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rate_limit_bucket (bucket_key, created_at)
);

CREATE TABLE audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor VARCHAR(128) NOT NULL,
    action VARCHAR(128) NOT NULL,
    subject_table VARCHAR(64),
    subject_id BIGINT UNSIGNED,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
