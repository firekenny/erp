<?php
declare(strict_types=1);
/* Email notifications for workflow steps. Silent if email is not set up (and never in the demo). */
require_once __DIR__ . '/mail.php';

/** Email a list of users (skips the person acting, inactive users and those who switched emails off). Returns how many were sent. */
function notify_users(array $userIds, string $subject, string $message, string $path): int {
    if (!mail_configured()) return 0;
    $me = current_user();
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($i) => $i && $i !== (int)($me['id'] ?? 0))));
    if (!$ids) return 0;
    $users = q('SELECT id, name, email FROM users WHERE active = 1 AND notify_email = 1 AND password_hash IS NOT NULL AND id IN (' . implode(',', $ids) . ')')->fetchAll();
    $link = base_url() . '/' . ltrim($path, '/');
    $sent = 0;
    foreach ($users as $u) {
        $html = mail_wrap('<p>Hello ' . e($u['name']) . ',</p><p>' . $message . '</p>'
            . '<p><a href="' . e($link) . '" style="display:inline-block;background:#1f4e79;color:#fff;padding:10px 18px;text-decoration:none;border-radius:4px">Open in the ERP</a></p>'
            . '<p style="color:#6b7785;font-size:12px">You receive this because of your role in ' . e(setting('org_name')) . ' ERP. You can switch these emails off under My account.</p>');
        [$ok] = send_mail($u['email'], $u['name'], $subject, $html);
        if ($ok) $sent++;
    }
    return $sent;
}

function users_with_role(string $role): array {
    return q("SELECT id FROM users WHERE active = 1 AND FIND_IN_SET(?, roles)", [$role])->fetchAll(PDO::FETCH_COLUMN);
}

/** People who must act now on a PR (current step); admins when the approver is the requester */
function notify_pr_approvers(int $prId): void {
    $pr = pr_get($prId);
    if (!$pr || $pr['status'] !== 'submitted') return;
    $ids = [];
    foreach (q("SELECT a.approver_id FROM pr_approvals a WHERE a.pr_id = ? AND a.status = 'pending' AND " . PR_CURRENT_STAGE, [$prId])->fetchAll(PDO::FETCH_COLUMN) as $aid) {
        if ((int)$aid === (int)$pr['requester_id']) $ids = array_merge($ids, array_diff(users_with_role('admin'), [$pr['requester_id']]));
        else $ids[] = $aid;
    }
    notify_users($ids, 'To approve: ' . $pr['number'] . ' · ' . $pr['title'],
        'Purchase Request <b>' . e($pr['number']) . '</b> from ' . e($pr['requester_name']) . ' (' . e($pr['title']) . ') is waiting for your approval.', 'pr_view.php?id=' . $prId);
}

/** People who must act now on an award */
function notify_award_approvers(int $rfqId): void {
    $r = rfq_get($rfqId);
    if (!$r || $r['award_status'] !== 'submitted') return;
    $ids = [];
    foreach (q("SELECT a.approver_id FROM award_approvals a WHERE a.rfq_id = ? AND a.status = 'pending' AND " . AWARD_CURRENT_STAGE, [$rfqId])->fetchAll(PDO::FETCH_COLUMN) as $aid) {
        if ((int)$aid === (int)$r['award_submitted_by']) $ids = array_merge($ids, array_diff(users_with_role('admin'), [$r['award_submitted_by']]));
        else $ids[] = $aid;
    }
    notify_users($ids, 'Award to approve: ' . $r['number'] . ' · ' . $r['title'],
        'The supplier award for <b>' . e($r['number']) . '</b> (' . e($r['title']) . ', ' . number_format((float)$r['award_total'], 2) . ' ' . e($r['currency']) . ') is waiting for your approval.', 'rfq_eval.php?id=' . $rfqId);
}
