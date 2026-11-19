CREATE DATABASE IF NOT EXISTS basreng_pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE basreng_pos;

CREATE TABLE users (
 id INT AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 email VARCHAR(160) NOT NULL UNIQUE,
 password VARCHAR(255) NOT NULL,
 role ENUM('seller','buyer') NOT NULL DEFAULT 'buyer',
 province VARCHAR(120) NULL,
 city VARCHAR(120) NULL,
 district VARCHAR(120) NULL,
 village VARCHAR(120) NULL,
 address VARCHAR(500) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE password_reset_tokens (
 user_id INT NOT NULL PRIMARY KEY,
 token_hash CHAR(64) NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE products (
 id INT AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(150) NOT NULL,
 description TEXT,
 price DECIMAL(12,2) NOT NULL DEFAULT 0,
 stock INT NOT NULL DEFAULT 0,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE materials (
 id INT AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(150) NOT NULL,
 unit VARCHAR(30) NOT NULL DEFAULT 'kg',
 stock DECIMAL(12,2) NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE purchases (
 id INT AUTO_INCREMENT PRIMARY KEY,
 material_id INT NOT NULL,
 qty DECIMAL(12,2) NOT NULL,
 unit_cost DECIMAL(12,2) NOT NULL,
 total DECIMAL(12,2) NOT NULL,
 note VARCHAR(255),
 purchased_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (material_id) REFERENCES materials(id)
);

CREATE TABLE orders (
 id INT AUTO_INCREMENT PRIMARY KEY,
 buyer_id INT NULL,
 customer_name VARCHAR(120) NULL,
 customer_phone VARCHAR(30) NULL,
 total DECIMAL(12,2) NOT NULL,
 shipping_distance_km DECIMAL(7,2) NULL,
 shipping_area ENUM('pickup','jabodetabek','outside','free_zone') NULL,
 shipping_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
 tracking_number VARCHAR(100) NULL,
 status ENUM('not_shipped','shipping','completed','returned') NOT NULL DEFAULT 'not_shipped',
 payment_status ENUM('unpaid','awaiting_seller','paid','refunded') NOT NULL DEFAULT 'unpaid',
 stock_deducted TINYINT(1) NOT NULL DEFAULT 0,
 payment_method VARCHAR(50) NOT NULL DEFAULT 'qris',
 order_seq INT NOT NULL DEFAULT 1,
 order_code BIGINT UNSIGNED NULL,
 payment_proof VARCHAR(255) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (buyer_id) REFERENCES users(id)
);

CREATE TABLE order_items (
 id INT AUTO_INCREMENT PRIMARY KEY,
 order_id INT NOT NULL,
 product_id INT NOT NULL,
 product_name VARCHAR(150) NOT NULL,
 qty INT NOT NULL,
 price DECIMAL(12,2) NOT NULL,
 subtotal DECIMAL(12,2) NOT NULL,
 FOREIGN KEY (order_id) REFERENCES orders(id),
 FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE notifications (
 id INT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 order_id INT NULL,
 type ENUM('payment_paid','payment_refunded','order_shipping','order_completed','order_returned') NOT NULL,
 message VARCHAR(255) NOT NULL,
 is_read TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_notifications_user_read_created (user_id, is_read, created_at),
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
);

CREATE TABLE settings (
 setting_key VARCHAR(80) PRIMARY KEY,
 setting_value TEXT
);

INSERT INTO settings(setting_key,setting_value) VALUES
('store_name','Basreng Store'),
('seller_qr_path','');

-- Login penjual awal:
-- Email: admin@basreng.local
-- Password: password
-- Buat hash menggunakan PHP: php -r "echo password_hash('password', PASSWORD_DEFAULT);"
INSERT INTO users(name,email,password,role) VALUES
('Administrator','admin@basreng.local','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','seller');
