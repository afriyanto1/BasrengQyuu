ALTER TABLE users
    ADD COLUMN phone VARCHAR(30) NULL AFTER email,
    ADD COLUMN date_of_birth DATE NULL AFTER phone,
    ADD COLUMN email_verified_at DATETIME NULL AFTER date_of_birth;

UPDATE users
SET email_verified_at = UTC_TIMESTAMP()
WHERE email_verified_at IS NULL;

CREATE TABLE email_verification_tokens (
    user_id INT NOT NULL PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
