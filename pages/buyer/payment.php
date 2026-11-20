<?php
require dirname(__DIR__, 2) . "/includes/config.php"; 
require_role('buyer');

$id = (int)($_GET['id'] ?? 0);
$s = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND buyer_id = ?');
$s->execute([$id, user()['id']]);
$o = $s->fetch();

if (!$o) {
    http_response_code(404);
    exit('Pesanan tidak ditemukan');
}

$shipping_error = null;
if (
    $o['shipping_area'] === null &&
    $o['status'] === 'not_shipped' &&
    in_array($o['payment_status'], ['unpaid', 'awaiting_seller'], true)
) {
    try {
        $address_stmt = $pdo->prepare(
            'SELECT country_code, province, city, district, village FROM users WHERE id = ?'
        );
        $address_stmt->execute([$o['buyer_id']]);
        $address = $address_stmt->fetch();
        if (!$address) {
            throw new InvalidArgumentException('Alamat akun tidak ditemukan.');
        }

        $shipping_area = shipping_area_for_address($address);
        $shipping_fee = calculate_shipping_fee($shipping_area);
        $product_total = (float) $o['total'] - (float) $o['shipping_fee'];
        $update_shipping = $pdo->prepare(
            "UPDATE orders SET total = ?, shipping_area = ?, shipping_fee = ?, shipping_distance_km = NULL WHERE id = ?"
        );
        $update_shipping->execute([
            $product_total + $shipping_fee,
            $shipping_area,
            $shipping_fee,
            $id,
        ]);
        $s->execute([$id, user()['id']]);
        $o = $s->fetch();
    } catch (InvalidArgumentException $e) {
        $shipping_error = $e->getMessage();
    } catch (PDOException $e) {
        error_log('Gagal menghitung ongkir otomatis: ' . $e->getMessage());
        $shipping_error = 'Ongkir gagal dihitung. Silakan coba beberapa saat lagi.';
    }
}

$stmt_items = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
$stmt_items->execute([$id]);
$items = $stmt_items->fetchAll();

$qr = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='seller_qr_path'")->fetchColumn();

$bca_acc = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='seller_bca_account'")->fetchColumn() ?: '729-522-0732 (a.n. Afri Yanto)';
$bca_account_number = (string) $bca_acc;
if (preg_match('/\d(?:[\s-]*\d){4,}/', $bca_account_number, $account_match)) {
    $bca_account_number = preg_replace('/\D+/', '', $account_match[0]);
}
$admin_wa = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='admin_whatsapp'")->fetchColumn() ?: '6282110760086'; // Ubah nomor WA admin Anda

$method = strtoupper($o['payment_method'] ?? 'QRIS');
$payment_status_labels = [
    'unpaid' => 'Belum Dibayar',
    'awaiting_seller' => 'Menunggu Konfirmasi Pembayaran',
    'paid' => 'Lunas',
    'refunded' => 'Pengembalian Dana',
];
$order_status_labels = [
    'not_shipped' => 'Belum Dikirim',
    'shipping' => 'Proses Pengiriman',
    'completed' => 'Selesai',
    'returned' => 'Dikembalikan',
];
$shipping_pending = $o['shipping_area'] === null || $shipping_error !== null;
$order_seq_label = "Pesanan #" . ($o['order_seq'] ?? 1);
$order_unique_id = $o['order_code'] ?? $o['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $method !== 'COD') {
    check_csrf();

    if (!in_array($o['payment_status'], ['unpaid', 'awaiting_seller'], true)) {
        flash('Status pembayaran pesanan ini tidak dapat diubah dari halaman pembeli.');
        header("Location: payment.php?id=" . $id);
        exit;
    }

    if ($shipping_pending) {
        flash($shipping_error ?? 'Ongkir belum dapat ditentukan. Periksa kembali alamat akun Anda.');
        header("Location: payment.php?id=" . $id);
        exit;
    }
    
    if (isset($_FILES['proof']) && $_FILES['proof']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION);
        $filename = 'proof_' . $id . '_' . time() . '.' . $ext;
        $upload_dir = app_path("uploads") . DIRECTORY_SEPARATOR;
        
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        if (move_uploaded_file($_FILES['proof']['tmp_name'], $upload_dir . $filename)) {
            $proof_path = "uploads/" . $filename;
            
            $update = $pdo->prepare("UPDATE orders SET payment_proof = ?, payment_status = 'awaiting_seller' WHERE id = ?");
            $update->execute([$proof_path, $id]);
            
            flash('Bukti pembayaran berhasil diunggah! Silakan klik tombol konfirmasi ke WhatsApp Admin.');
            header("Location: payment.php?id=" . $id);
            exit;
        } else {
            flash('Gagal mengunggah file bukti pembayaran.');
        }
    } else {
        flash('Pilih file gambar bukti pembayaran yang valid.');
    }
}
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Pembayaran <?=$order_seq_label?> - Basreng Store</title>
    <base href="<?= e(app_url('pages/buyer/')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>?v=<?= (int) filemtime(app_path('assets/css/style.css')) ?>">
    <style>
        .payment-copy-row { display:flex; align-items:center; justify-content:center; flex-wrap:wrap; gap:8px; margin-top:12px; }
        .payment-copy-button { margin:0; padding:7px 12px; font-size:13px; }
        .payment-copy-status { min-height:1.5em; }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . "/includes/partials/buyer_nav.php"; ?>
