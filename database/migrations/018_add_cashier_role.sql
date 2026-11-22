ALTER TABLE users
    MODIFY COLUMN role ENUM('owner','admin','cashier','buyer') NOT NULL DEFAULT 'buyer';
