<?php
declare(strict_types=1);
/* Asset register: staff, locations, handovers and history */
require_once __DIR__ . '/stock.php';

const HANDOVER_TYPES = ['handover' => 'Handover to staff', 'move' => 'Move to another location', 'return' => 'Return to store'];

function employees(bool $activeOnly = true): array {
    return q('SELECT * FROM employees' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY name')->fetchAll();
}
function locations(bool $activeOnly = true): array {
    return q('SELECT * FROM locations' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY name')->fetchAll();
}
/** Location of a warehouse (same name), created if missing */
function warehouse_location_id(int $whId): ?int {
    $w = q('SELECT name, address FROM warehouses WHERE id = ?', [$whId])->fetch();
    if (!$w) return null;
    q('INSERT IGNORE INTO locations (name, address) VALUES (?, ?)', [$w['name'], $w['address']]);
    return (int)q('SELECT id FROM locations WHERE name = ?', [$w['name']])->fetchColumn();
}

function asset_event(int $assetId, string $event, string $details = '', string $refType = '', ?int $refId = null): void {
    $u = current_user();
    q('INSERT INTO asset_events (asset_id, event, details, ref_type, ref_id, user_id) VALUES (?, ?, ?, ?, ?, ?)', [$assetId, $event, mb_substr($details, 0, 500), $refType, $refId, $u['id'] ?? null]);
}

function asset_get(int $id): ?array {
    $a = q('SELECT a.*, c.code AS cat_code, c.name AS cat_name, l.name AS loc_name, e.name AS emp_name, e.position AS emp_position, s.name AS supplier_name, po.number AS po_number
            FROM assets a JOIN categories c ON c.id = a.category_id LEFT JOIN locations l ON l.id = a.location_id LEFT JOIN employees e ON e.id = a.employee_id
            LEFT JOIN suppliers s ON s.id = a.supplier_id LEFT JOIN po ON po.id = a.po_id WHERE a.id = ?', [$id])->fetch();
    return $a ?: null;
}

/** Text shown in the register: "Location · Holder" */
function asset_where(?string $loc, ?string $emp): string {
    return trim(($loc ?: '') . ($emp ? ' · ' . $emp : ''), ' ·');
}

function handover_link(string $token): string { return base_url() . '/handover_sign.php?t=' . $token; }

function handover_get(int $id): ?array {
    $h = q('SELECT h.*, e.name AS emp_name, e.email AS emp_email, e.position AS emp_position, l.name AS loc_name, u.name AS by_name
            FROM handovers h LEFT JOIN employees e ON e.id = h.employee_id LEFT JOIN locations l ON l.id = h.location_id JOIN users u ON u.id = h.created_by WHERE h.id = ?', [$id])->fetch();
    return $h ?: null;
}
function handover_lines(int $id): array {
    return q('SELECT hl.*, a.code, a.description, a.brand_model, a.serial_no, a.purchase_value, a.currency FROM handover_lines hl JOIN assets a ON a.id = hl.asset_id WHERE hl.handover_id = ? ORDER BY a.code', [$id])->fetchAll();
}

/** Email the signature link to the staff member; returns a status text */
function handover_send(array $h): string {
    require_once __DIR__ . '/mail.php';
    if (!$h['emp_email']) return 'Not emailed: no email address for this person';
    $html = mail_wrap('<p>Dear ' . e($h['emp_name']) . ',</p><p>The following equipment has been handed over to you (' . e($h['number']) . ').</p>'
        . '<p><a href="' . e(handover_link($h['token'])) . '" style="display:inline-block;background:#1f4e79;color:#fff;padding:10px 18px;text-decoration:none;border-radius:4px">Review and sign to confirm receipt</a></p>'
        . '<p>By signing you confirm you received these items in the stated condition and will take reasonable care of them.</p>');
    [$ok, $err] = send_mail($h['emp_email'], $h['emp_name'], 'Equipment handover ' . $h['number'] . ': please confirm', $html);
    $st = $ok ? 'Email sent ' . date('d M H:i') : 'Not emailed: ' . $err;
    q('UPDATE handovers SET email_status = ? WHERE id = ?', [mb_substr($st, 0, 250), $h['id']]);
    return $st;
}
