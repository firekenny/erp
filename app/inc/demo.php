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
    if (setting('demo_reset_on') === $today) return;
    $pdo = db();
    if (!(int)$pdo->query("SELECT GET_LOCK('erp_demo_reset', 10)")->fetchColumn()) return;
    try {
        if (setting('demo_reset_on') !== $today) {
            demo_seed($pdo);
            set_setting('demo_reset_on', $today);
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
}
