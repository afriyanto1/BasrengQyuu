UPDATE orders o
JOIN users u ON u.id = o.buyer_id
SET
    o.total = o.total + CASE
        WHEN LOWER(TRIM(u.province)) = 'banten'
            AND LOWER(TRIM(u.city)) = 'kabupaten tangerang'
            AND (
                LOWER(TRIM(u.district)) IN ('pasar kemis', 'sepatan', 'rajeg')
                OR (
                    LOWER(TRIM(u.district)) = 'mauk'
                    AND LOWER(TRIM(u.village)) = 'jatiwaringin'
                )
            ) THEN 0
        WHEN (
            LOWER(TRIM(u.province)) IN ('dki jakarta', 'daerah khusus ibukota jakarta')
            AND LOWER(TRIM(u.city)) IN (
                'kota jakarta pusat', 'kota jakarta utara', 'kota jakarta barat',
                'kota jakarta selatan', 'kota jakarta timur',
                'kota administrasi jakarta pusat', 'kota administrasi jakarta utara',
                'kota administrasi jakarta barat', 'kota administrasi jakarta selatan',
                'kota administrasi jakarta timur', 'kabupaten administrasi kepulauan seribu'
            )
        ) OR (
            LOWER(TRIM(u.province)) = 'jawa barat'
            AND LOWER(TRIM(u.city)) IN (
                'kabupaten bogor', 'kota bogor', 'kota depok',
                'kabupaten bekasi', 'kota bekasi'
            )
        ) OR (
            LOWER(TRIM(u.province)) = 'banten'
            AND LOWER(TRIM(u.city)) IN (
                'kabupaten tangerang', 'kota tangerang', 'kota tangerang selatan'
            )
        ) THEN 25000
        ELSE 50000
    END,
    o.shipping_area = CASE
        WHEN LOWER(TRIM(u.province)) = 'banten'
            AND LOWER(TRIM(u.city)) = 'kabupaten tangerang'
            AND (
                LOWER(TRIM(u.district)) IN ('pasar kemis', 'sepatan', 'rajeg')
                OR (
                    LOWER(TRIM(u.district)) = 'mauk'
                    AND LOWER(TRIM(u.village)) = 'jatiwaringin'
                )
            ) THEN 'free_zone'
        WHEN (
            LOWER(TRIM(u.province)) IN ('dki jakarta', 'daerah khusus ibukota jakarta')
            AND LOWER(TRIM(u.city)) IN (
                'kota jakarta pusat', 'kota jakarta utara', 'kota jakarta barat',
                'kota jakarta selatan', 'kota jakarta timur',
                'kota administrasi jakarta pusat', 'kota administrasi jakarta utara',
                'kota administrasi jakarta barat', 'kota administrasi jakarta selatan',
                'kota administrasi jakarta timur', 'kabupaten administrasi kepulauan seribu'
            )
        ) OR (
            LOWER(TRIM(u.province)) = 'jawa barat'
            AND LOWER(TRIM(u.city)) IN (
                'kabupaten bogor', 'kota bogor', 'kota depok',
                'kabupaten bekasi', 'kota bekasi'
            )
        ) OR (
            LOWER(TRIM(u.province)) = 'banten'
            AND LOWER(TRIM(u.city)) IN (
                'kabupaten tangerang', 'kota tangerang', 'kota tangerang selatan'
            )
        ) THEN 'jabodetabek'
        ELSE 'outside'
    END,
    o.shipping_fee = CASE
        WHEN LOWER(TRIM(u.province)) = 'banten'
            AND LOWER(TRIM(u.city)) = 'kabupaten tangerang'
            AND (
                LOWER(TRIM(u.district)) IN ('pasar kemis', 'sepatan', 'rajeg')
                OR (
                    LOWER(TRIM(u.district)) = 'mauk'
                    AND LOWER(TRIM(u.village)) = 'jatiwaringin'
                )
            ) THEN 0
        WHEN (
            LOWER(TRIM(u.province)) IN ('dki jakarta', 'daerah khusus ibukota jakarta')
            AND LOWER(TRIM(u.city)) IN (
                'kota jakarta pusat', 'kota jakarta utara', 'kota jakarta barat',
                'kota jakarta selatan', 'kota jakarta timur',
                'kota administrasi jakarta pusat', 'kota administrasi jakarta utara',
                'kota administrasi jakarta barat', 'kota administrasi jakarta selatan',
                'kota administrasi jakarta timur', 'kabupaten administrasi kepulauan seribu'
            )
        ) OR (
            LOWER(TRIM(u.province)) = 'jawa barat'
            AND LOWER(TRIM(u.city)) IN (
                'kabupaten bogor', 'kota bogor', 'kota depok',
                'kabupaten bekasi', 'kota bekasi'
            )
        ) OR (
            LOWER(TRIM(u.province)) = 'banten'
            AND LOWER(TRIM(u.city)) IN (
                'kabupaten tangerang', 'kota tangerang', 'kota tangerang selatan'
            )
        ) THEN 25000
        ELSE 50000
    END,
    o.shipping_distance_km = NULL,
    o.payment_proof = CASE
        WHEN o.status = 'waiting_approval' THEN NULL
        ELSE o.payment_proof
    END,
    o.status = CASE
        WHEN o.status = 'waiting_approval' THEN 'pending'
        ELSE o.status
    END
WHERE o.shipping_area IS NULL
    AND o.status IN ('pending', 'waiting_approval')
    AND u.province IS NOT NULL
    AND u.city IS NOT NULL
    AND u.district IS NOT NULL;
