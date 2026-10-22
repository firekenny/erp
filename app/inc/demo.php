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
    'procurement'   => ['Paula Procurement', 'procurement',              'Processes approved Purchase Requests'],
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
    $mk('requester', 'Office stationery for Q4', 'draft', 'UNR', 'UNR-SUP',
        [['STAT-0001', '', 40, 4.80, '7010'], ['STAT-0002', '', 6, 7.50, '7010']],
        [], 'Nairobi office', 0);
}
