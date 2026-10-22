<?php
declare(strict_types=1);
/* Sending emails through the SMTP account set on the Email settings page */

require_once APP_ROOT . '/lib/PHPMailer/Exception.php';
require_once APP_ROOT . '/lib/PHPMailer/PHPMailer.php';
require_once APP_ROOT . '/lib/PHPMailer/SMTP.php';

function mail_configured(): bool {
    return !is_demo() && setting('smtp_host') !== '' && setting('mail_from') !== '';
}

/**
 * Send one email. Returns [true, ''] on success or [false, reason].
 * In the demo, or when email is not set up, nothing is sent.
 */
function send_mail(string $to, string $toName, string $subject, string $html, string $replyTo = ''): array {
    if (is_demo()) return [false, 'Demo: emails are never sent'];
    if (!mail_configured()) return [false, 'Email is not set up (Setup > Email settings)'];
    $m = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $m->isSMTP();
        $m->Host = setting('smtp_host');
        $m->Port = (int)setting('smtp_port', '465');
        $sec = setting('smtp_secure', 'ssl');
        $m->SMTPSecure = $sec === 'none' ? '' : $sec;
        $m->SMTPAutoTLS = $sec !== 'none';
        if (setting('smtp_user') !== '') {
            $m->SMTPAuth = true;
            $m->Username = setting('smtp_user');
            $m->Password = setting('smtp_pass');
        }
        $m->CharSet = 'UTF-8';
        $m->Timeout = 15;
        $m->setFrom(setting('mail_from'), setting('mail_from_name', setting('org_name')));
        $reply = $replyTo ?: setting('mail_reply_to');
        if ($reply !== '') $m->addReplyTo($reply);
        $m->addAddress($to, $toName);
        $m->isHTML(true);
        $m->Subject = $subject;
        $m->Body = $html;
        $m->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</tr>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $m->send();
        return [true, ''];
    } catch (Throwable $e) {
        return [false, $m->ErrorInfo ?: $e->getMessage()];
    }
}

/** Simple branded email layout */
function mail_wrap(string $inner): string {
    return '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1d2733;max-width:640px">'
         . '<div style="background:#1f4e79;color:#fff;padding:12px 16px;font-weight:bold">' . e(setting('org_name')) . '</div>'
         . '<div style="padding:16px;border:1px solid #dde3ea;border-top:0">' . $inner . '</div></div>';
}
