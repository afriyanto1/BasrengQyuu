ALTER TABLE users
    ADD COLUMN username VARCHAR(30) NULL AFTER name,
    ADD COLUMN gender ENUM('male','female','prefer_not_to_say') NULL AFTER date_of_birth;

UPDATE users
SET username = CONCAT('user', id)
WHERE username IS NULL OR username = '';

ALTER TABLE users
    MODIFY COLUMN username VARCHAR(30) NOT NULL,
    ADD UNIQUE KEY uq_users_username (username);
