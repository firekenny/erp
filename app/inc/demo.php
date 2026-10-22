<?php
declare(strict_types=1);
/*
 * Public demo mode (config.php: define('DEMO_MODE', true)).
 * - One-click sign-in for 4 demo accounts
 * - Fresh sample data every night (first visit after midnight resets everything)
 */

const DEMO_DOMAIN = 'demo.local';
const DEMO_ACCOUNTS = [
    'admin'         => ['Alex Admin',        'admin',                    'Sets up finance codes, categories, items and users'],
    'requester'     => ['Rita Requester',    'requester',                'Raises Purchase Requests'],
    'budget_holder' => ['Ben Budget Holder', 'requester,budget_holder',  'Approves Purchase Requests for his cost centres'],
    'procurement'   => ['Paula Procurement', 'procurement',              'Runs RFQs, awards and Purchase Orders'],
    'storekeeper'   => ['Sam Storekeeper',   'storekeeper',              'Receives deliveries into stock and the asset register'],
    'finance'       => ['Fiona Finance',     'finance',                  'Records supplier invoices, checks the 3-way match and records payments'],
];

function is_demo(): bool { return defined('DEMO_MODE') && DEMO_MODE; }

function demo_email(string $role): string { return 'demo-' . str_replace('_', '-', $role) . '@' . DEMO_DOMAIN; }

function is_demo_account(array $u): bool { return str_ends_with((string)$u['email'], '@' . DEMO_DOMAIN); }

