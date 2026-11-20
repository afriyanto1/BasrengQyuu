ALTER TABLE users
    ADD country_code VARCHAR(5) NOT NULL DEFAULT 'ID' AFTER role;

ALTER TABLE orders
    MODIFY shipping_area VARCHAR(50) NULL;
