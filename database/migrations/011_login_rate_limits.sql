CREATE TABLE login_rate_limits (
    ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    attempts TINYINT UNSIGNED NOT NULL,
    window_started_at DATETIME NOT NULL,
    INDEX idx_login_rate_limits_window_started_at (window_started_at)
);
