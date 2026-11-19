ALTER TABLE orders
    ADD shipping_distance_km DECIMAL(7,2) NULL,
    ADD shipping_area ENUM('pickup','jabodetabek','outside') NULL,
    ADD shipping_fee DECIMAL(12,2) NOT NULL DEFAULT 0;
