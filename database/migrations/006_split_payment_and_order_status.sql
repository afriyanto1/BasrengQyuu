ALTER TABLE orders
    ADD payment_status ENUM('unpaid','awaiting_seller','paid','refunded') NOT NULL DEFAULT 'unpaid' AFTER status;

ALTER TABLE orders
    ADD stock_deducted TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_status;

UPDATE orders
SET payment_status = CASE status
    WHEN 'waiting_approval' THEN 'awaiting_seller'
    WHEN 'paid' THEN 'paid'
    WHEN 'processing' THEN 'paid'
    WHEN 'completed' THEN 'paid'
    ELSE CASE
        WHEN payment_proof IS NOT NULL AND payment_proof <> '' THEN 'awaiting_seller'
        ELSE 'unpaid'
    END
END;

UPDATE orders
SET stock_deducted = 1
WHERE status IN ('paid', 'processing', 'completed');

ALTER TABLE orders
    MODIFY status ENUM(
        'pending','waiting_approval','paid','processing','completed','cancelled',
        'not_shipped','shipping','returned'
    ) NOT NULL DEFAULT 'pending';

UPDATE orders
SET status = CASE status
    WHEN 'pending' THEN 'not_shipped'
    WHEN 'waiting_approval' THEN 'not_shipped'
    WHEN 'paid' THEN 'not_shipped'
    WHEN 'processing' THEN 'shipping'
    WHEN 'completed' THEN 'completed'
    WHEN 'cancelled' THEN 'returned'
END;

ALTER TABLE orders
    MODIFY status ENUM('not_shipped','shipping','completed','returned') NOT NULL DEFAULT 'not_shipped';
