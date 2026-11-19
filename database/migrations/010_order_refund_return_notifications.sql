ALTER TABLE notifications
    MODIFY type ENUM(
        'payment_paid',
        'payment_refunded',
        'order_shipping',
        'order_completed',
        'order_returned'
    ) NOT NULL;
