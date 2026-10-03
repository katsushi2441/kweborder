<?php
/**
 * Kurage Web Order — 商社の管理画面（受注一覧・ステータス・CSV・取引先の承認・得意先別価格・商品）。
 */
declare(strict_types=1);
require __DIR__ . '/kwo_lib.php';
sess();
db();
$p = (string)($_GET['p'] ?? 'orders');
$NAV = [['admin.php?p=orders', '受注'], ['admin.php?p=companies', '取引先'], ['admin.php?p=products', '商品'], ['admin.php?p=logout', 'ログアウト']];
$HEAD = ['admin' => true, 'home' => 'admin.php', 'nav' => $NAV];

function is_admin(): bool { return !empty($_SESSION['admin']); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string)($_POST['a'] ?? '');
    try {
        if ($a === 'login') {
            if (too_many('admin')) { throw new RuntimeException('ログインの失敗が続いたため、15分ほど止めています'); }
            $ok = hash_equals((string)cfg('admin_user'), (string)$_POST['user'])
               && (cfg('admin_hash') ? password_verify((string)$_POST['pass'], (string)cfg('admin_hash')) : hash_equals((string)cfg('admin_pass'), (string)$_POST['pass']));
            if (!$ok) { fail('admin'); throw new RuntimeException('IDかパスワードが違います'); }
            session_regenerate_id(true); $_SESSION['admin'] = 1; go('admin.php');
        }
        if (!is_admin()) { go('admin.php'); }
        if ($a === 'status') {
            $oid = (int)$_POST['id']; $to = (string)$_POST['to'];
            set_status($oid, $to, 'admin', trim((string)($_POST['memo'] ?? '')), trim((string)($_POST['tracking'] ?? '')));
            $st = db()->prepare('SELECT o.no, o.tracking, u.email, u.name FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=?'); $st->execute([$oid]); $o = $st->fetch();
            $msg = ['accepted' => "ご注文 {$o['no']} を受注しました。出荷の手配を進めます。", 'preparing' => "ご注文 {$o['no']} の出荷を手配しています。",
                    'shipped' => "ご注文 {$o['no']} を出荷しました。" . ($o['tracking'] ? "送り状番号：{$o['tracking']}" : ''), 'declined' => "ご注文 {$o['no']} は、お受けできませんでした。詳しくはご連絡します。",
                    'cancelled' => "ご注文 {$o['no']} を取り消しました。"][$to] ?? '';
            if ($msg) { send_mail($o['email'], '【' . cfg('site') . '】' . KWO_STATUS[$to] . '（' . $o['no'] . '）', "{$o['name']} 様\n\n{$msg}\n" . base_url() . '?p=view&id=' . $oid); }
            flash('「' . KWO_STATUS[$to] . '」にしました。'); go('admin.php?p=order&id=' . $oid);
        }
        if ($a === 'bulk_accept') {
            $n = 0;
            foreach ((array)($_POST['ids'] ?? []) as $oid) { try { set_status((int)$oid, 'accepted', 'admin', 'まとめて受注'); $n++; } catch (Throwable $e) {} }
            flash($n . '件を受注済にしました。'); go('admin.php?p=orders&s=requested');
        }
        if ($a === 'company') {
            $id = (int)$_POST['id'];
            $st = (string)$_POST['status'];
            if (!in_array($st, ['pending', 'active', 'stopped'], true)) { throw new RuntimeException('状態が正しくありません'); }
            db()->prepare('UPDATE companies SET code=?, name=?, status=?, memo=?, approved=COALESCE(approved, CASE WHEN ?=\'active\' THEN ? END) WHERE id=?')
              ->execute([trim((string)$_POST['code']) ?: null, trim((string)$_POST['name']), $st, trim((string)$_POST['memo']), $st, now(), $id]);
            if ($st === 'active' && ($_POST['was'] ?? '') !== 'active') {
                $u = db()->prepare('SELECT email, name FROM users WHERE company_id=?'); $u->execute([$id]);
                foreach ($u as $r) { send_mail($r['email'], '【' . cfg('site') . '】ご注文いただけるようになりました', "{$r['name']} 様\n\n会社の登録を承認しました。ログインして品番で注文できます。\n" . base_url()); }
            }
            flash('取引先を更新しました。'); go('admin.php?p=company&id=' . $id);
        }
        if ($a === 'prices_csv' || $a === 'products_csv') {
            if (empty($_FILES['csv']['tmp_name'])) { throw new RuntimeException('CSVファイルを選んでください'); }
            $raw = file_get_contents($_FILES['csv']['tmp_name']);
            if (!mb_check_encoding($raw, 'UTF-8')) { $raw = mb_convert_encoding($raw, 'UTF-8', 'SJIS-win'); }
            $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
            $rows = array_map('str_getcsv', preg_split('/\r\n|\n|\r/', trim($raw)));
            array_shift($rows);   // 見出し行
            $n = 0;
            db()->beginTransaction();
            if ($a === 'prices_csv') {
                $cid = (int)$_POST['id'];
                if (!empty($_POST['replace'])) { db()->prepare('DELETE FROM prices WHERE company_id=?')->execute([$cid]); }
                $st = db()->prepare('INSERT OR REPLACE INTO prices VALUES(?,?,?)');
                foreach ($rows as $r) { if (count($r) >= 2 && trim($r[0]) !== '' && is_numeric(str_replace(',', '', $r[1]))) { $st->execute([$cid, trim($r[0]), (int)str_replace(',', '', $r[1])]); $n++; } }
                db()->commit(); flash($n . '件の得意先別価格を取り込みました。'); go('admin.php?p=company&id=' . $cid);
            }
            $st = db()->prepare('INSERT INTO products(code,name,unit,price,active) VALUES(?,?,?,?,?)
                                 ON CONFLICT(code) DO UPDATE SET name=excluded.name, unit=excluded.unit, price=excluded.price, active=excluded.active');
            foreach ($rows as $r) {
                if (count($r) < 2 || trim($r[0]) === '') { continue; }
                $price = isset($r[3]) && trim($r[3]) !== '' ? (int)str_replace(',', '', $r[3]) : null;
                $st->execute([trim($r[0]), trim($r[1]), trim($r[2] ?? ''), $price, isset($r[4]) && trim($r[4]) === '0' ? 0 : 1]); $n++;
            }
            db()->commit(); flash($n . '件の商品を取り込みました。'); go('admin.php?p=products');
        }
        if ($a === 'note') {
            db()->prepare('UPDATE orders SET admin_note=? WHERE id=?')->execute([trim((string)$_POST['admin_note']), (int)$_POST['id']]);
            flash('社内メモを保存しました。'); go('admin.php?p=order&id=' . (int)$_POST['id']);
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        flash($e instanceof RuntimeException ? $e->getMessage() : 'うまく処理できませんでした：' . $e->getMessage(), 'ng');
        go($_SERVER['HTTP_REFERER'] ?? 'admin.php');
    }
}

if ($p === 'logout') { unset($_SESSION['admin']); go('admin.php'); }
if (!is_admin()) {
    page_head('商社の管理画面｜' . cfg('site'), ['admin' => true, 'home' => 'admin.php']);
    echo '<h1>商社の管理画面</h1><div class="card" style="max-width:460px"><form method="post">' . csrf_field() . '<input type="hidden" name="a" value="login">'
       . '<label for="user">ID</label><input id="user" name="user" required' . (cfg('demo') ? ' value="admin"' : '') . '><label for="pass">パスワード</label><input id="pass" name="pass" type="password" required' . (cfg('demo') ? ' value="demo2026"' : '') . '>'
       . '<p style="margin-top:12px"><button class="btn">ログイン</button></p></form>' . (cfg('demo') ? '<p class="note">デモ：admin ／ demo2026（入力済み）。取引先の画面は <a href="./">こちら</a></p>' : '') . '</div>';
    page_foot(); exit;
}

/* ---------- CSV 書き出し ---------- */
if ($p === 'csv') {
    [$where, $args] = order_filter();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="weborder-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // Excel で文字化けしないよう BOM
    fputcsv($out, CSV_HEAD);
    foreach (csv_rows($where, $args) as $r) { fputcsv($out, $r); }
    exit;
}

function order_filter(): array
{
    $w = []; $a = [];
    if (($s = (string)($_GET['s'] ?? '')) !== '' && isset(KWO_STATUS[$s])) { $w[] = 'o.status=?'; $a[] = $s; }
    if (($c = (int)($_GET['c'] ?? 0)) > 0) { $w[] = 'o.company_id=?'; $a[] = $c; }
    if (($f = (string)($_GET['from'] ?? '')) !== '') { $w[] = 'o.created>=?'; $a[] = $f . ' 00:00:00'; }
    if (($t = (string)($_GET['to'] ?? '')) !== '') { $w[] = 'o.created<=?'; $a[] = $t . ' 23:59:59'; }
    if (($q = trim((string)($_GET['q'] ?? ''))) !== '') { $w[] = '(o.no LIKE ? OR o.customer_po LIKE ? OR o.ship_name LIKE ?)'; array_push($a, "%$q%", "%$q%", "%$q%"); }
    return [$w, $a];
}

if ($p === 'order') {
    $st = db()->prepare('SELECT o.*, c.name cname, c.code ccode, u.name uname, u.email uemail FROM orders o JOIN companies c ON c.id=o.company_id JOIN users u ON u.id=o.user_id WHERE o.id=?');
    $st->execute([(int)($_GET['id'] ?? 0)]); $o = $st->fetch();
    if (!$o) { http_response_code(404); page_head('見つかりません', $HEAD); echo '<h1>注文が見つかりません</h1>'; page_foot(); exit; }
    page_head('受注 ' . $o['no'] . '｜管理', $HEAD);
    echo '<p><a href="admin.php?p=orders">← 受注一覧</a></p><h1>受注 ' . h($o['no']) . ' ' . badge($o['status']) . '</h1>';
    echo '<div class="grid2"><div class="card"><b>' . h($o['cname']) . '</b>（' . h($o['ccode'] ?: 'コード未設定') . '）<br>注文者 ' . h($o['uname']) . '（' . h($o['uemail']) . '）<br>注文日時 ' . h($o['created'])
       . '<br>希望納期 ' . h($o['want_date'] ?: '—') . '<br>得意先の注文番号 ' . h($o['customer_po'] ?: '—') . '<br>備考 ' . h($o['note'] ?: '—') . '</div>'
       . '<div class="card"><b>出荷先</b><br>' . h($o['ship_name']) . '<br>〒' . h($o['ship_zip']) . ' ' . h($o['ship_addr']) . '<br>' . h($o['ship_tel']) . ($o['tracking'] ? '<br>送り状番号 <b>' . h($o['tracking']) . '</b>' : '') . '</div></div>';
    echo '<div class="card tw"><table><tr><th>行</th><th>品番</th><th>品名</th><th class="n">数量</th><th>単位</th><th class="n">単価</th><th>単価の出どころ</th><th class="n">金額</th></tr>';
    $cust = false;
    foreach (order_lines((int)$o['id']) as $l) {
        $cust = $cust || $l['price_src'] === 'customer';
        echo '<tr><td>' . (int)$l['line'] . '</td><td>' . h($l['code']) . '</td><td>' . h($l['name']) . '</td><td class="n">' . h($l['qty'] + 0) . '</td><td>' . h($l['unit']) . '</td><td class="n">' . yen($l['price']) . '</td><td class="' . ($l['price_src'] === 'customer' ? 'src-customer' : 'note') . '">' . h(PRICE_SRC[$l['price_src']] ?? '') . '</td><td class="n">' . yen($l['amount']) . '</td></tr>';
    }
    echo '<tr><th colspan="7" style="text-align:right">合計（税抜）</th><th class="n">' . yen($o['total']) . '</th></tr></table>'
       . ($cust ? '<p class="note src-customer">取引先が入力した単価があります。受注済にする前に確かめてください。</p>' : '') . '</div>';
    $next = KWO_NEXT[$o['status']] ?? [];
    if ($next) {
        echo '<h2>状態を進める</h2><div class="card"><form method="post" class="grid2">' . csrf_field() . '<input type="hidden" name="a" value="status"><input type="hidden" name="id" value="' . (int)$o['id'] . '">'
           . '<div><label for="memo">記録に残すメモ（任意・取引先にも見える）</label><input id="memo" name="memo"></div>'
           . (in_array('shipped', $next, true) ? '<div><label for="tracking">送り状番号（出荷済にするとき）</label><input id="tracking" name="tracking"></div>' : '<div></div>')
           . '<div class="row" style="grid-column:1/-1">';
        foreach ($next as $to) { echo '<button class="btn' . (in_array($to, ['cancelled', 'declined'], true) ? ' red' : '') . '" name="to" value="' . h($to) . '">' . h(KWO_STATUS[$to]) . 'にする</button>'; }
        echo '</div></form><p class="note">' . ($o['status'] === 'requested' ? '「受注済」にした時点で注文が成立し、取引先にメールが届きます。' : '状態を変えると取引先にメールが届きます。') . '</p></div>';
    }
    $lg = db()->prepare('SELECT * FROM status_log WHERE order_id=? ORDER BY at'); $lg->execute([$o['id']]);
    echo '<h2>状態の記録</h2><div class="card"><ul style="margin:0;padding-left:1.2em">';
    foreach ($lg as $g) { echo '<li>' . h($g['at']) . ' ' . badge($g['status']) . ' <span class="note">' . h(str_starts_with((string)$g['by_who'], 'customer') ? '取引先' : '商社') . '</span> ' . h($g['memo']) . '</li>'; }
    echo '</ul></div><h2>社内メモ（取引先には見えない）</h2><div class="card"><form method="post">' . csrf_field() . '<input type="hidden" name="a" value="note"><input type="hidden" name="id" value="' . (int)$o['id'] . '">'
       . '<textarea name="admin_note" rows="3" aria-label="社内メモ">' . h($o['admin_note']) . '</textarea><p><button class="btn ghost sm">保存</button></p></form></div>';
    page_foot(); exit;
}

if ($p === 'companies') {
    page_head('取引先｜管理', $HEAD);
    $rows = db()->query("SELECT c.*, (SELECT COUNT(*) FROM users u WHERE u.company_id=c.id) users, (SELECT COUNT(*) FROM orders o WHERE o.company_id=c.id) orders,
                         (SELECT COUNT(*) FROM prices x WHERE x.company_id=c.id) prices FROM companies c ORDER BY c.status='pending' DESC, c.id DESC")->fetchAll();
    $pend = count(array_filter($rows, fn($r) => $r['status'] === 'pending'));
    echo '<h1>取引先</h1>' . ($pend ? '<div class="flash ng">承認待ちの取引先が' . $pend . '社あります。</div>' : '') . '<div class="card tw"><table><tr><th>取引先コード</th><th>会社名</th><th>状態</th><th class="n">人</th><th class="n">注文</th><th class="n">得意先別価格</th><th>登録日</th></tr>';
    $lab = ['pending' => '承認待ち', 'active' => '利用中', 'stopped' => '停止'];
    foreach ($rows as $c) { echo '<tr><td>' . h($c['code'] ?: '—') . '</td><td><a href="admin.php?p=company&id=' . (int)$c['id'] . '">' . h($c['name']) . '</a></td><td>' . h($lab[$c['status']] ?? $c['status']) . '</td><td class="n">' . (int)$c['users'] . '</td><td class="n">' . (int)$c['orders'] . '</td><td class="n">' . (int)$c['prices'] . '品番</td><td>' . h(substr((string)$c['created'], 0, 10)) . '</td></tr>'; }
    echo '</table></div>';
    page_foot(); exit;
}

if ($p === 'company') {
    $st = db()->prepare('SELECT * FROM companies WHERE id=?'); $st->execute([(int)($_GET['id'] ?? 0)]); $c = $st->fetch();
    if (!$c) { go('admin.php?p=companies'); }
    page_head($c['name'] . '｜管理', $HEAD);
    echo '<p><a href="admin.php?p=companies">← 取引先</a></p><h1>' . h($c['name']) . '</h1><div class="grid2"><div class="card"><form method="post">' . csrf_field()
       . '<input type="hidden" name="a" value="company"><input type="hidden" name="id" value="' . (int)$c['id'] . '"><input type="hidden" name="was" value="' . h($c['status']) . '">'
       . '<label for="code">取引先コード（基幹の得意先コード）</label><input id="code" name="code" value="' . h($c['code']) . '">'
       . '<label for="name">会社名</label><input id="name" name="name" value="' . h($c['name']) . '">'
       . '<label for="status">状態</label><select id="status" name="status">';
    foreach (['pending' => '承認待ち（注文できない）', 'active' => '利用中（注文できる）', 'stopped' => '停止（注文できない）'] as $k => $v) { echo '<option value="' . $k . '"' . ($c['status'] === $k ? ' selected' : '') . '>' . $v . '</option>'; }
    echo '</select><label for="memo">社内メモ</label><input id="memo" name="memo" value="' . h($c['memo']) . '"><p style="margin-top:12px"><button class="btn">保存</button></p></form>'
       . '<p class="note">招待コード <b>' . h($c['invite_code']) . '</b>（同じ会社の人はこのコードで登録）。電話 ' . h($c['tel'] ?: '—') . '</p></div>';
    $u = db()->prepare('SELECT name, email, verified, last_login FROM users WHERE company_id=?'); $u->execute([$c['id']]);
    echo '<div class="card"><b>登録している人</b><ul style="padding-left:1.2em">';
    foreach ($u as $r) { echo '<li>' . h($r['name']) . '（' . h($r['email']) . '）' . ($r['verified'] ? '' : ' メール未確認') . '</li>'; }
    echo '</ul></div></div>';
    $pr = db()->prepare('SELECT x.code, x.price, p.name, p.price std FROM prices x LEFT JOIN products p ON p.code=x.code WHERE x.company_id=? ORDER BY x.code'); $pr->execute([$c['id']]);
    echo '<h2>得意先別の価格</h2><div class="card"><form method="post" enctype="multipart/form-data" class="row">' . csrf_field() . '<input type="hidden" name="a" value="prices_csv"><input type="hidden" name="id" value="' . (int)$c['id'] . '">'
       . '<input type="file" name="csv" accept=".csv,text/csv" style="max-width:320px" aria-label="得意先別価格のCSV"><label class="row" style="font-weight:400"><input type="checkbox" name="replace" value="1" style="width:auto"> いまの価格を消して入れ替える</label><button class="btn ghost sm">CSVを取り込む</button></form>'
       . '<p class="note">CSV は「品番,単価」の2列（1行目は見出し）。Shift_JIS でも読めます。登録の無い品番は標準単価、それも無ければ取引先が入力します。</p><div class="tw"><table><tr><th>品番</th><th>品名</th><th class="n">得意先別</th><th class="n">標準</th></tr>';
    foreach ($pr as $r) { echo '<tr><td>' . h($r['code']) . '</td><td>' . h($r['name'] ?? '（商品マスタに無い）') . '</td><td class="n">' . yen($r['price']) . '</td><td class="n">' . yen($r['std']) . '</td></tr>'; }
    echo '</table></div></div>';
    page_foot(); exit;
}

if ($p === 'products') {
    page_head('商品｜管理', $HEAD);
    echo '<h1>商品（品番）</h1><div class="card"><form method="post" enctype="multipart/form-data" class="row">' . csrf_field() . '<input type="hidden" name="a" value="products_csv">'
       . '<input type="file" name="csv" accept=".csv,text/csv" style="max-width:320px" aria-label="商品のCSV"><button class="btn ghost sm">CSVを取り込む</button></form>'
       . '<p class="note">CSV は「品番,品名,単位,標準単価,有効(1/0)」（1行目は見出し）。同じ品番は上書きします。標準単価を空にすると、得意先別の価格が無い取引先は単価を入力して注文します。</p></div>';
    $rows = db()->query('SELECT * FROM products ORDER BY code')->fetchAll();
    echo '<div class="card tw"><table><tr><th>品番</th><th>品名</th><th>単位</th><th class="n">標準単価</th><th>有効</th></tr>';
    foreach ($rows as $r) { echo '<tr><td>' . h($r['code']) . '</td><td>' . h($r['name']) . '</td><td>' . h($r['unit']) . '</td><td class="n">' . yen($r['price']) . '</td><td>' . ($r['active'] ? '○' : '—') . '</td></tr>'; }
    echo '</table></div>';
    page_foot(); exit;
}

/* ---------- 受注一覧（既定） ---------- */
page_head('受注一覧｜管理', $HEAD);
[$where, $args] = order_filter();
$sql = 'SELECT o.*, c.name cname, (SELECT COUNT(*) FROM order_lines l WHERE l.order_id=o.id) n,
        (SELECT COUNT(*) FROM order_lines l WHERE l.order_id=o.id AND l.price_src=\'customer\') cust
        FROM orders o JOIN companies c ON c.id=o.company_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY o.id DESC LIMIT 300';
$st = db()->prepare($sql); $st->execute($args); $rows = $st->fetchAll();
$cnt = db()->query('SELECT status, COUNT(*) n FROM orders GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
echo '<h1>受注一覧</h1><p class="row">';
foreach (KWO_STATUS as $k => $v) { echo '<a href="admin.php?p=orders&s=' . $k . '" style="text-decoration:none">' . badge($k) . ' ' . (int)($cnt[$k] ?? 0) . '</a>'; }
echo ' <a href="admin.php?p=orders">すべて</a></p>';
$cs = db()->query('SELECT id, name FROM companies ORDER BY name')->fetchAll();
echo '<form class="card filters" method="get"><input type="hidden" name="p" value="orders"><div><label for="s">状態</label><select id="s" name="s"><option value="">すべて</option>';
foreach (KWO_STATUS as $k => $v) { echo '<option value="' . $k . '"' . (($_GET['s'] ?? '') === $k ? ' selected' : '') . '>' . $v . '</option>'; }
echo '</select></div><div><label for="c">取引先</label><select id="c" name="c"><option value="">すべて</option>';
foreach ($cs as $c) { echo '<option value="' . (int)$c['id'] . '"' . ((int)($_GET['c'] ?? 0) === (int)$c['id'] ? ' selected' : '') . '>' . h($c['name']) . '</option>'; }
echo '</select></div><div><label for="from">注文日（から）</label><input id="from" type="date" name="from" value="' . h($_GET['from'] ?? '') . '"></div><div><label for="to">（まで）</label><input id="to" type="date" name="to" value="' . h($_GET['to'] ?? '') . '"></div>'
   . '<div><label for="q">番号・出荷先</label><input id="q" name="q" value="' . h($_GET['q'] ?? '') . '"></div><div><button class="btn sm">絞り込む</button></div>'
   . '<div><a class="btn ghost sm" href="admin.php?p=csv&' . h(http_build_query(array_intersect_key($_GET, array_flip(['s', 'c', 'from', 'to', 'q'])))) . '">この条件でCSV</a></div></form>';
echo '<form method="post">' . csrf_field() . '<input type="hidden" name="a" value="bulk_accept"><div class="card tw"><table><tr><th></th><th>受注番号</th><th>注文日時</th><th>状態</th><th>取引先</th><th>出荷先</th><th>希望納期</th><th class="n">行</th><th class="n">合計</th></tr>';
foreach ($rows as $o) {
    echo '<tr><td>' . ($o['status'] === 'requested' ? '<input type="checkbox" name="ids[]" value="' . (int)$o['id'] . '" style="width:auto" aria-label="選ぶ">' : '') . '</td><td><a href="admin.php?p=order&id=' . (int)$o['id'] . '">' . h($o['no']) . '</a></td><td>' . h($o['created']) . '</td><td>' . badge($o['status'])
       . ($o['cust'] ? ' <span class="src-customer" title="取引先が単価を入力した行あり">単価要確認</span>' : '') . '</td><td>' . h($o['cname']) . '</td><td>' . h($o['ship_name']) . '</td><td>' . h($o['want_date'] ?: '—') . '</td><td class="n">' . (int)$o['n'] . '</td><td class="n">' . yen($o['total']) . '</td></tr>';
}
echo '</table>' . (!$rows ? '<p class="note">該当する注文はありません。</p>' : '') . '</div><p><button class="btn ghost sm">チェックした注文をまとめて受注済にする</button></p></form>';
echo '<p class="note">CSV の列：' . h(implode('・', CSV_HEAD)) . '（明細1行が1行。BOM付き UTF-8 で Excel でも開けます）</p>';
page_foot();
