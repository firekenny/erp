<?php
require __DIR__ . '/inc/bootstrap.php';
$me = require_role('admin');

function clean_roles(array $r): string {
    return implode(',', array_values(array_intersect(array_keys(ROLES), $r)));
}
function new_invite(int $id): string {
    $t = bin2hex(random_bytes(24));
    q('UPDATE users SET invite_token = ?, invite_expires = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?', [$t, $id]);
    return base_url() . '/set_password.php?t=' . $t;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    $id  = (int)($_POST['id'] ?? 0);

    if ($act === 'invite') {
        $name  = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $roles = clean_roles((array)($_POST['roles'] ?? []));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Name and a valid email are required.', 'err');
        } elseif ($roles === '') {
            flash('Tick at least one role.', 'err');
        } elseif (q('SELECT id FROM users WHERE email = ?', [$email])->fetch()) {
            flash('A user with this email already exists.', 'err');
        } else {
            q('INSERT INTO users (name, email, roles) VALUES (?, ?, ?)', [$name, $email, $roles]);
            $newId = (int)db()->lastInsertId();
            $_SESSION['last_link'] = ['who' => $name, 'url' => new_invite($newId)];
            audit('user.invite', "$email ($roles)");
            flash("$name added. Copy the access link below and send it to them.");
        }
    } elseif ($act === 'roles' && $id) {
        $roles = clean_roles((array)($_POST['roles'] ?? []));
        if ($id === (int)$me['id'] && !str_contains($roles, 'admin')) {
            flash('You cannot remove your own Administrator role.', 'err');
        } elseif ($roles === '') {
            flash('A user needs at least one role.', 'err');
        } else {
            q('UPDATE users SET roles = ? WHERE id = ?', [$roles, $id]);
            audit('user.roles', "#$id -> $roles");
            flash('Roles updated.');
        }
    } elseif ($act === 'toggle' && $id) {
        if ($id === (int)$me['id']) {
            flash('You cannot deactivate your own account.', 'err');
        } else {
            q('UPDATE users SET active = 1 - active, invite_token = NULL WHERE id = ?', [$id]);
            audit('user.toggle', "#$id");
            flash('User status changed.');
        }
    } elseif ($act === 'link' && $id) {
        $u = q('SELECT name FROM users WHERE id = ? AND active = 1', [$id])->fetch();
        if ($u) {
            $_SESSION['last_link'] = ['who' => $u['name'], 'url' => new_invite($id)];
            audit('user.link', "#$id");
            flash('New access link created (valid 7 days). Any previous link no longer works.');
        }
    }
    redirect('users.php');
}

$users = q('SELECT * FROM users ORDER BY active DESC, name')->fetchAll();
$link = $_SESSION['last_link'] ?? null;
unset($_SESSION['last_link']);

render_header('Users & roles');

if ($link): ?>
<div class="card">
  <h2>Access link for <?= e($link['who']) ?></h2>
  <p class="muted">Send this link by email or message. It lets them choose their password and expires in 7 days.</p>
  <div class="copy" id="lnk"><?= e($link['url']) ?></div>
  <div class="actions"><button class="btn small" onclick="navigator.clipboard.writeText(document.getElementById('lnk').innerText);this.innerText='Copied'">Copy link</button></div>
</div>
<?php endif; ?>

<div class="card">
  <h2>Add a user</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="act" value="invite">
    <div class="row">
      <div><label>Full name</label><input type="text" name="name" required></div>
      <div><label>Email</label><input type="email" name="email" required></div>
    </div>
    <label>Roles</label>
    <div class="checks">
      <?php foreach (ROLES as $k => $label): ?>
        <label><input type="checkbox" name="roles[]" value="<?= e($k) ?>" <?= $k === 'requester' ? 'checked' : '' ?>> <?= e($label) ?></label>
      <?php endforeach; ?>
    </div>
    <div class="actions"><button class="btn">Add user and create access link</button></div>
  </form>
</div>

<div class="table-wrap">
<table>
  <tr><th>Name</th><th>Email</th><th>Roles</th><th>Status</th><th></th></tr>
  <?php foreach ($users as $u): $r = user_roles($u); ?>
  <tr>
    <td><?= e($u['name']) ?></td>
    <td><?= e($u['email']) ?></td>
    <td>
      <form method="post" class="checks" style="margin:0">
        <?= csrf_field() ?><input type="hidden" name="act" value="roles"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <?php foreach (ROLES as $k => $label): ?>
          <label><input type="checkbox" name="roles[]" value="<?= e($k) ?>" <?= in_array($k, $r, true) ? 'checked' : '' ?> onchange="this.form.submit()"> <?= e($label) ?></label>
        <?php endforeach; ?>
      </form>
    </td>
    <td>
      <?php if (!$u['active']): ?><span class="tag off">Deactivated</span>
      <?php elseif (!$u['password_hash']): ?><span class="tag">Link sent, not used yet</span>
      <?php else: ?><span class="tag stock">Active</span><?php endif; ?>
    </td>
    <td style="white-space:nowrap">
      <?php if ($u['active']): ?>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="link"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn sec small">New access link</button></form>
      <?php endif; ?>
      <?php if ((int)$u['id'] !== (int)$me['id']): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Change this user\'s status?')"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn sec small"><?= $u['active'] ? 'Deactivate' : 'Reactivate' ?></button></form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
</div>
<p class="muted">"New access link" also works as a password reset. Deactivated users can no longer sign in; their history is kept.</p>
<?php render_footer();
