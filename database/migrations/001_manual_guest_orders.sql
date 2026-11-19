-- Jalankan satu kali pada database lama agar pesanan manual tanpa akun dapat disimpan.
ALTER TABLE orders
    MODIFY buyer_id INT NULL,
    MODIFY status ENUM('pending','waiting_approval','paid','processing','completed','cancelled') NOT NULL DEFAULT 'pending',
    ADD customer_name VARCHAR(120) NULL,
    ADD customer_phone VARCHAR(30) NULL;
