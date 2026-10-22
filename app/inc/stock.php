<?php
declare(strict_types=1);
/* Warehouses, goods receipts, stock ledger and asset creation */

const ASSET_STATUS = ['in_store' => ['In store', ''], 'in_use' => ['In use', 'stock'], 'in_repair' => ['In repair', 'warn'], 'disposed' => ['Disposed', 'off'], 'lost' => ['Lost', 'off']];
const ASSET_COND = ['new' => 'New', 'good' => 'Good', 'fair' => 'Fair', 'poor' => 'Poor', 'broken' => 'Broken'];

function warehouses(bool $activeOnly = true): array {
    return q('SELECT * FROM warehouses' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY name')->fetchAll();
}

function grn_next_number(): string {
    $prefix = 'GRN-' . date('Y') . '-';
    $max = (int)q('SELECT MAX(CAST(SUBSTRING(number, ?) AS UNSIGNED)) FROM grn WHERE number LIKE ?', [strlen($prefix) + 1, $prefix . '%'])->fetchColumn();
    return $prefix . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

/** Next asset code for a category, e.g. AS-IT-0001 (prefix set in Organisation settings) */
function next_asset_code(string $catCode): string {
    $pfx = setting('asset_prefix', 'AS');
    $base = ($pfx !== '' ? $pfx . '-' : '') . $catCode . '-';
    $max = (int)q('SELECT MAX(CAST(SUBSTRING(code, ?) AS UNSIGNED)) FROM assets WHERE code LIKE ?', [strlen($base) + 1, $base . '%'])->fetchColumn();
    return $base . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

/** Quantity accepted so far for each PO line: [po_line_id => qty] */
function po_accepted(int $poId): array {
    return array_map('floatval', array_column(q('SELECT gl.po_line_id, SUM(gl.qty_received - gl.qty_rejected) AS acc FROM grn_lines gl JOIN grn g ON g.id = gl.grn_id
        WHERE g.po_id = ? GROUP BY gl.po_line_id', [$poId])->fetchAll(), 'acc', 'po_line_id'));
}

/** Set the PO to partially received / received from what has been accepted */
function po_refresh_receipt_status(int $poId): void {
    $acc = po_accepted($poId);
    $all = true; $any = false;
    foreach (po_lines($poId) as $l) {
        $a = $acc[$l['id']] ?? 0.0;
        if ($a > 0) $any = true;
        if ($a + 0.0001 < (float)$l['qty']) $all = false;
    }
    if ($any) q("UPDATE po SET status = ? WHERE id = ? AND status <> 'cancelled'", [$all ? 'received' : 'partially_received', $poId]);
}

/** Add stock with weighted average cost and write the ledger line */
function stock_in(int $itemId, int $whId, float $qty, float $unitCost, array $ref, array $fin = []): void {
    $row = q('SELECT qty, avg_cost FROM stock WHERE item_id = ? AND warehouse_id = ? FOR UPDATE', [$itemId, $whId])->fetch();
    $oldQ = $row ? (float)$row['qty'] : 0.0; $oldC = $row ? (float)$row['avg_cost'] : 0.0;
    $newQ = $oldQ + $qty;
    $newC = $newQ > 0 ? (($oldQ > 0 ? $oldQ * $oldC : 0) + $qty * $unitCost) / $newQ : $unitCost;
    q('INSERT INTO stock (item_id, warehouse_id, qty, avg_cost) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE qty = VALUES(qty), avg_cost = VALUES(avg_cost)', [$itemId, $whId, $newQ, $newC]);
    $u = current_user();
    q('INSERT INTO stock_moves (item_id, warehouse_id, move_type, qty, unit_cost, balance_after, ref_type, ref_id, ref_number, bin, batch_no, expiry_date, fin1_id, fin2_id, fin3_id, fin4_id, user_id)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [$itemId, $whId, $ref['type'] ?? 'receipt', $qty, $unitCost, $newQ, $ref['ref_type'] ?? '', $ref['ref_id'] ?? null, $ref['ref_number'] ?? '',
       $ref['bin'] ?? '', $ref['batch_no'] ?? '', $ref['expiry_date'] ?? null, $fin[1] ?? null, $fin[2] ?? null, $fin[3] ?? null, $fin[4] ?? null, $u['id'] ?? null]);
}