<main>
    <div class="panel payment" style="max-width: 650px; margin: 30px auto;">
        <h1>Pembayaran <?=$order_seq_label?></h1>
        <p class="muted">Nomor ID Unik: <strong>#<?=$order_unique_id?></strong></p>
        
        <?php show_flash(); ?>

        <div style="text-align: left; background: #f8fafc; padding: 16px; border-radius: 8px; margin: 16px 0; border: 1px solid #e2e8f0;">
            <h3 style="margin-top:0;">Rincian Barang (<?=$order_seq_label?>):</h3>
            <ul style="padding-left: 20px; margin-bottom: 0;">
                <?php foreach($items as $it):?>
                    <li><strong><?=e($it['product_name'])?></strong> × <?=$it['qty']?> — <?=rupiah($it['subtotal'])?></li>
                <?php endforeach;?>
            </ul>
        </div>

        <p>Subtotal produk: <strong><?=rupiah((float) $o['total'] - (float) $o['shipping_fee'])?></strong></p>
        <?php if (!$shipping_pending): ?>
            <p>Ongkir: <strong><?= $o['shipping_area'] === 'free_zone' ? 'Gratis (wilayah khusus)' : e(shipping_area_label($o['shipping_area'])) . ' — ' . rupiah($o['shipping_fee']) ?></strong></p>
        <?php endif; ?>
        <h2>Total Tagihan: <?=rupiah($o['total'])?></h2>
        
        <?php if ($shipping_pending): ?>
            <div class="alert" role="status">
                <?=e($shipping_error ?? 'Ongkir belum dapat ditentukan. Periksa kembali alamat wilayah pada akun Anda.')?>
            </div>
        <?php else: ?>
        <div style="margin: 20px 0; text-align: left;" class="panel">
            <h3 style="margin-top:0;">Metode Pembayaran:</h3>
            <p style="font-size: 16px;"><strong>
                <?php if($method === 'BCA'): ?>
                    Transfer Bank BCA
                <?php elseif($method === 'COD'): ?>
                    COD (Cash on Delivery / Bayar di Tempat)
                <?php else: ?>
                    QRIS
                <?php endif; ?>
            </strong></p>
            
            <?php if($method === 'BCA'): ?>
                <div style="background: #f8f9fc; padding: 16px; border-radius: 6px; border: 1px solid #cbd5e0; margin-top: 10px;">
                    <p style="margin: 0 0 8px 0;">Silakan transfer sejumlah <strong><?=rupiah($o['total'])?></strong> ke rekening berikut:</p>
                    <div style="font-size: 20px; font-weight: bold; color: #171c2e; background: #fff; padding: 12px; border: 1px dashed #a0aec0; text-align: center; border-radius: 6px; letter-spacing: 1px;">
                        <?=e($bca_acc)?>
                    </div>
                    <div class="payment-copy-row">
                        <button class="btn payment-copy-button" id="copy-bca-account" type="button" data-copy-value="<?= e($bca_account_number) ?>">Salin nomor rekening</button>
                        <span class="muted payment-copy-status" id="copy-bca-status" role="status" aria-live="polite"></span>
                    </div>
                </div>
            <?php elseif($method === 'COD'): ?>
                <div style="background: #f8f9fc; padding: 16px; border-radius: 6px; border: 1px solid #cbd5e0; margin-top: 10px;">
                    <p style="margin: 0; color: #4a5568;">Pesanan Anda menggunakan metode <strong>COD (Bayar di Tempat)</strong>. Pembayaran dilakukan secara tunai kepada kurir/penjual saat pesanan sampai.</p>
                </div>
            <?php else: ?>
                <div style="text-align: center; margin-top: 10px;">
                    <p>Scan QR Code berikut menggunakan aplikasi m-Banking atau e-wallet Anda:</p>
                    <?php if($qr && file_exists(app_path($qr))):?>
                        <img class="qr-preview" src="<?= e(app_url($qr)) ?>" alt="QR pembayaran penjual" style="max-width: 260px; height: auto;">
                        <div class="payment-copy-row">
                            <a class="btn payment-copy-button" href="<?= e(app_url($qr)) ?>" download="<?= e(basename($qr)) ?>">Unduh QRIS</a>
                        </div>
                    <?php else:?>
                        <div class="alert">File QRIS belum ditemukan di direktori uploads.</div>
                    <?php endif;?>
                </div>
            <?php endif; ?>
        </div>

        <div style="text-align: left;" class="panel">
            <?php if($method === 'COD'): ?>
                <h3 style="margin-top:0;">Konfirmasi Pesanan COD</h3>
                <div class="alert" style="background: #e6f4ea; border-color: #ceead6; color: #137333;">
                    ✓ Pesanan COD berhasil dibuat (<?=$order_seq_label?> / ID: <strong>#<?=$order_unique_id?></strong>). Silakan klik tombol WhatsApp di bawah untuk mengirim data pesanan ke Admin.
                </div>
            <?php else: ?>
                <h3 style="margin-top:0;">Konfirmasi & Upload Bukti Pembayaran</h3>
                
                <?php if(!empty($o['payment_proof'])): ?>
                    <div class="alert" style="background: #e6f4ea; border-color: #ceead6; color: #137333;">
                        ✓ Bukti pembayaran telah diunggah dan menunggu verifikasi seller. Silakan klik tombol WhatsApp di bawah untuk mengirim konfirmasi beserta Nomor ID <strong>#<?=$order_unique_id?></strong> ke Admin.
                    </div>
                    <div style="text-align: center; margin: 15px 0;">
                        <p class="muted">Bukti yang diunggah:</p>
                        <img src="<?= e(app_url($o['payment_proof'])) ?>" alt="Bukti Pembayaran" style="max-width: 200px; border-radius: 8px; border: 1px solid #ccc;">
                    </div>
                <?php elseif(in_array($o['payment_status'], ['unpaid', 'awaiting_seller'], true)): ?>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf" value="<?=csrf()?>">
                        <label>Upload Foto / Screenshot Struk / Bukti Transfer:</label>
                        <input type="file" name="proof" accept="image/*" required style="margin-bottom: 12px;">
                        <button type="submit" class="btn full">Upload Bukti Pembayaran</button>
                    </form>
                <?php else: ?>
                    <p class="muted">Status pembayaran: <strong><?=e($payment_status_labels[$o['payment_status']] ?? $o['payment_status'])?></strong></p>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Tombol Kirim Pesan ke WhatsApp Admin Menggunakan Nomor ID Unik -->
            <?php 
                $buyer_name = user()['name'] ?? 'Pembeli';
                $wa_msg_text = ($method === 'COD') 
                    ? "Halo Admin, saya ingin memesan produk dengan metode *COD (Bayar di Tempat)*:%0A" .
                      "- Nomor ID Pesanan: *#{$order_unique_id}* ({$order_seq_label})%0A" .
                      "- Nama Pembeli: {$buyer_name}%0A" .
                      "- Total Tagihan: " . rupiah($o['total']) . "%0A" .
                      "Mohon bantu diproses ya Kak. Terima kasih!"
                    : "Halo Admin, saya ingin konfirmasi pembayaran pesanan:%0A" .
                      "- Nomor ID Pesanan: *#{$order_unique_id}* ({$order_seq_label})%0A" .
                      "- Nama Pembeli: {$buyer_name}%0A" .
                      "- Total Tagihan: " . rupiah($o['total']) . "%0A" .
                      "- Metode Pembayaran: {$method}%0A" .
                      "Bukti pembayaran sudah saya unggah di website untuk ID *#{$order_unique_id}*. Mohon bantu diverifikasi ya Kak. Terima kasih!";
                $wa_link = "https://wa.me/{$admin_wa}?text=" . urlencode(str_replace('%0A', "\n", $wa_msg_text));
            ?>
            <div style="margin-top: 20px;">
                <a href="<?=$wa_link?>" target="_blank" class="btn full" style="background: #25d366; text-align: center; display: block; text-decoration: none;">
                    💬 Konfirmasi ID #<?=$order_unique_id?> ke WhatsApp Admin
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <p>Status pembayaran: <span class="pill"><?=e($payment_status_labels[$o['payment_status']] ?? $o['payment_status'])?></span></p>
        <p>Status pesanan: <span class="pill"><?=e($order_status_labels[$o['status']] ?? $o['status'])?></span></p>
        <a class="btn" style="background: #737b8c;" href="my_orders.php">← Lihat Riwayat Pesanan Saya</a>
    </div>
</main>
<script>
    (function () {
        const copyButton = document.getElementById("copy-bca-account");
        if (!copyButton) {
            return;
        }

        const status = document.getElementById("copy-bca-status");

        function copyFallback(value) {
            const input = document.createElement("textarea");
            input.value = value;
            input.setAttribute("readonly", "");
            input.style.position = "fixed";
            input.style.opacity = "0";
            document.body.appendChild(input);
            input.select();
            const copied = document.execCommand("copy");
            input.remove();
            if (!copied) {
                throw new Error("Clipboard tidak tersedia.");
            }
        }

        copyButton.addEventListener("click", async function () {
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(copyButton.dataset.copyValue);
                } else {
                    copyFallback(copyButton.dataset.copyValue);
                }
                status.textContent = "Nomor rekening berhasil disalin.";
            } catch (error) {
                status.textContent = "Gagal menyalin nomor rekening. Silakan salin secara manual.";
            }
        });
    })();
</script>
</body>
</html>