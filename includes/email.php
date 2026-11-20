<?php
require_once dirname(__DIR__) . "/vendor/autoload.php";

function create_configured_mailer(): \PHPMailer\PHPMailer\PHPMailer
{
    $smtp_username = "";
    $smtp_password = "";
    $from_email = "";
    $from_name = "";

    if (
        !is_string($smtp_username) || trim($smtp_username) === "" ||
        !is_string($smtp_password) || trim($smtp_password) === "" ||
        !is_string($from_email) || !filter_var($from_email, FILTER_VALIDATE_EMAIL)
    ) {
        throw new RuntimeException("Konfigurasi SMTP email belum lengkap atau tidak valid.");
    }

    $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->isSMTP();
    $mailer->SMTPAuth = true;
    $mailer->Host = "smtp-relay.brevo.com";
    $mailer->Username = trim($smtp_username);
    $mailer->Password = trim($smtp_password);
    $mailer->Port = 587;
    $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
    $mailer->setFrom($from_email, $from_name);

    return $mailer;
}

function send_branded_email(
    string $recipient_email,
    string $recipient_name,
    string $subject,
    string $title,
    string $message,
    ?string $button_label = null,
    ?string $button_url = null,
    string $note = ""
): bool {
    try {
        $mailer = create_configured_mailer();
        $mailer->addAddress($recipient_email);
        $mailer->Subject = $subject;
        $safe_name = htmlspecialchars($recipient_name, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
        $safe_title = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
        $safe_message = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"));
        $safe_note = nl2br(htmlspecialchars($note, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"));
        $logo_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . "assets" .
            DIRECTORY_SEPARATOR . "images" . DIRECTORY_SEPARATOR . "basrengqyuu-email-logo.png";
        $logo_data = is_file($logo_path) ? file_get_contents($logo_path) : false;
        if ($logo_data === false) {
            error_log("Logo email tidak dapat dibaca.");
            $brand_html = '<span style="font-family:Arial,sans-serif;font-size:28px;font-weight:800;color:#f59e0b;">Basreng<span style="color:#ef4444;">Qyuu</span> <span style="color:#64748b;font-size:18px;">Store</span></span>';
        } else {
            $brand_html = '<img src="cid:basrengqyuu-email-logo" width="200" alt="BasrengQyuu Store" style="display:block;width:100%;max-width:200px;height:auto;border:0;">';
        }

        $button_html = "";
        $button_plain = "";
        if ($button_label !== null && $button_url !== null) {
            $safe_button_label = htmlspecialchars($button_label, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
            $safe_button_url = htmlspecialchars($button_url, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
            $button_html = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:0 auto;">' .
                '<tr><td align="center" bgcolor="#f59e0b" style="border-radius:8px;">' .
                '<a href="' . $safe_button_url . '" style="display:inline-block;padding:14px 28px;border:1px solid #f59e0b;border-radius:8px;color:#111827;font-size:16px;font-weight:bold;text-decoration:none;">' .
                $safe_button_label . '</a></td></tr></table>';
            $button_plain = "\n\n" . $button_label . ": " . $button_url;
        }

        $mailer->isHTML(true);
        $mailer->Body = '<!doctype html><html lang="id"><head><meta charset="utf-8"></head>' .
            '<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif;color:#1f2937;">' .
            '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3f4f6;">' .
            '<tr><td align="center" style="padding:32px 16px;">' .
            '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;">' .
            '<tr><td align="center" style="padding:32px 32px 20px;">' . $brand_html . '</td></tr>' .
            '<tr><td style="padding:0 40px 32px;">' .
            '<h1 style="margin:0 0 32px;text-align:center;font-size:24px;line-height:1.3;color:#111827;">' . $safe_title . '</h1>' .
            '<p style="margin:0 0 16px;font-size:16px;line-height:1.6;">Halo, ' . $safe_name . '!</p>' .
            '<p style="margin:0 0 24px;font-size:16px;line-height:1.6;">' . $safe_message . '</p>' .
            $button_html .
            ($safe_note !== "" ? '<p style="margin:24px 0;font-size:14px;line-height:1.6;color:#4b5563;">' . $safe_note . '</p>' : '') .
            '<hr style="border:0;border-top:1px solid #e5e7eb;margin:0 0 20px;">' .
            '</td></tr></table>' .
            '<p style="margin:20px 0 0;font-size:12px;line-height:1.5;color:#6b7280;">Email otomatis dari BasrengQyuu Store. Mohon tidak membalas email ini.</p>' .
            '</td></tr></table></body></html>';
        $mailer->AltBody = "Halo, " . $recipient_name . "!\n\n" .
            $title . "\n\n" . $message . $button_plain .
            ($note !== "" ? "\n\n" . $note : "") .
            "\n\nEmail otomatis dari BasrengQyuu Store. Mohon tidak membalas email ini.";
        if ($logo_data !== false) {
            $mailer->addStringEmbeddedImage(
                $logo_data,
                "basrengqyuu-email-logo",
                "basrengqyuu-email-logo.png",
                \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64,
                "image/png"
            );
        }
        $mailer->send();
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log("Gagal mengirim email: " . $e->getMessage());
        return false;
    } catch (RuntimeException $e) {
        error_log("Email tidak dikirim: " . $e->getMessage());
        return false;
    }
}

function send_account_activation_email(
    string $recipient_email,
    string $recipient_name,
    string $activation_url
): bool {
    return send_branded_email(
        $recipient_email,
        $recipient_name,
        "Aktivasi akun BasrengQyuu Store",
        "Aktifkan akun Anda",
        "Klik tombol di bawah untuk memverifikasi email dan mengaktifkan akun BasrengQyuu Store Anda.",
        "Aktifkan akun",
        $activation_url,
        "Tautan ini berlaku selama 24 jam. Jika Anda tidak membuat akun ini, abaikan email ini."
    );
}

function send_order_notification_email(
    string $recipient_email,
    string $recipient_name,
    string $message,
    string $type
): bool
{
    $titles = [
        "payment_paid" => "Pembayaran Berhasil",
        "payment_refunded" => "Pengembalian Dana",
        "order_shipping" => "Pesanan Sedang Dikirim",
        "order_completed" => "Pesanan Selesai Dikirim",
        "order_returned" => "Pesanan Dikembalikan",
    ];
    if (!isset($titles[$type])) {
        error_log("Email notifikasi pesanan tidak dikirim: jenis pembaruan tidak valid.");
        return false;
    }

    $notification_url = app_email_absolute_url("pages/buyer/notifications.php");
    return send_branded_email(
        $recipient_email,
        $recipient_name,
        $titles[$type] . " - BasrengQyuu Store",
        "Pembaruan Pesanan",
        $message,
        $notification_url !== null ? "Lihat notifikasi pesanan" : null,
        $notification_url,
        "Silakan lihat detail dan status terbaru pesanan Anda di BasrengQyuu Store."
    );
}

function app_email_absolute_url(string $path): ?string
{
    $server_name = $_SERVER["SERVER_NAME"] ?? "";
    $is_ipv6 = is_string($server_name) &&
        filter_var($server_name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    $is_valid_host = is_string($server_name) &&
        ($is_ipv6 || preg_match('/^(?=.{1,253}$)[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?$/', $server_name) === 1);
    if (!$is_valid_host) {
        error_log("Tautan email notifikasi tidak dibuat: nama server tidak valid.");
        return null;
    }

    $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
    $port = (int) ($_SERVER["SERVER_PORT"] ?? ($scheme === "https" ? 443 : 80));
    $port_suffix = in_array($port, [80, 443], true) ? "" : ":" . $port;
    $host = $is_ipv6 ? "[" . $server_name . "]" : $server_name;

    return $scheme . "://" . $host . $port_suffix . app_url($path);
}
