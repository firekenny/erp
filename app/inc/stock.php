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

/** Generic document number: PREFIX-YYYY-0001 */
function next_doc_number(string $table, string $prefix): string {
    $p = $prefix . '-' . date('Y') . '-';
    $max = (int)q("SELECT MAX(CAST(SUBSTRING(number, ?) AS UNSIGNED)) FROM `$table` WHERE number LIKE ?", [strlen($p) + 1, $p . '%'])->fetchColumn();
    return $p . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

function stock_qty(int $itemId, int $whId): float {
    return (float)(q('SELECT qty FROM stock WHERE item_id = ? AND warehouse_id = ?', [$itemId, $whId])->fetchColumn() ?: 0);
}

/**
 * Add stock: creates a lot (keeps donor coding, batch, expiry), updates the weighted average cost and writes the ledger.
 * $ref: type, ref_type, ref_id, ref_number, bin, batch_no, expiry_date, date, parent_lot_id, source_type
 */
function stock_in(int $itemId, int $whId, float $qty, float $unitCost, array $ref, array $fin = []): int {
    $row = q('SELECT qty, avg_cost FROM stock WHERE item_id = ? AND warehouse_id = ? FOR UPDATE', [$itemId, $whId])->fetch();
    $oldQ = $row ? (float)$row['qty'] : 0.0; $oldC = $row ? (float)$row['avg_cost'] : 0.0;
    $newQ = $oldQ + $qty;
    $newC = $newQ > 0 ? (($oldQ > 0 ? $oldQ * $oldC : 0) + $qty * $unitCost) / $newQ : $unitCost;
    q('INSERT INTO stock (item_id, warehouse_id, qty, avg_cost) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE qty = VALUES(qty), avg_cost = VALUES(avg_cost)', [$itemId, $whId, $newQ, $newC]);
    q('INSERT INTO stock_lots (item_id, warehouse_id, source_type, source_ref, parent_lot_id, received_date, qty_in, qty_left, unit_cost, fin1_id, fin2_id, fin3_id, fin4_id, batch_no, expiry_date, bin)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [$itemId, $whId, $ref['source_type'] ?? 'grn', $ref['ref_number'] ?? '', $ref['parent_lot_id'] ?? null, $ref['date'] ?? date('Y-m-d'), $qty, $qty, $unitCost,
       $fin[1] ?? null, $fin[2] ?? null, $fin[3] ?? null, $fin[4] ?? null, $ref['batch_no'] ?? '', $ref['expiry_date'] ?? null, $ref['bin'] ?? '']);
    $lotId = (int)db()->lastInsertId();
    $u = current_user();
    q('INSERT INTO stock_moves (item_id, warehouse_id, move_type, qty, unit_cost, balance_after, ref_type, ref_id, ref_number, bin, batch_no, expiry_date, fin1_id, fin2_id, fin3_id, fin4_id, user_id, lot_id, issued_to, destination)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [$itemId, $whId, $ref['type'] ?? 'receipt', $qty, $unitCost, $newQ, $ref['ref_type'] ?? '', $ref['ref_id'] ?? null, $ref['ref_number'] ?? '',
       $ref['bin'] ?? '', $ref['batch_no'] ?? '', $ref['expiry_date'] ?? null, $fin[1] ?? null, $fin[2] ?? null, $fin[3] ?? null, $fin[4] ?? null, $u['id'] ?? null, $lotId,
       $ref['issued_to'] ?? '', $ref['destination'] ?? '']);
    return $lotId;
}

/**
 * Take stock out, from lots: earliest expiry first, then oldest. Average cost is unchanged.
 * Returns [[lot row, qty taken], ...]. Throws if not enough stock.
 */
function stock_out(int $itemId, int $whId, float $qty, array $ref): array {
    $row = q('SELECT qty, avg_cost FROM stock WHERE item_id = ? AND warehouse_id = ? FOR UPDATE', [$itemId, $whId])->fetch();
    $have = $row ? (float)$row['qty'] : 0.0;
    if ($qty > $have + 0.0001) throw new RuntimeException('Not enough stock');
    $lots = q('SELECT * FROM stock_lots WHERE item_id = ? AND warehouse_id = ? AND qty_left > 0 ORDER BY expiry_date IS NULL, expiry_date, received_date, id FOR UPDATE', [$itemId, $whId])->fetchAll();
    $left = $qty; $taken = []; $bal = $have;
    $u = current_user();
    foreach ($lots as $lot) {
        if ($left <= 0.0001) break;
        $t = min($left, (float)$lot['qty_left']);
        q('UPDATE stock_lots SET qty_left = qty_left - ? WHERE id = ?', [$t, $lot['id']]);
        $bal -= $t; $left -= $t;
        q('INSERT INTO stock_moves (item_id, warehouse_id, move_type, qty, unit_cost, balance_after, ref_type, ref_id, ref_number, bin, batch_no, expiry_date, fin1_id, fin2_id, fin3_id, fin4_id, user_id, lot_id, issued_to, destination)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
          [$itemId, $whId, $ref['type'] ?? 'issue', -$t, (float)$row['avg_cost'], $bal, $ref['ref_type'] ?? '', $ref['ref_id'] ?? null, $ref['ref_number'] ?? '',
           $lot['bin'], $lot['batch_no'], $lot['expiry_date'], $lot['fin1_id'], $lot['fin2_id'], $lot['fin3_id'], $lot['fin4_id'], $u['id'] ?? null, $lot['id'],
           $ref['issued_to'] ?? '', $ref['destination'] ?? '']);
        $taken[] = [$lot, $t];
    }
    if ($left > 0.0001) throw new RuntimeException('Lots do not match stock quantity');
    q('UPDATE stock SET qty = qty - ? WHERE item_id = ? AND warehouse_id = ?', [$qty, $itemId, $whId]);
    return $taken;
}

/** Donor coding label for a lot/move row */
function fin_label(array $row, array $codes): string {
    return implode(' / ', array_filter([$codes[(int)($row['fin1_id'] ?? 0)] ?? '', $codes[(int)($row['fin2_id'] ?? 0)] ?? '', $codes[(int)($row['fin3_id'] ?? 0)] ?? '', $codes[(int)($row['fin4_id'] ?? 0)] ?? '']));
}

const SR_STATUS = ['submitted' => ['Waiting', ''], 'partially_issued' => ['Partially issued', 'warn'], 'issued' => ['Issued', 'stock'], 'cancelled' => ['Cancelled', 'off'], 'rejected' => ['Rejected', 'off']];
const COUNT_STATUS = ['open' => ['Counting', ''], 'submitted' => ['Waiting for approval', 'warn'], 'approved' => ['Approved, stock adjusted', 'stock'], 'rejected' => ['Rejected', 'off']];
