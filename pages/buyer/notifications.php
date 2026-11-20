<?php
require dirname(__DIR__, 2) . "/includes/config.php";
require_role("buyer");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    if (($_POST["action"] ?? "") !== "mark_all_read") {
        http_response_code(400);
        exit("Aksi notifikasi tidak valid.");
    }

    $mark_read = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $mark_read->execute([user()["id"]]);
    flash("Semua notifikasi ditandai sudah dibaca.", "success");
    header("Location: notifications.php");
    exit();
}

$notification_stmt = $pdo->prepare(
    "SELECT id, order_id, type, message, is_read, created_at
     FROM notifications WHERE user_id = ? ORDER BY id DESC"
);
$notification_stmt->execute([user()["id"]]);
$notifications = $notification_stmt->fetchAll();
$unread_count = count(array_filter(
    $notifications,
    static fn(array $notification): bool => !(bool) $notification["is_read"]
));
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Notifikasi - Basreng Store</title>
    <base href="<?= e(app_url('pages/buyer/')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>?v=<?= (int) filemtime(app_path('assets/css/style.css')) ?>">
    <style>
        .notifications-container {
            max-width: 900px;
            margin: 0 auto;
        }
        .notification-card {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 12px;
            padding: 18px 20px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #fff;
        }
        .notification-card.unread {
            border-color: #93c5fd;
            background: #eff6ff;
        }
        .notification-message {
            margin: 0;
            color: #1e293b;
            font-weight: 600;
        }
        .notification-date {
            display: block;
            margin-top: 6px;
            color: #64748b;
            font-size: 12px;
        }
        .notification-link {
            flex: 0 0 auto;
            color: #2563eb;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
        }
        @media (max-width: 560px) {
            .notification-card {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . "/includes/partials/buyer_nav.php"; ?>
<main>
    <div class="notifications-container">
        <div style="display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 20px;">
            <div>
                <h1 style="margin: 0 0 6px; font-size: 24px; color: #0f172a;"><?= e(t("Notifikasi")) ?></h1>
                <p class="muted" style="margin: 0;"><?= e(app_language() === "en" ? "Updates on your order payment and shipping." : "Pembaruan pembayaran dan pengiriman pesanan Anda.") ?></p>
            </div>
            <?php if ($unread_count > 0): ?>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= csrf() ?>">
                    <input type="hidden" name="action" value="mark_all_read">
                    <button class="btn" type="submit" style="margin: 0; white-space: nowrap;"><?= e(app_language() === "en" ? "Mark as Read" : "Tandai Dibaca") ?></button>
                </form>
            <?php endif; ?>
        </div>

        <?php show_flash(); ?>

        <?php foreach ($notifications as $notification): ?>
            <?php
            $target = $notification["order_id"] !== null
                ? "payment.php?id=" . (int) $notification["order_id"]
                : "my_orders.php";
            ?>
            <article class="notification-card <?= empty($notification["is_read"]) ? "unread" : "" ?>">
                <div>
                    <p class="notification-message"><?= e($notification["message"]) ?></p>
                    <time class="notification-date" datetime="<?= e($notification["created_at"]) ?>">
                        <?= e($notification["created_at"]) ?>
                    </time>
                </div>
                <a class="notification-link" href="<?= e($target) ?>"><?= e(app_language() === "en" ? "View order" : "Lihat pesanan") ?></a>
            </article>
        <?php endforeach; ?>

        <?php if (empty($notifications)): ?>
            <div class="panel" style="padding: 36px; text-align: center;">
                <p class="muted" style="margin: 0;">Belum ada notifikasi.</p>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