/** Reset once per day, on the first visit after midnight */
function demo_reset_if_due(): void {
    $today = date('Y-m-d');
    // also reload right away when a new version changed the database, so new sample data shows up
    $due = setting('demo_reset_on') !== $today || setting('demo_seed_version') !== (string)SCHEMA_VERSION;
    if (!$due) return;
    $pdo = db();
    if (!(int)$pdo->query("SELECT GET_LOCK('erp_demo_reset', 10)")->fetchColumn()) return;
    try {
        if (setting('demo_reset_on') !== $today || setting('demo_seed_version') !== (string)SCHEMA_VERSION) {
            demo_seed($pdo);
            set_setting('demo_reset_on', $today);
            set_setting('demo_seed_version', (string)SCHEMA_VERSION);
            session_regenerate_id(true);
            unset($_SESSION['uid']);
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('erp_demo_reset')");
    }
}

/** Wipe every table and load the sample organisation */
function demo_seed(PDO $pdo): void {
    $ver = (string)$pdo->query("SELECT v FROM settings WHERE k = 'db_version'")->fetchColumn();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if ($t !== 'settings') $pdo->exec("TRUNCATE TABLE `$t`");
    }
    $pdo->exec("DELETE FROM settings WHERE k NOT IN ('db_version')");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $ins = fn(string $sql, array $p) => $pdo->prepare($sql)->execute($p) ? (int)$pdo->lastInsertId() : 0;

    foreach (['org_name' => 'Horizon Relief Kenya (demo)', 'currency' => 'USD', 'pr_prefix' => 'PR', 'db_version' => $ver] as $k => $v) {
        $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
    }

    // Demo accounts (no password: one-click sign-in only)
    $uid = [];
    foreach (DEMO_ACCOUNTS as $role => [$name, $roles]) {
        $uid[$role] = $ins('INSERT INTO users (name, email, roles) VALUES (?, ?, ?)', [$name, demo_email($role), $roles]);
    }

    // Finance coding: Donor -> Cost centre (approval level) -> Budget line (shared list)
    $lv1 = $ins('INSERT INTO fin_levels (position, name, required, linked) VALUES (1, ?, 1, 1)', ['Donor']);
    $lv2 = $ins('INSERT INTO fin_levels (position, name, required, linked) VALUES (2, ?, 1, 1)', ['Cost centre']);
    $lv3 = $ins('INSERT INTO fin_levels (position, name, required, linked) VALUES (3, ?, 1, 0)', ['Budget line']);
    $pdo->prepare("INSERT INTO settings (k, v) VALUES ('approval_level_id', ?)")->execute([(string)$lv2]);

    $donors = [
        'ECHO' => ['EU Humanitarian Aid', [['ECH-TUR', 'Turkana drought response'], ['ECH-MAR', 'Marsabit WASH']]],
        'FCDO' => ['UK Foreign, Commonwealth & Development Office', [['FCD-NBI', 'Nairobi urban livelihoods'], ['FCD-GAR', 'Garissa health support']]],
        'PRIV' => ['Private foundations', [['PRV-EDU', 'Girls education programme']]],
        'UNR'  => ['Unrestricted funds', [['UNR-SUP', 'Country office support costs']]],
    ];
    foreach ($donors as $code => [$name, $ccs]) {
        $d = $ins('INSERT INTO fin_values (level_id, code, name) VALUES (?, ?, ?)', [$lv1, $code, $name]);
        foreach ($ccs as $i => [$cc, $ccName]) {
            $appr = ($i === 0 || $code === 'UNR') ? $uid['budget_holder'] : $uid['admin'];
            $ins('INSERT INTO fin_values (level_id, parent_id, code, name, approver_user_id) VALUES (?, ?, ?, ?, ?)', [$lv2, $d, $cc, $ccName, $appr]);
        }
    }
    foreach ([
        ['5010', 'Programme supplies'], ['5020', 'Medical supplies'], ['5030', 'WASH materials'],
        ['6010', 'Vehicle fuel'], ['6020', 'Vehicle maintenance'], ['6030', 'Vehicle purchase'],
        ['7010', 'Office supplies'], ['7020', 'IT and communications equipment'], ['7030', 'Office furniture and equipment'],
        ['7040', 'Rent and utilities'], ['8010', 'Staff training'], ['8020', 'Consultancy services'],
    ] as [$c, $n]) $ins('INSERT INTO fin_values (level_id, code, name) VALUES (?, ?, ?)', [$lv3, $c, $n]);

    // Categories and items
    $cats = [
        ['IT', 'IT equipment (laptops, desktops, printers)', 'asset', [['Laptop 14" i5, 16GB RAM, 512GB SSD', 'pcs'], ['Desktop computer with 24" monitor', 'set'], ['Laser printer A4 multifunction', 'pcs']]],
        ['TEL', 'Telecommunications (radios, satellite phones)', 'asset', [['VHF handheld radio', 'pcs'], ['Satellite phone', 'pcs'], ['VHF vehicle radio kit', 'set']]],
        ['VEH', 'Vehicles', 'asset', [['4x4 station wagon, diesel', 'pcs'], ['Motorbike 125cc', 'pcs']]],
        ['GEN', 'Generators and power equipment', 'asset', [['Diesel generator 10 kVA', 'pcs'], ['Solar power kit 3 kW', 'set']]],
        ['FURN', 'Office furniture', 'asset', [['Office desk 140 cm', 'pcs'], ['Lockable filing cabinet, 4 drawers', 'pcs']]],
        ['APPL', 'Appliances (fridges, heaters, AC units)', 'asset', [['Vaccine refrigerator, solar', 'pcs'], ['Split air conditioner 12,000 BTU', 'pcs']]],
        ['MED', 'Medical equipment', 'asset', [['Digital weighing scale, adult', 'pcs'], ['Blood pressure monitor, digital', 'pcs']]],
        ['TOOL', 'Power tools and workshop equipment', 'asset', [['Cordless drill 18V', 'pcs'], ['Water pump, petrol, 3"', 'pcs']]],
        ['CAM', 'Cameras and drones', 'asset', [['Digital camera with GPS', 'pcs']]],
        ['STAT', 'Stationery and office supplies', 'stock', [['A4 paper 80g, ream of 500', 'ream'], ['Ballpoint pens, blue, box of 50', 'box'], ['Lever arch files A4', 'pcs']]],
        ['ITC', 'IT consumables and accessories (toner, cables, mice)', 'stock', [['Toner cartridge, black', 'pcs'], ['USB flash drive 64GB', 'pcs'], ['Wireless mouse', 'pcs']]],
        ['PPE', 'Personal protective equipment', 'stock', [['Disposable gloves, nitrile, box of 100', 'box'], ['Face masks, surgical, box of 50', 'box'], ['High-visibility vest', 'pcs']]],
        ['MEDC', 'Medical consumables and first aid', 'stock', [['First aid kit, vehicle', 'kit'], ['Oral rehydration salts, sachet', 'sachet'], ['Gauze bandage 10 cm', 'roll']]],
        ['SPR', 'Vehicle spare parts', 'stock', [['Oil filter, 4x4', 'pcs'], ['Brake pads, front set', 'set'], ['Tyre 265/70 R16', 'pcs']]],
        ['LUB', 'Fuel, lubricants and filters', 'stock', [['Diesel fuel', 'litre'], ['Engine oil 15W-40', 'litre']]],
        ['CLN', 'Cleaning and hygiene supplies', 'stock', [['Bar soap 200g', 'pcs'], ['Chlorine tablets, water treatment', 'tab'], ['Hygiene kit, family', 'kit']]],
        ['UNI', 'Uniforms and clothing', 'stock', [['Staff T-shirt with logo', 'pcs']]],
        ['HTL', 'Hand tools', 'stock', [['Shovel, round point', 'pcs'], ['Hammer, claw, 500g', 'pcs']]],
        ['CAMP', 'Field camp supplies (tents, bedding, water containers)', 'stock', [['Family tent 16 m2', 'pcs'], ['Jerrycan 20 L, collapsible', 'pcs'], ['Blanket, thermal', 'pcs'], ['Mosquito net, LLIN', 'pcs']]],
        ['CONS', 'Construction and repair materials', 'stock', [['Cement 50 kg bag', 'bag'], ['PVC pipe 2", 6 m', 'pcs']]],
        ['FOOD', 'Food and drinking water', 'stock', [['Bottled water 1.5 L, pack of 6', 'pack'], ['High energy biscuits', 'carton']]],
        ['SRV', 'Services', 'stock', [['Vehicle servicing (labour)', 'service', 'service'], ['Printing services', 'job', 'service']]],
    ];
    foreach ($cats as [$cc, $cn, $type, $items]) {
        $cid = $ins('INSERT INTO categories (code, name, default_type) VALUES (?, ?, ?)', [$cc, $cn, $type]);
        foreach ($items as $n => $it) {
            $ins('INSERT INTO items (code, description, category_id, unit, item_type) VALUES (?, ?, ?, ?, ?)',
                 [$cc . '-' . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT), $it[0], $cid, $it[1], $it[2] ?? $type]);
        }
    }

    // Approval threshold: above 5,000 USD the Country Director (Alex) approves after the Budget Holder
    $ins('INSERT INTO approval_levels (name, min_amount, approver_user_id) VALUES (?, ?, ?)', ['Country Director', 5000, $uid['admin']]);

    // Warehouses
    $ins("INSERT INTO warehouses (code, name, address) VALUES ('NBO', 'Nairobi main warehouse', 'Industrial Area, Nairobi'), ('LOD', 'Lodwar field store', 'Lodwar, Turkana')", []);

    // Opening stock in lots (each with its donor coding) so stock requests, transfers, counts and traceability can be tried
    $whId = fn(string $c) => (int)$pdo->query('SELECT id FROM warehouses WHERE code = ' . $pdo->quote($c))->fetchColumn();
    $finId = fn(string $c) => (int)$pdo->query('SELECT id FROM fin_values WHERE code = ' . $pdo->quote($c))->fetchColumn();
    foreach ([
        ['CLN-0003', 'LOD', 200, 18.5, 'ECHO', 'ECH-TUR', '5030', null, ''],
        ['CLN-0001', 'LOD', 1000, 0.40, 'ECHO', 'ECH-TUR', '5030', null, ''],
        ['CAMP-0004', 'LOD', 400, 4.20, 'ECHO', 'ECH-MAR', '5030', null, ''],
        ['CAMP-0003', 'LOD', 300, 6.00, 'FCDO', 'FCD-GAR', '5010', null, ''],
        ['MEDC-0002', 'NBO', 3000, 0.08, 'FCDO', 'FCD-GAR', '5020', date('Y-m-d', strtotime('+60 days')), 'ORS-2401'],
        ['MEDC-0002', 'NBO', 5000, 0.09, 'ECHO', 'ECH-TUR', '5020', date('Y-m-d', strtotime('+400 days')), 'ORS-2510'],
        ['STAT-0001', 'NBO', 60, 4.80, 'UNR', 'UNR-SUP', '7010', null, ''],
        ['MEDC-0001', 'NBO', 25, 35.0, 'FCDO', 'FCD-GAR', '5020', date('Y-m-d', strtotime('+300 days')), 'FAK-77'],
    ] as [$ic, $wc, $q, $cost, $d, $cc, $bl, $exp, $batch]) {
        $iid = (int)$pdo->query('SELECT id FROM items WHERE code = ' . $pdo->quote($ic))->fetchColumn(); $wid = $whId($wc);
        $when = date('Y-m-d H:i:s', strtotime('-20 days'));
        $lot = $ins("INSERT INTO stock_lots (item_id, warehouse_id, source_type, source_ref, received_date, qty_in, qty_left, unit_cost, fin1_id, fin2_id, fin3_id, batch_no, expiry_date, created_at)
                     VALUES (?, ?, 'opening', 'Opening stock', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [$iid, $wid, date('Y-m-d', strtotime('-20 days')), $q, $q, $cost, $finId($d), $finId($cc), $finId($bl), $batch, $exp, $when]);
        $cur = $pdo->query("SELECT qty, avg_cost FROM stock WHERE item_id = $iid AND warehouse_id = $wid")->fetch(PDO::FETCH_ASSOC);
        $nq = ($cur ? (float)$cur['qty'] : 0) + $q; $nc = (($cur ? (float)$cur['qty'] * (float)$cur['avg_cost'] : 0) + $q * $cost) / $nq;
        $pdo->prepare('INSERT INTO stock (item_id, warehouse_id, qty, avg_cost) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE qty = VALUES(qty), avg_cost = VALUES(avg_cost)')->execute([$iid, $wid, $nq, $nc]);
        $ins("INSERT INTO stock_moves (item_id, warehouse_id, move_type, qty, unit_cost, balance_after, ref_type, ref_number, batch_no, expiry_date, fin1_id, fin2_id, fin3_id, lot_id, created_at)
              VALUES (?, ?, 'receipt', ?, ?, ?, 'opening', 'Opening stock', ?, ?, ?, ?, ?, ?, ?)", [$iid, $wid, $q, $cost, $nq, $batch, $exp, $finId($d), $finId($cc), $finId($bl), $lot, $when]);
    }

    // Procurement methods and suppliers
    foreach ([['Single quotation', 1000, 1, 0], ['Request for quotations (RFQ)', 25000, 3, 1], ['Open tender', null, 3, 1]] as $m) {
        $ins('INSERT INTO proc_methods (name, max_amount, min_quotes, sealed) VALUES (?, ?, ?, ?)', $m);
    }
    $catId = fn(string $c) => (int)$pdo->query('SELECT id FROM categories WHERE code = ' . $pdo->quote($c))->fetchColumn();
    foreach ([
        ['Savannah Office Supplies Ltd', 'Grace Wanjiru', 'Nairobi', ['STAT', 'ITC', 'FURN']],
        ['Rift Valley Computers', 'David Otieno', 'Nairobi', ['IT', 'ITC', 'TEL']],
        ['Lakeside Hygiene & Relief', 'Amina Hassan', 'Kisumu', ['CLN', 'CAMP', 'MEDC']],
        ['Coastline Motors', 'Peter Mwangi', 'Mombasa', ['VEH', 'SPR', 'LUB']],
        ['Northern Frontier Traders', 'Abdi Noor', 'Garissa', ['CAMP', 'CONS', 'HTL', 'FOOD']],
        ['PowerGen East Africa', 'Lucy Achieng', 'Nairobi', ['GEN', 'TOOL', 'APPL']],
        ['MediQuip Kenya', 'Dr. Samuel Kiprop', 'Nakuru', ['MED', 'MEDC', 'PPE']],
        ['Turkana General Store', 'Joseph Ekai', 'Lodwar', ['CLN', 'FOOD', 'CAMP', 'STAT']],
        ['SafeGuard Uniforms', 'Esther Njeri', 'Thika', ['UNI', 'PPE']],
        ['Highland Hardware', 'Kevin Mutua', 'Eldoret', ['HTL', 'CONS', 'TOOL']],
    ] as $i => [$nm, $contact, $city, $cc]) {
        $slug = strtolower(preg_replace('/[^a-z]+/i', '', explode(' ', $nm)[0]));
        $sid = $ins("INSERT INTO suppliers (code, name, contact_name, email, phone, address, country, registration_no, status) VALUES (?, ?, ?, ?, ?, ?, 'Kenya', ?, ?)",
            ['SUP-' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT), $nm, $contact, "sales@$slug.example", '+254 7' . str_pad((string)(10000000 + $i * 1234567), 8, '0'), $city, 'P05' . (1000000 + $i * 7919) . 'K', $i === 8 ? 'blacklisted' : 'active']);
        foreach ($cc as $c) if ($cid = $catId($c)) $ins('INSERT INTO supplier_categories (supplier_id, category_id) VALUES (?, ?)', [$sid, $cid]);
    }

    // Sample Purchase Requests, one per status
    $fid  = fn(string $code) => (int)$pdo->query('SELECT id FROM fin_values WHERE code = ' . $pdo->quote($code))->fetchColumn();
    $iid  = fn(string $code) => $pdo->query('SELECT id, unit FROM items WHERE code = ' . $pdo->quote($code))->fetch(PDO::FETCH_ASSOC);
    $year = date('Y'); $n = 0;
    $mk = function (string $req, string $title, string $status, string $donor, string $cc, array $lines, array $flow, string $loc, int $daysAgo)
          use ($pdo, $ins, $fid, $iid, $uid, $year, &$n) {
        $n++;
        $created = date('Y-m-d H:i:s', strtotime("-$daysAgo days 09:00"));
        $prId = $ins('INSERT INTO pr (number, requester_id, title, needed_by, delivery_location, justification, currency, status, created_at, submitted_at, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ["PR-$year-" . str_pad((string)$n, 4, '0', STR_PAD_LEFT), $uid[$req], $title, date('Y-m-d', strtotime('+' . (21 - $daysAgo) . ' days')), $loc,
             'Sample request created for the demo.', 'USD', $status, $created,
             $status === 'draft' ? null : $created, $status === 'approved' ? date('Y-m-d H:i:s', strtotime("-$daysAgo days 15:00")) : null]);
        foreach ($lines as $i => [$item, $spec, $qty, $price, $bl]) {
            $it = $iid($item);
            $ins('INSERT INTO pr_lines (pr_id, line_no, item_id, specification, qty, unit, unit_price, fin1_id, fin2_id, fin3_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                 [$prId, $i + 1, $it['id'], $spec, $qty, $it['unit'], $price, $fid($donor), $fid($cc), $fid($bl)]);
        }
        $hist = fn($who, $act, $c = '') => $ins('INSERT INTO pr_history (pr_id, user_id, action, comment, created_at) VALUES (?, ?, ?, ?, ?)', [$prId, $uid[$who], $act, $c, $created]);
        $hist($req, 'created');
        if ($status === 'draft') return;
        $hist($req, 'submitted');
        [$approver, $state, $comment, $by] = $flow;
        $ins('INSERT INTO pr_approvals (pr_id, approver_id, fin_value_id, status, comment, decided_by, decided_at, stage, level_name) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)',
             [$prId, $uid[$approver], $fid($cc), $state, $comment, $state === 'pending' ? null : $uid[$by], $state === 'pending' ? null : $created, 'Budget Holder']);
        $total = array_sum(array_map(fn($l) => $l[2] * $l[3], $lines));
        if ($total > 5000 && $uid[$approver] !== $uid['admin'] && $state === 'pending') {
            $ins("INSERT INTO pr_approvals (pr_id, approver_id, status, stage, level_name) VALUES (?, ?, 'pending', 2, 'Country Director')", [$prId, $uid['admin']]);
        }
        if ($state === 'approved') { $hist($by, 'approved', $comment); $hist($by, 'fully approved'); }
        if ($state === 'returned') $hist($by, 'returned', $comment);
    };
    $mk('requester', 'Hygiene kits for Turkana distribution', 'submitted', 'ECHO', 'ECH-TUR',
        [['CLN-0003', 'Family kit: soap, sanitary pads, jerrycan, toothbrushes', 500, 18.50, '5030'], ['CAMP-0004', 'WHO-approved, 180 x 160 x 150 cm', 500, 4.20, '5030']],
        ['budget_holder', 'pending', '', null], 'Lodwar warehouse', 1);
    $mk('requester', 'Laptops for the M&E team', 'approved', 'FCDO', 'FCD-NBI',
        [['IT-0001', 'Including carry bag and 3-year warranty', 4, 950, '7020'], ['ITC-0003', '', 4, 12, '7020']],
        ['budget_holder', 'approved', 'OK, within budget.', 'budget_holder'], 'Nairobi office', 6);
    $mk('budget_holder', 'Backup generator for Marsabit field office', 'submitted', 'ECHO', 'ECH-TUR',
        [['GEN-0001', 'Silent canopy, with installation', 1, 6800, '7030']],
        ['budget_holder', 'pending', '', null], 'Marsabit field office', 2);
    $mk('requester', 'Replacement tyres for KDA 123X', 'returned', 'UNR', 'UNR-SUP',
        [['SPR-0003', 'All-terrain', 4, 210, '6020']],
        ['budget_holder', 'returned', 'Please attach the fleet officer\'s inspection note and check whether 2 tyres are enough.', 'budget_holder'], 'Nairobi garage', 4);
    $mk('requester', 'Toner for Nairobi office printers', 'approved', 'UNR', 'UNR-SUP',
        [['ITC-0001', 'HP 59A compatible', 6, 85, '7010']],
        ['budget_holder', 'approved', '', 'budget_holder'], 'Nairobi office', 3);
    $mk('requester', 'Office stationery for Q4', 'draft', 'UNR', 'UNR-SUP',
        [['STAT-0001', '', 40, 4.80, '7010'], ['STAT-0002', '', 6, 7.50, '7010']],
        [], 'Nairobi office', 0);

    // Sample sealed RFQ for the laptops PR: deadline passed, 3 bids waiting to be opened
    $pr2 = (int)$pdo->query("SELECT id FROM pr WHERE title = 'Laptops for the M&E team'")->fetchColumn();
    $pdo->prepare("UPDATE pr SET status = 'processing' WHERE id = ?")->execute([$pr2]);
    $rfqId = $ins("INSERT INTO rfq (number, pr_id, title, method_name, min_quotes, sealed, currency, instructions, delivery_location, deadline, status, created_by, created_at, sent_at)
                   VALUES (?, ?, 'Laptops for the M&E team', 'Request for quotations (RFQ)', 3, 1, 'USD', ?, 'Nairobi office', ?, 'open', ?, ?, ?)",
        ["RFQ-$year-0001", $pr2, "Prices must include delivery to the Nairobi office.\nPlease quote in USD. Leave a price empty for any item you cannot supply.",
         date('Y-m-d H:i:s', strtotime('-1 hour')), $uid['procurement'], date('Y-m-d H:i:s', strtotime('-5 days')), date('Y-m-d H:i:s', strtotime('-5 days'))]);
    foreach ([['Price', 70, 1], ['Delivery time', 15, 0], ['Quality / technical compliance', 15, 0]] as $i => [$cn, $cw, $cp]) {
        $ins('INSERT INTO rfq_criteria (rfq_id, name, weight, is_price, sort) VALUES (?, ?, ?, ?, ?)', [$rfqId, $cn, $cw, $cp, $i]);
    }
    $rl = [];
    foreach ($pdo->query("SELECT l.*, i.description FROM pr_lines l JOIN items i ON i.id = l.item_id WHERE l.pr_id = $pr2 ORDER BY l.line_no")->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $rl[] = [$ins('INSERT INTO rfq_lines (rfq_id, pr_line_id, line_no, item_id, description, specification, qty, unit) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                      [$rfqId, $l['id'], $l['line_no'], $l['item_id'], $l['description'], $l['specification'], $l['qty'], $l['unit']]), (float)$l['qty']];
    }
    $sup = fn(string $code) => (int)$pdo->query('SELECT id FROM suppliers WHERE code = ' . $pdo->quote($code))->fetchColumn();
    $rh = fn($act, $sid = null, $uidv = null) => $ins('INSERT INTO rfq_history (rfq_id, user_id, supplier_id, action, created_at) VALUES (?, ?, ?, ?, ?)', [$rfqId, $uidv, $sid, $act, date('Y-m-d H:i:s', strtotime('-4 days'))]);
    $rh('created', null, $uid['procurement']); $rh('sent', null, $uid['procurement']);
    foreach ([['SUP-0002', [910, 11], 21, 'Dell Latitude 3440'], ['SUP-0001', [975, 9.5], 14, 'HP ProBook 440'], ['SUP-0006', [890, null], 30, 'Lenovo ThinkPad E14, no mice']] as [$sc, $prices, $days, $brand]) {
        $sid = $sup($sc);
        $ins("INSERT INTO rfq_suppliers (rfq_id, supplier_id, token, invited_at, email_status, viewed_at) VALUES (?, ?, ?, ?, 'Demo: emails are never sent', ?)",
             [$rfqId, $sid, bin2hex(random_bytes(24)), date('Y-m-d H:i:s', strtotime('-5 days')), date('Y-m-d H:i:s', strtotime('-4 days'))]);
        $qid = $ins('INSERT INTO quotes (rfq_id, supplier_id, delivery_days, validity_days, notes, submitted_at) VALUES (?, ?, ?, 30, ?, ?)', [$rfqId, $sid, $days, $brand, date('Y-m-d H:i:s', strtotime('-2 days'))]);
        foreach ($rl as $k => [$lid]) $ins('INSERT INTO quote_lines (quote_id, rfq_line_id, unit_price, remarks) VALUES (?, ?, ?, ?)', [$qid, $lid, $prices[$k], $k === 0 ? $brand : '']);
        $rh('bid submitted', $sid);
    }
    $ins("INSERT INTO pr_history (pr_id, user_id, action, comment) VALUES (?, ?, 'sent to RFQ', ?)", [$pr2, $uid['procurement'], "RFQ-$year-0001"]);

    // Sample Purchase Order, acknowledged by the supplier and ready to receive (radios = assets, USB drives = stock)
    $mk('requester', 'Radios and USB drives for Lodwar field office', 'approved', 'ECHO', 'ECH-TUR',
        [['TEL-0001', 'With spare battery and charger', 3, 420, '7020'], ['ITC-0002', '', 10, 14, '7020']],
        ['budget_holder', 'approved', '', 'budget_holder'], 'Lodwar field store', 9);
    $prR = (int)$pdo->query("SELECT MAX(id) FROM pr")->fetchColumn();
    $pdo->prepare("UPDATE pr SET status = 'ordered' WHERE id = ?")->execute([$prR]);
    $poId = $ins("INSERT INTO po (number, pr_id, supplier_id, currency, delivery_location, delivery_date, payment_terms, terms, status, token, created_by, created_at, acknowledged_at, acknowledged_name, email_status)
                  VALUES (?, ?, ?, 'USD', 'Lodwar field store', ?, ?, ?, 'acknowledged', ?, ?, ?, ?, 'David Otieno, Sales', 'Demo: emails are never sent')",
        ["PO-$year-0001", $prR, $sup('SUP-0002'), date('Y-m-d', strtotime('+2 days')), 'Payment within 30 days of receipt of goods and a valid invoice quoting this PO number.',
         function_exists('default_po_terms') ? default_po_terms() : 'Standard terms and conditions apply.', bin2hex(random_bytes(24)), $uid['procurement'], date('Y-m-d H:i:s', strtotime('-6 days')), date('Y-m-d H:i:s', strtotime('-5 days'))]);
    foreach ($pdo->query("SELECT l.*, i.description FROM pr_lines l JOIN items i ON i.id = l.item_id WHERE l.pr_id = $prR ORDER BY l.line_no")->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $ins('INSERT INTO po_lines (po_id, line_no, pr_line_id, item_id, description, specification, remarks, qty, unit, unit_price, fin1_id, fin2_id, fin3_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
             [$poId, $l['line_no'], $l['id'], $l['item_id'], $l['description'], $l['specification'], $l['line_no'] == 1 ? 'Motorola DP1400' : 'SanDisk Ultra', $l['qty'], $l['unit'], $l['unit_price'], $l['fin1_id'], $l['fin2_id'], $l['fin3_id']]);
    }
    $ins("INSERT INTO po_history (po_id, user_id, action, comment) VALUES (?, ?, 'issued', 'Single quotation')", [$poId, $uid['procurement']]);

    // A second order, fully received, with two supplier invoices: one ready to pay, one on hold (price above the PO)
    $mk('requester', 'Soap and blankets top-up for Lodwar store', 'approved', 'ECHO', 'ECH-TUR',
        [['CLN-0001', '', 2000, 0.40, '5030'], ['CAMP-0003', 'Fleece, 150 x 200 cm', 100, 6.00, '5030']],
        ['budget_holder', 'approved', '', 'budget_holder'], 'Lodwar field store', 15);
    $prB = (int)$pdo->query("SELECT MAX(id) FROM pr")->fetchColumn();
    $pdo->prepare("UPDATE pr SET status = 'ordered' WHERE id = ?")->execute([$prB]);
    $po2 = $ins("INSERT INTO po (number, pr_id, supplier_id, currency, delivery_location, delivery_date, payment_terms, terms, status, token, created_by, created_at, acknowledged_at, acknowledged_name, email_status)
                 VALUES (?, ?, ?, 'USD', 'Lodwar field store', ?, 'Payment within 30 days of receipt of goods and a valid invoice quoting this PO number.', ?, 'received', ?, ?, ?, ?, 'Mary Njeri, Accounts', 'Demo: emails are never sent')",
        ["PO-$year-0002", $prB, $sup('SUP-0003'), date('Y-m-d', strtotime('-6 days')), function_exists('default_po_terms') ? default_po_terms() : 'Standard terms and conditions apply.',
         bin2hex(random_bytes(24)), $uid['procurement'], date('Y-m-d H:i:s', strtotime('-13 days')), date('Y-m-d H:i:s', strtotime('-12 days'))]);
    $grn2 = $ins("INSERT INTO grn (number, po_id, warehouse_id, received_date, delivery_note, received_by, created_at) VALUES (?, ?, ?, ?, 'DN-8812', ?, ?)",
        ["GRN-$year-0001", $po2, $whId('LOD'), date('Y-m-d', strtotime('-7 days')), $uid['storekeeper'], date('Y-m-d H:i:s', strtotime('-7 days'))]);
    $pol2 = [];
    foreach ($pdo->query("SELECT l.*, i.description FROM pr_lines l JOIN items i ON i.id = l.item_id WHERE l.pr_id = $prB ORDER BY l.line_no")->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $plId = $ins('INSERT INTO po_lines (po_id, line_no, pr_line_id, item_id, description, specification, qty, unit, unit_price, fin1_id, fin2_id, fin3_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
             [$po2, $l['line_no'], $l['id'], $l['item_id'], $l['description'], $l['specification'], $l['qty'], $l['unit'], $l['unit_price'], $l['fin1_id'], $l['fin2_id'], $l['fin3_id']]);
        $pol2[(int)$l['line_no']] = $plId;
        $ins("INSERT INTO grn_lines (grn_id, po_line_id, item_id, line_type, qty_received, unit_price) VALUES (?, ?, ?, 'stock', ?, ?)", [$grn2, $plId, $l['item_id'], $l['qty'], $l['unit_price']]);
        $wid = $whId('LOD'); $iidN = (int)$l['item_id']; $q = (float)$l['qty']; $cost = (float)$l['unit_price']; $when = date('Y-m-d H:i:s', strtotime('-7 days'));
        $lot = $ins("INSERT INTO stock_lots (item_id, warehouse_id, source_type, source_ref, received_date, qty_in, qty_left, unit_cost, fin1_id, fin2_id, fin3_id, created_at)
                     VALUES (?, ?, 'grn', ?, ?, ?, ?, ?, ?, ?, ?, ?)", [$iidN, $wid, "GRN-$year-0001", date('Y-m-d', strtotime('-7 days')), $q, $q, $cost, $l['fin1_id'], $l['fin2_id'], $l['fin3_id'], $when]);
        $cur = $pdo->query("SELECT qty, avg_cost FROM stock WHERE item_id = $iidN AND warehouse_id = $wid")->fetch(PDO::FETCH_ASSOC);
        $nq = ($cur ? (float)$cur['qty'] : 0) + $q; $nc = (($cur ? (float)$cur['qty'] * (float)$cur['avg_cost'] : 0) + $q * $cost) / $nq;
        $pdo->prepare('INSERT INTO stock (item_id, warehouse_id, qty, avg_cost) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE qty = VALUES(qty), avg_cost = VALUES(avg_cost)')->execute([$iidN, $wid, $nq, $nc]);
        $ins("INSERT INTO stock_moves (item_id, warehouse_id, move_type, qty, unit_cost, balance_after, ref_type, ref_id, ref_number, fin1_id, fin2_id, fin3_id, user_id, lot_id, created_at)
              VALUES (?, ?, 'receipt', ?, ?, ?, 'grn', ?, ?, ?, ?, ?, ?, ?, ?)", [$iidN, $wid, $q, $cost, $nq, $grn2, "GRN-$year-0001", $l['fin1_id'], $l['fin2_id'], $l['fin3_id'], $uid['storekeeper'], $lot, $when]);
    }
    foreach ([['issued', 'Single quotation', '-13 days'], ['acknowledged by supplier', 'Mary Njeri, Accounts', '-12 days'], ['received', "GRN-$year-0001", '-7 days']] as [$a, $c, $t]) {
        $ins('INSERT INTO po_history (po_id, user_id, action, comment, created_at) VALUES (?, ?, ?, ?, ?)', [$po2, $a === 'acknowledged by supplier' ? null : $uid[$a === 'received' ? 'storekeeper' : 'procurement'], $a, $c, date('Y-m-d H:i:s', strtotime($t))]);
    }
    $ins("INSERT INTO pr_history (pr_id, user_id, action, comment) VALUES (?, ?, 'ordered', ?)", [$prB, $uid['procurement'], "PO-$year-0002"]);
    foreach ([[1, 'SI-24-4471', 2000, 0.40, 'ready', '', '-5 days'],
              [2, 'SI-24-4472', 100, 6.90, 'on_hold', 'Line 2 (Blankets): price 6.90 is above the PO price 6.00 (+10% allowed).', '-4 days']] as $k => [$ln, $sno, $q, $pr, $st, $why, $t]) {
        $invId = $ins("INSERT INTO invoices (number, supplier_invoice_no, po_id, supplier_id, currency, invoice_date, due_date, tax_amount, total, status, hold_reasons, created_by, created_at)
                       VALUES (?, ?, ?, ?, 'USD', ?, ?, 0, ?, ?, ?, ?, ?)",
            ["INV-$year-" . str_pad((string)($k + 1), 4, '0', STR_PAD_LEFT), $sno, $po2, $sup('SUP-0003'), date('Y-m-d', strtotime($t)), date('Y-m-d', strtotime($t . ' +30 days')), round($q * $pr, 2), $st, $why, $uid['finance'], date('Y-m-d H:i:s', strtotime($t))]);
        $ins('INSERT INTO invoice_lines (invoice_id, po_line_id, qty, unit_price) VALUES (?, ?, ?, ?)', [$invId, $pol2[$ln], $q, $pr]);
        $ins("INSERT INTO invoice_history (invoice_id, user_id, action, comment, created_at) VALUES (?, ?, 'recorded', ?, ?)", [$invId, $uid['finance'], "Supplier invoice $sno", date('Y-m-d H:i:s', strtotime($t))]);
        if ($st === 'on_hold') $ins("INSERT INTO invoice_history (invoice_id, user_id, action, comment, created_at) VALUES (?, ?, 'put on hold', ?, ?)", [$invId, $uid['finance'], $why, date('Y-m-d H:i:s', strtotime($t))]);
    }

    // Staff, locations and assets in use (with handovers)
    foreach ([['Grace Wanjiru', 'Programme Manager', 'Programmes', 'grace.w@horizon.example'], ['David Kiprono', 'Logistics Officer', 'Support', 'david.k@horizon.example'],
              ['Amina Hassan', 'WASH Engineer', 'Programmes', 'amina.h@horizon.example'], ['Peter Ekai', 'Driver', 'Support', ''],
              ['Joyce Akinyi', 'Finance Officer', 'Finance', 'joyce.a@horizon.example'], ['Samuel Lokol', 'Field Coordinator', 'Programmes', 'samuel.l@horizon.example']] as $k => [$n, $pos, $dep, $em]) {
        $ins('INSERT INTO employees (name, position, department, email, staff_no) VALUES (?, ?, ?, ?, ?)', [$n, $pos, $dep, $em, 'HRK-' . str_pad((string)($k + 1), 3, '0', STR_PAD_LEFT)]);
    }
    $pdo->exec("INSERT INTO locations (name, address) SELECT name, address FROM warehouses");
    foreach (['Nairobi office', 'Lodwar field office', 'Kakuma distribution point', 'Marsabit field office'] as $ln) $ins('INSERT INTO locations (name) VALUES (?)', [$ln]);
    $locId = fn(string $n) => (int)$pdo->query('SELECT id FROM locations WHERE name = ' . $pdo->quote($n))->fetchColumn();
    $empId = fn(string $n) => (int)$pdo->query('SELECT id FROM employees WHERE name = ' . $pdo->quote($n))->fetchColumn();
    $catOf = fn(string $c) => (int)$pdo->query('SELECT id FROM categories WHERE code = ' . $pdo->quote($c))->fetchColumn();
    $aIds = [];
    foreach ([
        ['AS-IT-0001', 'IT', 'Laptop 14", i5, 16GB RAM', 'Dell Latitude 3440', 'DL3440-88412', '-14 months', 905, 'FCDO', 'FCD-NBI', '7020', 'Nairobi office', 'Grace Wanjiru', 'good', 'in_use'],
        ['AS-IT-0002', 'IT', 'Laptop 14", i5, 16GB RAM', 'Dell Latitude 3440', 'DL3440-88419', '-14 months', 905, 'FCDO', 'FCD-NBI', '7020', 'Nairobi office', 'Joyce Akinyi', 'good', 'in_use'],
        ['AS-IT-0003', 'IT', 'Laser printer A4 multifunction', 'HP LaserJet M428', 'PHBX12093', '-30 months', 420, 'UNR', 'UNR-SUP', '7020', 'Nairobi office', '', 'fair', 'in_store'],
        ['AS-VEH-0001', 'VEH', '4x4 station wagon, diesel', 'Toyota Land Cruiser 76, KDA 123X', 'JTEBH9FJ10K112233', '-3 years', 58000, 'ECHO', 'ECH-TUR', '6030', 'Lodwar field office', 'Peter Ekai', 'good', 'in_use'],
        ['AS-GEN-0001', 'GEN', 'Diesel generator 10 kVA', 'Perkins 10kVA silent', 'PK10-55821', '-2 years', 6400, 'ECHO', 'ECH-MAR', '7030', 'Marsabit field office', '', 'good', 'in_use'],
        ['AS-TEL-0101', 'TEL', 'Satellite phone', 'Thuraya XT-Lite', '', '-8 months', 690, 'ECHO', 'ECH-TUR', '7020', 'Lodwar field office', 'Samuel Lokol', 'new', 'in_use'],
        ['AS-FURN-0001', 'FURN', 'Office desk 140 cm', '', '', '-4 years', 180, 'UNR', 'UNR-SUP', '7030', 'Nairobi office', '', 'poor', 'in_repair'],
    ] as [$code, $cc, $desc, $bm, $sn, $age, $val, $d, $c, $bl, $loc, $emp, $cond, $st]) {
        $aid = $ins("INSERT INTO assets (code, category_id, description, brand_model, serial_no, purchase_date, purchase_value, currency, fin1_id, fin2_id, fin3_id, location_id, location, employee_id, cond, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'USD', ?, ?, ?, ?, ?, ?, ?, ?)",
            [$code, $catOf($cc), $desc, $bm, $sn, date('Y-m-d', strtotime($age)), $val, $finId($d), $finId($c), $finId($bl), $locId($loc), $loc, $emp ? $empId($emp) : null, $cond, $st]);
        $ins("INSERT INTO asset_events (asset_id, event, details, created_at) VALUES (?, 'imported', 'From existing register', ?)", [$aid, date('Y-m-d H:i:s', strtotime($age))]);
        $aIds[$code] = $aid;
    }
    $sigPng = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAASwAAABaCAYAAAACcWsdAAAET0lEQVR42u3dTVLjMBBA4SHFOVhzAO5/Ag7AmotkFlNUARPbktWS9fO9qtlAJjjd1lO3LDtP9/v9DwCMwE0IABAWABAWAMICAMICAMICQFgAQFgAQFgACAsACAsACAsAYQEAYQEAYQEgLAAgLAAgLACEBQCEBQCEBYCwAICwAICwABAWABAWABAWAMICAMICQFgAQFgAQFgACAsA+uZ55IN/eX27P/r558f7k9QC8/F0v9+nkhVplcVQ3KAlbCyr1NeQlbiBsC6pCh5VBgbfOZmLGwir0oD4LirtTFwbSFrokWZrWBFrTr/fY+v/pL6OsH7GRtz6zJU8NBZW6my9l5jcBWKD7zguKZWVuPXRechDo5YwYs3J1aw2snr0c63h9bKSh0bCOhJNirTOyorQ8mWFfmRlXfGiCusoCVuJ+fpXIqK99RmzN9mXxjTinNo7x+XhQmHtBT81MRJ4zeRiZt+WzN7kGjFOtOg/qbbofqYFqbFWtXorVLqIrpXMk0VOjHJy42LIP7q6l7BGEj4/3p++kv3y+nZXpZUN2KP4zXrL1NZkurXmWhonNGwJzcpzVFe5A22lW6a+rzPlLpCf6SSMo4rCwjykDsaZK4YU8ZfE6cwa7qoV2m21Qbdqoktm6KMruXt77R5VILPm4EhaEWu0q1da4cLSDvZXFUTk4ex2ktlawzOtW8QWnZnb6xyeDW2UVqqpG3lHHGBnxJ/yWU3mWkJtYcMqd6vdyxmkM+chYt+hc1mF9WOwjDTLpR6vy+X9iL/n86vVkyCix9mtpwTj/3h+X/842lE9gqzs3L6+Y6i5U//R34l87+fVkvw7Wb2KNUJMJo367d1IFXjKEyFqfdao975FJzZ3XQOPq6qt2M4wyEYRwMgtbMrvcq5olh5LVM6H/dacHoPZ+thGb8F7Pv5RnvaZIqIzryn53DWfX2ene8Zs2nrWTdnzo6Jdu7qK2qxa8xyKfO8lK6ycGaXlY2tXvGgR8Zmj9zyN+Cz1qHXN0nzU7l6WrbCOSuKUXj5yJnaF9Vw8o2+4nnEHfk4lHtkK1jiP7XRPOFn3LiFHzMYu7Y8lgJ6PObI67PEq+s1Juf/7R0+AtGbUrtLNndFLbrieYeKI+FLh1veeqrAKB0zLe+M8SbJt+5iyVtkyB71L8swdFjXjt+yie40TrsdFylEFFPUdlWfa/NY3ao+webmX7TWEFZjYkuelr15dnd1PtJpEeptEWp/DFt0vKJ+1Z3HvW/KlDyQ13vmtwrqwyqpdSo9YkWzN2FG7p1NaRKRVvld0CITVuN/X2sRINmIbCUn1kxPC6rwasVFU6yYnEwprpGrkzO0+Bt9xTMVITr4YYtF95C/f9MC6NXMvJ3W4CU7dYyUoYCFhzTD7bD0qVvUAEJZKCyAsRLayqisgH9saKmPPD0BYALSEAEBYAEBYAAgLAAgLAAgLAGEBAGEBAGEBICwAICwAICwAk/EXcB35uIItNywAAAAASUVORK5CYII=';
    foreach ([['HO', 'Grace Wanjiru', 'Nairobi office', ['AS-IT-0001'], true], ['HO', 'Samuel Lokol', 'Lodwar field office', ['AS-TEL-0101'], false]] as $k => [$pf, $emp, $loc, $codes, $signed]) {
        $hid = $ins("INSERT INTO handovers (number, type, employee_id, location_id, created_by, created_at, token, signed_at, signed_name, signature, sign_method, email_status)
                     VALUES (?, 'handover', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Demo: emails are never sent')",
            ["HO-$year-" . str_pad((string)($k + 1), 4, '0', STR_PAD_LEFT), $empId($emp), $locId($loc), $uid['storekeeper'], date('Y-m-d H:i:s', strtotime('-' . (10 - $k * 8) . ' days')),
             bin2hex(random_bytes(24)), $signed ? date('Y-m-d H:i:s', strtotime('-9 days')) : null, $signed ? $emp : null, $signed ? $sigPng : null, $signed ? 'screen' : null]);
        foreach ($codes as $ac) {
            $ins("INSERT INTO handover_lines (handover_id, asset_id, from_text, cond) VALUES (?, ?, 'In store', 'good')", [$hid, $aIds[$ac]]);
            $ins("INSERT INTO asset_events (asset_id, event, details, ref_type, ref_id) VALUES (?, 'handed over', ?, 'handover', ?)", [$aIds[$ac], "To $emp at $loc", $hid]);
        }
    }

    // Depreciation (straight-line) and an asset count in progress
    $pdo->exec("INSERT INTO settings (k, v) VALUES ('dep_method', 'straight') ON DUPLICATE KEY UPDATE v = VALUES(v)");
    foreach (['IT' => [3, 0], 'TEL' => [3, 0], 'VEH' => [5, 20], 'GEN' => [5, 10], 'FURN' => [7, 0], 'APPL' => [5, 0], 'MED' => [5, 0], 'TOOL' => [5, 0], 'CAM' => [3, 0]] as $cc => [$life, $resid]) {
        $pdo->prepare('UPDATE categories SET useful_life = ?, residual_pct = ? WHERE code = ?')->execute([$life, $resid, $cc]);
    }
    $cntId = $ins("INSERT INTO asset_counts (number, name, created_by, created_at) VALUES (?, ?, ?, ?)", ["AC-$year-0001", "Annual asset count $year", $uid['storekeeper'], date('Y-m-d H:i:s', strtotime('-1 day'))]);
    $tN = $ins('INSERT INTO asset_count_teams (count_id, name, token) VALUES (?, ?, ?)', [$cntId, 'Team Nairobi', bin2hex(random_bytes(24))]);
    $ins('INSERT INTO asset_count_teams (count_id, name, token) VALUES (?, ?, ?)', [$cntId, 'Team Lodwar', bin2hex(random_bytes(24))]);
    $ins('INSERT INTO asset_count_lines (count_id, team_id, asset_id, location_id, employee_id, cond) VALUES (?, ?, ?, ?, ?, ?)', [$cntId, $tN, $aIds['AS-IT-0001'], $locId('Nairobi office'), $empId('Grace Wanjiru'), 'good']);
    $ins('INSERT INTO asset_count_lines (count_id, team_id, asset_id, location_id, employee_id, cond, note) VALUES (?, ?, ?, ?, ?, ?, ?)', [$cntId, $tN, $aIds['AS-IT-0002'], $locId('Nairobi office'), $empId('David Kiprono'), 'fair', 'Now used by David; screen scratched']);
    $ins('INSERT INTO asset_count_lines (count_id, team_id, location_id, cond, note, new_description, new_serial) VALUES (?, ?, ?, ?, ?, ?, ?)', [$cntId, $tN, $locId('Nairobi office'), 'good', 'Meeting room', 'Projector Epson EB-X49', 'X4Q8812']);

    // A stock request waiting for the storekeeper
    $srId = $ins("INSERT INTO stock_requests (number, requester_id, warehouse_id, issue_to, destination, purpose, needed_by, created_at) VALUES (?, ?, ?, 'Rita Requester', 'Kakuma distribution point', 'Monthly hygiene distribution, 50 households', ?, ?)",
        ["SR-$year-0001", $uid['requester'], $whId('LOD'), date('Y-m-d', strtotime('+3 days')), date('Y-m-d H:i:s', strtotime('-1 day'))]);
    foreach ([['CLN-0003', 50], ['CLN-0001', 200], ['CAMP-0004', 50]] as [$ic, $q]) {
        $ins('INSERT INTO stock_request_lines (request_id, item_id, qty_requested) VALUES (?, (SELECT id FROM items WHERE code = ?), ?)', [$srId, $ic, $q]);
    }
    $ins("INSERT INTO pr_history (pr_id, user_id, action, comment) VALUES (?, ?, 'ordered', ?)", [$prR, $uid['procurement'], "PO-$year-0001"]);

    // Budgets per cost centre × budget line (the Turkana hygiene request is over what is left)
    $pdo->prepare("INSERT INTO settings (k, v) VALUES ('budget_level_a', ?), ('budget_level_b', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([(string)$lv2, (string)$lv3]);
    foreach ([['ECH-TUR', '5030', 12000], ['ECH-TUR', '7020', 4000], ['ECH-TUR', '6030', 60000], ['ECH-MAR', '5030', 8000], ['ECH-MAR', '7030', 7000],
              ['FCD-NBI', '7020', 6000], ['FCD-GAR', '5010', 3000], ['FCD-GAR', '5020', 6000], ['UNR-SUP', '7010', 1500], ['UNR-SUP', '7020', 2000]] as [$a, $b, $amt]) {
        $ins('INSERT INTO budgets (a_value_id, b_value_id, amount, updated_by) VALUES (?, ?, ?, ?)', [$finId($a), $finId($b), $amt, $uid['admin']]);
    }
}
