<?php
require __DIR__ . '/inc/bootstrap.php';
/* User manual. Public (no sign-in needed). The demo site shows the demo version, the real site the full set-up guide. */
$demo = is_demo();
$org = setting('org_name', 'ERP');
$sections = [
    'start'    => $demo ? 'Try the demo' : 'Getting started',
    'roles'    => 'Who does what',
    'setup'    => 'Setting up the ERP',
    'pr'       => 'Purchase Requests',
    'budget'   => 'Budgets',
    'rfq'      => 'RFQs and supplier bids',
    'award'    => 'Bid analysis and award',
    'po'       => 'Purchase Orders',
    'receive'  => 'Receiving goods',
    'stock'    => 'Stock, requests and issues',
    'counts'   => 'Stock counts',
    'trace'    => 'Donor traceability',
    'assets'   => 'Assets and handovers',
    'acounts'  => 'Asset counts and depreciation',
    'invoices' => 'Supplier invoices and payments',
    'email'    => 'Emails and security',
    'excel'    => 'Excel exports and imports',
    'faq'      => 'Questions and answers',
];
render_header($demo ? 'User guide (demo)' : 'User guide'); ?>
<style>
<?php if (!current_user()): ?>body>main{max-width:1150px!important;width:100%!important;margin:0 auto!important;box-sizing:border-box;padding:16px}<?php endif; ?>
.help{display:flex;gap:24px;align-items:flex-start}
.help nav.toc{position:sticky;top:12px;flex:0 0 230px;background:#fff;border:1px solid var(--line,#dde3ea);border-radius:8px;padding:12px 14px;font-size:14px;max-height:calc(100vh - 30px);overflow:auto}
.help nav.toc a{display:block;padding:4px 0;text-decoration:none}
.help .body{flex:1;min-width:0}
.help section{background:#fff;border:1px solid var(--line,#dde3ea);border-radius:8px;padding:6px 20px 14px;margin-bottom:16px;scroll-margin-top:12px}
.help h2{margin-top:14px}.help h3{margin:16px 0 6px;font-size:16px}
.help ol li,.help ul li{margin:4px 0}
.help .tip{background:#e8f0f8;color:#1f4e79;border-radius:6px;padding:8px 12px;margin:10px 0}
.help .warnbox{background:#fff6e0;color:#7a5400;border-radius:6px;padding:8px 12px;margin:10px 0}
.help .flow{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:10px 0}
.help .flow span{background:#f0f3f6;border-radius:6px;padding:5px 10px;font-size:14px}
.help .flow i{color:#8a96a3;font-style:normal}
.help mark{background:#ffe08a;color:inherit;padding:0 1px;border-radius:2px}
.help mark.cur{background:#ffb400}
.help nav.toc a.off{display:none}
.help kbd{background:#f0f3f6;border:1px solid #d5dce3;border-radius:4px;padding:0 5px;font-family:inherit;font-size:13px}
@media (max-width:800px){.help{display:block}.help nav.toc{position:static;max-height:none;margin-bottom:14px}}
@media print{.help nav.toc{display:none}.help section{break-inside:avoid-page;border:0}}
</style>
<div class="help">
<nav class="toc noprint"><b><?= $demo ? 'Demo guide' : 'User guide' ?></b>
  <input type="search" id="hq" placeholder="Search the guide…" autocomplete="off" aria-label="Search the guide" style="margin:8px 0 4px">
  <div id="hn" class="muted" style="font-size:13px;min-height:18px"></div>
  <?php foreach ($sections as $k => $t): ?><a href="#<?= $k ?>"><?= e($t) ?></a><?php endforeach; ?>
  <p class="muted" style="margin-bottom:0"><a href="#" onclick="window.print();return false">Print / save as PDF</a><?= current_user() ? '' : '<a href="login.php">Sign in</a>' ?></p>
</nav>
<div class="body">

<section id="start">
<?php if ($demo): ?>
  <h2>Try the demo</h2>
  <p>This is a working copy of the ERP for a fictitious NGO, <b>Horizon Relief Kenya</b>, with sample donors, cost centres, suppliers, stock, assets and documents. Everything works as in the real version, with three differences:</p>
  <ul>
    <li><b>One-click sign-in.</b> On the sign-in page, pick a role. There are no passwords. Use <b>Switch role</b> in the yellow bar to change role at any time.</li>
    <li><b>No emails are ever sent.</b> Wherever the real ERP would email a supplier or a staff member, the demo shows a button such as <b>Open as this supplier</b> so you can play their part.</li>
    <li><b>The sample data is reset every night</b>, so feel free to approve, reject, receive and delete anything.</li>
  </ul>
  <h3>The demo accounts</h3>
  <div class="table-wrap"><table><tr><th>Account</th><th>Role(s)</th><th>What to try</th></tr>
  <?php foreach (DEMO_ACCOUNTS as [$n, $r, $d]): ?><tr><td><b><?= e($n) ?></b></td><td><?= e(implode(', ', array_map(fn($x) => ROLES[$x] ?? $x, explode(',', $r)))) ?></td><td><?= e($d) ?></td></tr><?php endforeach; ?>
  </table></div>
  <h3>A 20-minute tour</h3>
  <ol>
    <li><b>Rita Requester</b>: <i>Purchase Requests &gt; + New Purchase Request</i>. Add two items, charge them to a donor, cost centre and budget line, and submit.</li>
    <li><b>Ben Budget Holder</b>: open <i>Purchase Requests &gt; To approve</i>. <b>PR-0001 Hygiene kits</b> is over budget: try <b>Approve</b> without a comment, then with one. Look at <i>My cost centres</i> and <i>Finance &gt; Budgets</i>.</li>
    <li><b>Paula Procurement</b>: open <i>RFQs</i>. <b>RFQ-0001 Laptops</b> has passed its deadline with 3 sealed bids. Click <b>Open the bids</b>, score the offers, save the award and submit it for approval.</li>
    <li><b>Ben Budget Holder</b>: approve the award (it follows the same approval chain as the request). The Purchase Orders are created automatically. Back as <b>Paula</b>, open the PO, click <b>Open as supplier</b> and accept it as the supplier.</li>
    <li><b>Sam Storekeeper</b>: <i>Receiving</i>. Receive <b>PO-0001</b> (radios become assets with serial numbers, USB drives go into stock). Then issue <b>SR-0001</b> from <i>Stock requests</i> and look at <i>Donor traceability</i>.</li>
    <li><b>Fiona Finance</b>: <i>Supplier invoices</i>. Pay <b>INV-0001</b>, look at why <b>INV-0002</b> is on hold, then record an invoice for the PO Sam just received.</li>
    <li><b>Alex Admin</b>: release INV-0002, then look at <i>Assets</i>, <i>Handovers</i>, <i>Depreciation</i> and <i>Setup</i>.</li>
  </ol>
  <div class="tip">The rest of this guide describes the full ERP. Set-up pages and emails behave as described in the real version.</div>
<?php else: ?>
  <h2>Getting started</h2>
  <p>This ERP runs the whole purchase-to-pay, warehouse and asset cycle of <?= e($org) ?> in one place. Every document is linked to the next one, and the donor coding entered on the Purchase Request follows the goods all the way through.</p>
  <div class="flow"><span>Purchase Request</span><i>→</i><span>Approval</span><i>→</i><span>RFQ &amp; bids</span><i>→</i><span>Award approval</span><i>→</i><span>Purchase Order</span><i>→</i><span>Receipt (GRN)</span><i>→</i><span>Stock / Assets</span><i>→</i><span>Invoice &amp; payment</span></div>
  <h3>Signing in</h3>
  <ul>
    <li>Your Administrator creates your account and sends you an <b>access link</b>. Open it and choose your password (at least 8 characters).</li>
    <li>If your organisation uses Microsoft 365, click <b>Sign in with Microsoft</b> and use your work account (with Microsoft's 2-step verification). Your Administrator must have added you with the same email address.</li>
    <li>Forgot your password? Click <b>Forgot your password?</b> on the sign-in page. A link valid for 1 hour is emailed to you.</li>
    <li>Click your name at the top right (<b>My account</b>) to change your password or switch off email notifications.</li>
  </ul>
  <h3>Finding your way</h3>
  <ul>
    <li>The <b>Dashboard</b> shows what is waiting for you: requests to approve, orders to receive, invoices on hold…</li>
    <li>The menu on the left only shows what your role can use. Numbers in brackets are things waiting for action.</li>
    <li>Every document keeps its own <b>history</b> (who did what, when), shown at the bottom of its page.</li>
  </ul>
<?php endif; ?>
</section>

<section id="roles">
  <h2>Who does what</h2>
  <p>A user can have several roles (for example Requester + Budget Holder). Roles are set in <i>Setup &gt; Users &amp; roles</i>.</p>
  <div class="table-wrap"><table><tr><th>Role</th><th>Main tasks</th></tr>
    <tr><td><b>Requester</b></td><td>Raises Purchase Requests and stock requests, follows their progress.</td></tr>
    <tr><td><b>Budget Holder</b></td><td>Approves Purchase Requests charged to their cost centres; follows their budgets.</td></tr>
    <tr><td><b>Procurement Officer</b></td><td>Runs RFQs, opens bids, prepares the bid analysis and award, manages suppliers and Purchase Orders.</td></tr>
    <tr><td><b>Storekeeper</b></td><td>Receives deliveries, issues stock, transfers, counts stock and assets, hands over assets.</td></tr>
    <tr><td><b>Finance Officer</b></td><td>Records supplier invoices, checks the 3-way match, records payments.</td></tr>
    <tr><td><b>Administrator</b></td><td>Sets up the ERP, manages users, approves above thresholds, approves stock count adjustments, releases invoices on hold, disposes of assets.</td></tr>
  </table></div>
  <div class="tip"><b>Segregation of duties is built in:</b> nobody approves their own request or award, the person who submits a stock count cannot approve it, and the person who records an invoice cannot release it from hold.</div>
</section>

<section id="setup">
  <h2>Setting up the ERP <span class="muted" style="font-size:14px;font-weight:normal">(Administrator)</span></h2>
  <p>Do it once, in this order. The Dashboard shows a checklist until the essentials are done.</p>
  <ol>
    <li><b>Organisation</b>: name, currency, address (printed on POs), document prefixes, payment terms and PO terms &amp; conditions, the <b>tolerance %</b> (how much an award may exceed the PR estimate, and an invoice price may exceed the PO price), the asset code prefix, automatic sign-out time.</li>
    <li><b>Microsoft sign-in</b> (optional): staff sign in with their Microsoft 365 account and its 2-step verification. Register the app in Microsoft Entra, paste the tenant ID, client ID and secret, and choose whether passwords remain allowed (administrators always keep theirs for emergencies).</li>
    <li><b>Email settings</b>: the mailbox the ERP sends from (SMTP server, port, user, password). Click <b>Send a test email to me</b>. Without this, no notification, supplier invitation or password reset is sent.</li>
    <li><b>Users &amp; roles</b>: add each user with their roles. <b>Add user and create access link</b> emails the link, or shows it so you can copy it.</li>
    <li><b>Finance codes</b>: create up to 4 coding levels (typically <i>Donor → Cost centre → Budget line</i>).
      <ul><li>A <b>linked</b> level is filtered by the level above (a cost centre belongs to one donor). An unlinked level is one shared list (the same budget lines for every cost centre).</li>
      <li>Choose which level decides the <b>Budget Holder</b> (usually the cost centre) and assign a Budget Holder to each code.</li>
      <li>Codes can be imported from a CSV file.</li></ul></li>
    <li><b>Approval thresholds</b>: extra approvers above an amount (e.g. Country Director above 5,000). Requests go through the Budget Holder first, then every threshold they exceed, in order.</li>
    <li><b>Procurement methods</b>: amount bands with the minimum number of quotes and whether bids are sealed. <b>Start from typical NGO bands</b> fills a sensible default.</li>
    <li><b>Item categories</b> and <b>Item catalogue</b>: each item is <i>stock</i>, <i>asset</i> or <i>service</i> (the default comes from its category). Both can be imported from CSV.</li>
    <li><b>Warehouses</b>, then <b>Staff</b> and <b>Asset locations</b> (Assets menu). Asset locations include your warehouses automatically.</li>
    <li><b>Suppliers</b> (Procurement menu): code, contacts, email, the categories they supply. Blacklisted suppliers cannot be invited.</li>
    <li><b>Budgets</b> (Finance menu): choose the levels (usually cost centre × budget line), then type or import the amounts.</li>
    <li>Existing assets can be imported in <i>Asset register &gt; Import</i>.</li>
  </ol>
</section>

<section id="pr">
  <h2>Purchase Requests</h2>
  <h3>Raise a request (Requester)</h3>
  <ol>
    <li><i>Purchase Requests &gt; + New Purchase Request</i>: title, needed-by date, delivery place, justification.</li>
    <li>Add a line per item from the catalogue: specification, quantity, estimated unit price, and the <b>finance coding</b> of that line (donor, cost centre, budget line…). Lines can be charged to different codes.</li>
    <li><b>Save draft</b> to continue later, or <b>Submit for approval</b>. The ERP tells you who will approve, in which order.</li>
  </ol>
  <h3>Approval (Budget Holder, then thresholds)</h3>
  <ul>
    <li>Each Budget Holder approves the lines charged to their codes. When all of them have approved, the next threshold approver (if the total requires it) is asked, and so on.</li>
    <li><b>Return for changes</b> (comment required) sends it back to the requester, who edits and resubmits.</li>
    <li>The requester can <b>Take back to edit</b> a submitted request; approvals given so far are cleared.</li>
    <li>If the requester is their own Budget Holder, an Administrator approves that part.</li>
    <li>Over-budget requests can still be approved, but only with a written justification (see <a href="#budget">Budgets</a>).</li>
  </ul>
  <div class="flow"><span>Draft</span><i>→</i><span>Submitted</span><i>→</i><span>Approved</span><i>→</i><span>In procurement (RFQ)</span><i>→</i><span>Ordered</span></div>
  <h3>Follow-up</h3>
  <p>Requesters see their requests under <i>My requests</i>. Budget Holders see every request charged to their cost centres, and where it is now, under <i>My cost centres</i>.</p>
</section>

<section id="budget">
  <h2>Budgets</h2>
  <ul>
    <li>A budget is an amount per <b>cost centre × budget line</b> (or the levels chosen by the Administrator), in the organisation's currency.</li>
    <li><b>Used = Committed + Actual</b>
      <ul><li><b>Committed</b>: Purchase Orders not cancelled, for the part not yet invoiced.</li>
      <li><b>Actual</b>: supplier invoices (tax included).</li></ul>
      Purchase Requests are not deducted; they are checked against what is left.</li>
    <li>Every request shows a <b>Budget check</b>. If a line is over what is left, or no budget exists for its coding, the request is flagged <b>Over budget</b>: the requester is warned, the approvers' email says so, and <b>Approve</b> needs a comment.</li>
    <li><i>Finance &gt; Budgets</i> shows budget, committed, actual, left and % used, with an Excel export. Budget Holders see only their cost centres by default.</li>
    <li>Administrators can edit amounts in the table, or import a CSV (download the current list, edit it in Excel, import it back).</li>
  </ul>
  <div class="warnbox">POs in another currency are counted at face value (no conversion). The page warns you when that happens.</div>
</section>

<section id="rfq">
  <h2>RFQs and supplier bids <span class="muted" style="font-size:14px;font-weight:normal">(Procurement)</span></h2>
  <ol>
    <li>Approved requests appear in <i>RFQs</i> (the number in brackets). Open the request and click <b>Create RFQ</b>. The procurement method, minimum number of quotes and sealed/open mode are set from the amount.</li>
    <li>Set the deadline, instructions and evaluation criteria (price weight and other criteria, total 100%).</li>
    <li>Choose the suppliers: those registered for the categories of the items are listed first. Blacklisted suppliers cannot be selected.</li>
    <li><b>Sealed RFQs</b>: each supplier receives a <b>personal link</b> by email (you can also copy it). They enter their prices, delivery time and validity on that page, without an account, and can change their offer until the deadline. A missing price means "not offered". Prices stay hidden from everyone until the deadline passes and Procurement clicks <b>Open the bids</b>; opening is recorded in the history.</li>
    <li><b>Non-sealed RFQs</b> (small amounts): you collect the quotes by email or on paper and type them in with <b>Save quote</b>.</li>
  </ol>
</section>

<section id="award">
  <h2>Bid analysis and award</h2>
  <ol>
    <li><b>Compliance</b>: tick non-compliant offers (they are excluded).</li>
    <li><b>Scores</b>: the committee scores each non-price criterion from 0 to 10. Price points are automatic: <i>weight × lowest price ÷ this price</i>, per line.</li>
    <li>The best offer per line is marked ★. Award each line to a supplier (split awards across suppliers are allowed). Choosing a supplier other than the ★ one requires a justification.</li>
    <li>If the award exceeds the PR estimate by more than the tolerance %, a justification is required too.</li>
    <li><b>Submit award for approval</b>: it follows the same approval chain as the request. The submitter cannot approve it.</li>
    <li><b>Print bid analysis</b> gives the signed-off comparison for the file.</li>
  </ol>
  <p>When the award is approved, one <b>Purchase Order per supplier</b> is created automatically.</p>
</section>

<section id="po">
  <h2>Purchase Orders</h2>
  <ul>
    <li>The PO carries the awarded prices, delivery place and date, payment terms and your terms &amp; conditions. Finance codes are kept on each line but <b>not printed</b> on the supplier's copy.</li>
    <li><b>Email to supplier</b> sends the PO link; the supplier views, prints and clicks <b>We accept this order</b> (acknowledgement with their name and time).</li>
    <li>A PO not yet received can be cancelled with a reason.</li>
    <li>The PO page shows its receipts (GRNs) and invoices.</li>
  </ul>
  <div class="flow"><span>Issued</span><i>→</i><span>Acknowledged</span><i>→</i><span>Partially received</span><i>→</i><span>Received</span></div>
</section>

<section id="receive">
  <h2>Receiving goods <span class="muted" style="font-size:14px;font-weight:normal">(Storekeeper)</span></h2>
  <ol>
    <li><i>Receiving</i> lists the POs waiting. Open one, choose the <b>warehouse</b>, the date and the supplier's delivery note number.</li>
    <li>For each line: quantity received, quantity <b>rejected</b> with the reason (inspection). Only accepted quantities count.</li>
    <li>Choose how each line is registered: <b>Stock</b> (bin, batch, expiry) or <b>Asset</b> (one asset per unit, with brand/model and serial numbers, which can be completed later).</li>
    <li><b>Record receipt (GRN)</b>. Stock is updated at <b>weighted average cost</b>, assets are created with codes like <i>AS-IT-0001</i>, and the PO status is updated. Partial deliveries are fine.</li>
  </ol>
  <div class="tip">The donor coding of the PO line is stored on the stock batch and on each asset. Nobody has to type finance codes again in the warehouse.</div>
</section>

<section id="stock">
  <h2>Stock, requests and issues</h2>
  <h3>Stock</h3>
  <ul>
    <li><i>Stock</i> shows quantities and value per warehouse. Open an item for its <b>stock card</b>: every movement with the balance, and the batches still in stock with their donor, batch number and expiry.</li>
  </ul>
  <h3>Stock requests</h3>
  <ol>
    <li>A requester clicks <b>+ Stock request</b>, chooses the warehouse (only items in stock there are listed), the person receiving, the destination and the quantities, then <b>Send request</b>. Storekeepers are notified.</li>
    <li>The storekeeper opens it, adjusts the quantities and clicks <b>Issue and print voucher</b>. Stock is taken from the batches expiring first, then the oldest, and the voucher shows which donor's stock was used. Partial issues are possible; a request can also be rejected or closed with a reason.</li>
  </ol>
  <h3>Transfers</h3>
  <p><i>Issues &amp; transfers</i>: move stock between warehouses. Batches keep their donor coding and cost.</p>
</section>

<section id="counts">
  <h2>Stock counts</h2>
  <ol>
    <li><i>Stock counts &gt; Start count</i> for a warehouse. The count is <b>blind</b>: counters do not see the system quantities.</li>
    <li>Enter the counted quantities, then <b>Submit count</b>.</li>
    <li>An Administrator (not the person who submitted) reviews the differences and clicks <b>Approve and adjust stock</b>, or <b>Reject (recount)</b>.</li>
  </ol>
</section>

<section id="trace">
  <h2>Donor traceability</h2>
  <p><i>Donor traceability</i> answers "what did we buy with this donor's money, and where did it go?". Choose a donor, cost centre or budget line and a period to see receipts, issues (to whom, where), transfers and adjustments, with quantities and values. <b>Export to Excel (CSV)</b> or print it for donor reports.</p>
</section>

<section id="assets">
  <h2>Assets and handovers</h2>
  <ul>
    <li>The <b>Asset register</b> lists every asset: code, description, serial, location, person holding it, condition, status, value and donor. It can be filtered and exported to Excel.</li>
    <li>Each asset page shows its full <b>history</b> and these actions: <b>Hand over to a person</b>, <b>Move to a location</b>, <b>Return to store</b>, <b>Send to repair</b> / <b>Back from repair</b>, <b>Report lost / stolen</b> / <b>Found again</b>, and <b>Dispose</b> (Administrator, with reason).</li>
    <li>A <b>handover</b> creates a receipt document (HO-…) that the staff member signs: on screen (finger or mouse), or through a link sent by email. Unsigned handovers are counted in the menu.</li>
    <li>Assets created at receipt get their codes automatically; existing assets can be imported from Excel/CSV.</li>
  </ul>
</section>

<section id="acounts">
  <h2>Asset counts and depreciation</h2>
  <h3>Asset counts</h3>
  <ol>
    <li><i>Asset counts &gt; Create count</i>, then add one or more <b>teams</b>. Each team gets a link that works on a phone, without an account.</li>
    <li>Teams search each asset, confirm where it is, who uses it and its condition, and add assets that are not in the register.</li>
    <li>The count report (Excel) shows found, not found, moved and new assets. <b>A count never changes the register by itself</b>: you decide what to update.</li>
  </ol>
  <h3>Depreciation</h3>
  <p>Choose the method in <i>Depreciation</i> (none, straight-line or reducing balance) and set the useful life and residual value % per category. The report gives the value, depreciation to date and net book value of each asset at any date. It starts from the purchase month and stops at disposal.</p>
</section>

<section id="invoices">
  <h2>Supplier invoices and payments <span class="muted" style="font-size:14px;font-weight:normal">(Finance)</span></h2>
  <ol>
    <li><i>Supplier invoices &gt; + Record an invoice</i>. Choose the PO (only POs with goods received and not yet invoiced are listed).</li>
    <li>Copy the supplier's invoice number, date, due date, the <b>quantity and unit price of each line</b>, and the tax. Attach the scanned invoice (PDF/JPG/PNG).</li>
    <li><b>Record and check the match</b>. The <b>3-way match</b> compares, per line:
      <ul><li>the quantity invoiced with what the storekeeper <b>accepted</b> and was not already invoiced</li>
      <li>the unit price with the <b>PO price</b> (up to the tolerance % above)</li></ul></li>
    <li>If everything matches, the invoice is <b>Ready to pay</b>. If not, it is <b>On hold</b> with the reasons in plain words, and Administrators are notified.</li>
  </ol>
  <h3>Invoices on hold</h3>
  <ul>
    <li>They are released automatically when the missing goods are received.</li>
    <li>Otherwise an Administrator (not the person who recorded it) can <b>Release for payment</b> with a reason, or Finance cancels it (e.g. duplicate, corrected invoice expected).</li>
    <li>The same supplier invoice number cannot be recorded twice.</li>
  </ul>
  <h3>Payments</h3>
  <p>Record each payment with date, method and bank reference; part payments are allowed (<b>Partly paid</b> → <b>Paid</b>). The payment itself is made in your bank or accounting system. The invoices list shows the total to pay and what is overdue, with an Excel export.</p>
</section>

<section id="email">
  <h2>Emails and security</h2>
  <h3>Automatic emails</h3>
  <div class="table-wrap"><table><tr><th>When</th><th>Who receives it</th></tr>
    <tr><td>A request is submitted, or moves to the next approval step</td><td>The approvers of that step</td></tr>
    <tr><td>A request is approved / returned</td><td>The requester (and Procurement when approved)</td></tr>
    <tr><td>An award is submitted, approved or returned</td><td>The approvers, then the submitter and the requester</td></tr>
    <tr><td>RFQ invitation, Purchase Order</td><td>The supplier (personal link)</td></tr>
    <tr><td>Stock request created / issued / rejected</td><td>Storekeepers / the requester</td></tr>
    <tr><td>Stock count submitted</td><td>Administrators</td></tr>
    <tr><td>Invoice on hold / released</td><td>Administrators / Finance</td></tr>
    <tr><td>Asset handover to sign</td><td>The staff member</td></tr>
  </table></div>
  <p>Nobody is emailed about their own action. Anyone can switch notifications off in <b>My account</b>.<?= $demo ? ' <b>In the demo, no email is ever sent.</b>' : '' ?></p>
  <h3>Security</h3>
  <ul>
    <li>5 wrong passwords lock the account for 15 minutes.</li>
    <li>You are signed out automatically after a period without activity (60 minutes by default).</li>
    <li>Supplier, staff and count-team links are personal and unguessable; they only show that one document.</li>
    <li><i>Setup &gt; Audit log</i> records sign-ins, lock-outs, password changes, settings, imports and every important decision, with filters and Excel export.</li>
  </ul>
</section>

<section id="excel">
  <h2>Excel exports and imports</h2>
  <ul>
    <li>Look for the <b>Excel</b> button: asset register, asset count reports, depreciation, supplier invoices, budgets and audit log. Files open directly in Excel with filters on the header row. Donor traceability exports to a CSV file that opens in Excel.</li>
    <li>Imports use CSV files saved from Excel (comma or semicolon). Each import page explains the columns: finance codes, item categories, items, assets, budgets.</li>
  </ul>
</section>

<section id="faq">
  <h2>Questions and answers</h2>
  <h3>I submitted a request but nobody received an email.</h3>
  <p><?= $demo ? 'The demo never sends emails.' : 'Check that Setup > Email settings is filled in and the test email works. The approvers are shown on the request page; they also see it on their Dashboard.' ?></p>
  <h3>"No active Budget Holder" when submitting a request.</h3>
  <p>One of the cost centres used has no Budget Holder. The Administrator assigns one in <i>Setup &gt; Finance codes</i>.</p>
  <h3>Why can't I approve my own request?</h3>
  <p>Segregation of duties: an Administrator approves the part where you are your own Budget Holder.</p>
  <h3>A supplier says the bid link does not work.</h3>
  <p>After the deadline the link no longer accepts offers. Before that, copy the link again from the RFQ page and send it yourself.</p>
  <h3>The invoice is on hold but the goods have arrived.</h3>
  <p>The storekeeper must record the receipt (GRN) first. The invoice is released automatically as soon as it matches.</p>
  <h3>Can I undo a receipt or an issue?</h3>
  <p>No. Stock movements are permanent for audit reasons. Correct differences with a stock count, and assets through their actions (return, move, dispose).</p>
  <?php if (!$demo): ?>
  <h3>Who do I contact?</h3>
  <p>Your ERP Administrator: account problems, finance codes, approval rules and settings.</p>
  <?php endif; ?>
</section>
</div>
</div>
<script>
(() => {
  const q = document.getElementById('hq'), note = document.getElementById('hn');
  const secs = [...document.querySelectorAll('.help .body section')];
  const links = [...document.querySelectorAll('.help nav.toc a[href^="#"]')];
  const norm = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const orig = new Map(secs.map(s => [s, s.innerHTML]));
  let t;
  function run() {
    const raw = q.value.trim(), term = norm(raw);
    secs.forEach(s => { s.innerHTML = orig.get(s); s.style.display = ''; });
    links.forEach(a => a.classList.remove('off'));
    if (term.length < 2) { note.textContent = ''; return; }
    let hits = 0, shown = 0;
    secs.forEach(s => {
      const walker = document.createTreeWalker(s, NodeFilter.SHOW_TEXT);
      const nodes = []; while (walker.nextNode()) nodes.push(walker.currentNode);
      let found = 0;
      nodes.forEach(n => {
        const txt = n.nodeValue, low = norm(txt); let i = low.indexOf(term);
        if (i < 0) return;
        const frag = document.createDocumentFragment(); let last = 0;
        while (i >= 0) {
          frag.appendChild(document.createTextNode(txt.slice(last, i)));
          const m = document.createElement('mark'); m.textContent = txt.slice(i, i + term.length); frag.appendChild(m);
          last = i + term.length; found++; i = low.indexOf(term, last);
        }
        frag.appendChild(document.createTextNode(txt.slice(last)));
        n.parentNode.replaceChild(frag, n);
      });
      const link = links.find(a => a.getAttribute('href') === '#' + s.id);
      if (found) { shown++; hits += found; } else { s.style.display = 'none'; if (link) link.classList.add('off'); }
    });
    note.textContent = hits ? hits + ' match' + (hits > 1 ? 'es' : '') + ' in ' + shown + ' section' + (shown > 1 ? 's' : '') + ' · Enter = next' : 'No match for "' + raw + '"';
    idx = -1;
  }
  let idx = -1;
  q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(run, 150); });
  q.addEventListener('keydown', e => {
    if (e.key === 'Escape') { q.value = ''; run(); }
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const ms = [...document.querySelectorAll('.help .body mark')]; if (!ms.length) return;
    ms.forEach(m => m.classList.remove('cur'));
    idx = (idx + (e.shiftKey ? -1 : 1) + ms.length) % ms.length;
    ms[idx].classList.add('cur'); ms[idx].scrollIntoView({behavior: 'smooth', block: 'center'});
  });
  document.addEventListener('keydown', e => { if (e.key === '/' && document.activeElement !== q && !/input|textarea|select/i.test(document.activeElement.tagName)) { e.preventDefault(); q.focus(); } });
})();
</script>
<?php render_footer();
