ALTER TABLE users
    MODIFY COLUMN role ENUM('seller','owner','admin','buyer') NOT NULL DEFAULT 'buyer';

UPDATE users
SET role = 'owner'
WHERE role = 'seller';

ALTER TABLE users
    MODIFY COLUMN role ENUM('owner','admin','buyer') NOT NULL DEFAULT 'buyer';
