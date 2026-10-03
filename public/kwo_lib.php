<?php
/**
 * Kurage Web Order — 共通部品（DB・ログイン・メール・画面の枠）。
 *
 * PHP 8 と SQLite だけで動く BtoB の WEB 受注。レンタルサーバーに置けば動く（常駐プロセスなし）。
 * 設定は同じフォルダの kwo_config.php（無ければ既定値）。
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');   // 注文日時は日本時間で記録する

$KWO = [
    'site'        => 'Kurage Web Order',
    'seller'      => '株式会社クラゲ食材（デモ）',      // 受注する側（商社）の名前
    'db'          => __DIR__ . '/kwo_data/kweborder.sqlite',
    'admin_user'  => 'admin',
    'admin_hash'  => '',                               // password_hash() の値。空なら admin_pass を使う
    'admin_pass'  => 'demo2026',
    'mail_from'   => 'no-reply@example.com',
    'admin_mail'  => '',                               // 新しい注文・会社登録を知らせる先（空なら送らない）
    'demo'        => true,                             // true: メールを送らず画面に出す・データを1日で初期化
    'public_url'  => '',
    'track_js'    => '',
];
if (is_file(__DIR__ . '/kwo_config.php')) { $KWO = array_merge($KWO, (array)require __DIR__ . '/kwo_config.php'); }

const KWO_STATUS = [
    'requested' => '注文受付',
    'accepted'  => '受注済',
    'preparing' => '出荷手配中',
    'shipped'   => '出荷済',
    'done'      => '完了',
    'cancelled' => 'キャンセル',
    'declined'  => 'お断り',
];
/** ステータスごとに、次に進められる先 */
const KWO_NEXT = [
    'requested' => ['accepted', 'declined', 'cancelled'],
    'accepted'  => ['preparing', 'cancelled'],
    'preparing' => ['shipped', 'cancelled'],
    'shipped'   => ['done'],
    'done'      => [],
    'cancelled' => [],
    'declined'  => [],
];

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function cfg(string $k) { global $KWO; return $KWO[$k] ?? null; }
function now(): string { return date('Y-m-d H:i:s'); }
function yen($n): string { return $n === null || $n === '' ? '—' : number_format((float)$n) . '円'; }

function db(): PDO
{
    static $pdo = null;
    if ($pdo) { return $pdo; }
    $f = cfg('db');
    if (!is_dir(dirname($f))) { mkdir(dirname($f), 0775, true); }
    $new = !is_file($f);
    $pdo = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL;');
    if ($new) { schema($pdo); seed_demo($pdo); }
    elseif (cfg('demo')) { demo_reset_if_old($pdo); }
    return $pdo;
}

function schema(PDO $p): void
{
    $p->exec(<<<SQL
CREATE TABLE IF NOT EXISTS meta(k TEXT PRIMARY KEY, v TEXT);
CREATE TABLE IF NOT EXISTS companies(
  id INTEGER PRIMARY KEY, code TEXT UNIQUE, name TEXT NOT NULL, tel TEXT, status TEXT NOT NULL DEFAULT 'pending',
  invite_code TEXT UNIQUE, created TEXT, approved TEXT, memo TEXT);
CREATE TABLE IF NOT EXISTS users(
  id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL REFERENCES companies(id), email TEXT UNIQUE NOT NULL,
  pass_hash TEXT NOT NULL, name TEXT NOT NULL, verified INTEGER NOT NULL DEFAULT 0, verify_token TEXT,
  reset_token TEXT, reset_exp TEXT, active INTEGER NOT NULL DEFAULT 1, created TEXT, last_login TEXT);
CREATE TABLE IF NOT EXISTS products(
  code TEXT PRIMARY KEY, name TEXT NOT NULL, unit TEXT, price INTEGER, active INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS prices(
  company_id INTEGER NOT NULL REFERENCES companies(id), code TEXT NOT NULL, price INTEGER NOT NULL, PRIMARY KEY(company_id, code));
CREATE TABLE IF NOT EXISTS shipto(
  id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL REFERENCES companies(id), label TEXT, name TEXT NOT NULL,
  zip TEXT, addr TEXT NOT NULL, tel TEXT, is_default INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS orders(
  id INTEGER PRIMARY KEY, no TEXT UNIQUE, company_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
  ship_name TEXT, ship_zip TEXT, ship_addr TEXT, ship_tel TEXT, want_date TEXT, customer_po TEXT, note TEXT,
  status TEXT NOT NULL, total INTEGER, created TEXT, updated TEXT, tracking TEXT, admin_note TEXT, source TEXT DEFAULT 'web');
CREATE TABLE IF NOT EXISTS order_lines(
  order_id INTEGER NOT NULL REFERENCES orders(id), line INTEGER NOT NULL, code TEXT, name TEXT, qty REAL, unit TEXT,
  price INTEGER, price_src TEXT, amount INTEGER, PRIMARY KEY(order_id, line));
CREATE TABLE IF NOT EXISTS status_log(order_id INTEGER, status TEXT, at TEXT, by_who TEXT, memo TEXT);
CREATE TABLE IF NOT EXISTS login_fail(k TEXT, at TEXT);
SQL);
}

function rand_code(int $n = 8): string
{
    $c = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < $n; $i++) { $s .= $c[random_int(0, strlen($c) - 1)]; }
    return $s;
}

/** デモ用の商品・取引先・注文。会社名と品目は架空。 */
function seed_demo(PDO $p): void
{
    $p->exec("INSERT OR REPLACE INTO meta VALUES('seeded','" . now() . "')");
    $items = [
        ['A-101', '有機しょうゆ 1L', '本', 680], ['A-102', '有機しょうゆ 500ml', '本', 380], ['A-205', '米みそ 750g', '個', 540],
        ['A-206', '麦みそ 750g', '個', 560], ['B-310', '本みりん 500ml', '本', 420], ['B-311', '料理酒 1L', '本', 390],
        ['C-012', '昆布だし 10袋入', '箱', 380], ['C-013', 'かつおだし 10袋入', '箱', 400], ['D-440', 'かつお節 500g', '袋', 1850],
        ['D-441', '削り節 100g', '袋', 520], ['E-501', '米酢 900ml', '本', 330], ['E-502', '黒酢 500ml', '本', 780],
        ['F-610', '上白糖 1kg', '袋', 260], ['F-611', 'てんさい糖 650g', '袋', 410], ['G-720', '天日塩 1kg', '袋', 480],
        ['H-830', 'ごま油 600g', '缶', 890], ['H-831', '菜種油 1000g', '本', 620], ['J-901', '乾しいたけ 100g', '袋', 1280],
    ];
    $st = $p->prepare('INSERT INTO products(code,name,unit,price) VALUES(?,?,?,?)');
    foreach ($items as $i) { $st->execute($i); }
    $p->prepare("INSERT INTO companies(code,name,tel,status,invite_code,created,approved) VALUES(?,?,?,?,?,?,?)")
      ->execute(['C0001', '株式会社みなと食品', '052-000-1234', 'active', 'MINATO24', now(), now()]);
    $cid = (int)$p->lastInsertId();
    $p->prepare('INSERT INTO users(company_id,email,pass_hash,name,verified,created) VALUES(?,?,?,?,1,?)')
      ->execute([$cid, 'demo@example.com', password_hash('demo2026', PASSWORD_DEFAULT), '佐藤 花子', now()]);
    $uid = (int)$p->lastInsertId();
    foreach ([['A-101', 640], ['A-205', 500], ['C-012', 350], ['D-440', 1780]] as $pr) {
        $p->prepare('INSERT INTO prices VALUES(?,?,?)')->execute([$cid, $pr[0], $pr[1]]);
    }
    $p->prepare('INSERT INTO shipto(company_id,label,name,zip,addr,tel,is_default) VALUES(?,?,?,?,?,?,1)')
      ->execute([$cid, '本社倉庫', '株式会社みなと食品 本社倉庫', '455-0000', '愛知県名古屋市港区港町1-2-3', '052-000-1234']);
    $p->prepare('INSERT INTO shipto(company_id,label,name,zip,addr,tel,is_default) VALUES(?,?,?,?,?,?,0)')
      ->execute([$cid, '第二倉庫', '株式会社みなと食品 第二倉庫', '455-0001', '愛知県名古屋市港区港町4-5-6', '052-000-1236']);
    // 見本の注文を2件
    create_order($cid, $uid, 1, [['A-101', 24], ['C-012', 30]], '2026-10-10', 'PO-1017', '午前中の納品を希望します。');
    $o2 = create_order($cid, $uid, 2, [['D-440', 3], ['H-830', 6]], '', '', '');
    set_status($o2, 'accepted', '商社（デモ）', '');
}

function demo_reset_if_old(PDO $p): void
{
    $s = $p->query("SELECT v FROM meta WHERE k='seeded'")->fetchColumn();
    if ($s && strtotime((string)$s) < time() - 86400) {
        $f = cfg('db');
        $p = null;
        foreach ([$f, $f . '-wal', $f . '-shm'] as $x) { if (is_file($x)) { @unlink($x); } }
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? './'));
        exit;
    }
}

/* ---------------- セッション・CSRF ---------------- */
function sess(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    session_name('kwo');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https']);
    session_start();
}
function csrf(): string { sess(); if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); } return $_SESSION['csrf']; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">'; }
function csrf_check(): void
{
    sess();
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('画面を開き直してから、もう一度送ってください（フォームの有効期限切れ）。'); }
}
function flash(string $msg, string $kind = 'ok'): void { sess(); $_SESSION['flash'][] = [$kind, $msg]; }
function go(string $url): void { header('Location: ' . $url); exit; }

/** 総当たりを防ぐ。同じキーで15分に10回失敗したら止める */
function too_many(string $k): bool
{
    db()->prepare("DELETE FROM login_fail WHERE at < ?")->execute([date('Y-m-d H:i:s', time() - 900)]);
    $st = db()->prepare('SELECT COUNT(*) FROM login_fail WHERE k=?'); $st->execute([$k]);
    return (int)$st->fetchColumn() >= 10;
}
function fail(string $k): void { db()->prepare('INSERT INTO login_fail VALUES(?,?)')->execute([$k, now()]); }

/* ---------------- 取引先ユーザー ---------------- */
function me(): ?array
{
    sess();
    if (empty($_SESSION['uid'])) { return null; }
    $st = db()->prepare('SELECT u.*, c.name AS company, c.code AS company_code, c.status AS company_status, c.invite_code
                         FROM users u JOIN companies c ON c.id=u.company_id WHERE u.id=? AND u.active=1');
    $st->execute([$_SESSION['uid']]);
    return $st->fetch() ?: null;
}

/* ---------------- メール ---------------- */
/** デモでは送らず、画面に「本番ならこのメールが届く」として出す */
function send_mail(string $to, string $subject, string $body): void
{
    if (cfg('demo')) {
        flash("（デモのためメールは送っていません。本番では {$to} に次のメールが届きます）\n件名：{$subject}\n\n{$body}", 'mail');
        return;
    }
    mb_language('Japanese'); mb_internal_encoding('UTF-8');
    @mb_send_mail($to, $subject, $body . "\n\n-- \n" . cfg('seller') . "\n", 'From: ' . cfg('mail_from'));
}
function base_url(): string
{
    if (cfg('public_url')) { return rtrim((string)cfg('public_url'), '/') . '/'; }
    $proto = (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}

/* ---------------- 価格・注文 ---------------- */
/** 単価を決める：得意先別の価格 → 標準単価 → （どちらも無ければ）null＝得意先が入力 */
function price_for(int $company_id, string $code): array
{
    $st = db()->prepare('SELECT price FROM prices WHERE company_id=? AND code=?'); $st->execute([$company_id, $code]);
    $v = $st->fetchColumn();
    if ($v !== false) { return [(int)$v, 'master']; }
    $st = db()->prepare('SELECT price FROM products WHERE code=?'); $st->execute([$code]);
    $v = $st->fetchColumn();
    if ($v !== false && $v !== null) { return [(int)$v, 'standard']; }
    return [null, 'customer'];
}
const PRICE_SRC = ['master' => '得意先別', 'standard' => '標準', 'customer' => '得意先入力'];

/**
 * 注文を作る。$lines = [[品番, 数量, (得意先が入れた単価)], ...]
 * 品番が商品マスタに無い行も受ける（品名は品番のまま・単価は得意先入力）。商社が確認して受注済にする。
 */
function create_order(int $cid, int $uid, int $shipto_id, array $lines, string $want, string $po, string $note, string $source = 'web'): int
{
    $p = db();
    $st = $p->prepare('SELECT * FROM shipto WHERE id=? AND company_id=?'); $st->execute([$shipto_id, $cid]);
    $sh = $st->fetch();
    if (!$sh) { throw new RuntimeException('出荷先を選んでください'); }
    $p->beginTransaction();
    $no = date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    $p->prepare('INSERT INTO orders(no,company_id,user_id,ship_name,ship_zip,ship_addr,ship_tel,want_date,customer_po,note,status,created,updated,source)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$no, $cid, $uid, $sh['name'], $sh['zip'], $sh['addr'], $sh['tel'], $want, $po, $note, 'requested', now(), now(), $source]);
    $oid = (int)$p->lastInsertId();
    $total = 0; $n = 0;
    $pst = $p->prepare('SELECT name, unit FROM products WHERE code=? AND active=1');
    foreach ($lines as $l) {
        $code = trim((string)$l[0]); $qty = (float)$l[1];
        if ($code === '' || $qty <= 0) { continue; }
        $pst->execute([$code]); $pr = $pst->fetch() ?: ['name' => '（商品マスタに無い品番）', 'unit' => ''];
        [$price, $src] = price_for($cid, $code);
        if ($price === null && isset($l[2]) && $l[2] !== '' && is_numeric($l[2])) { $price = (int)$l[2]; }
        $amount = $price === null ? null : (int)round($price * $qty);
        $total += (int)$amount;
        $p->prepare('INSERT INTO order_lines VALUES(?,?,?,?,?,?,?,?,?)')
          ->execute([$oid, ++$n, $code, $pr['name'], $qty, $pr['unit'], $price, $src, $amount]);
    }
    if ($n === 0) { $p->rollBack(); throw new RuntimeException('品番と数量を1行以上入れてください'); }
    $p->prepare('UPDATE orders SET total=? WHERE id=?')->execute([$total, $oid]);
    $p->prepare('INSERT INTO status_log VALUES(?,?,?,?,?)')->execute([$oid, 'requested', now(), 'customer:' . $uid, '']);
    $p->commit();
    return $oid;
}

function set_status(int $oid, string $to, string $by, string $memo, string $tracking = ''): void
{
    $p = db();
    $st = $p->prepare('SELECT status FROM orders WHERE id=?'); $st->execute([$oid]);
    $from = (string)$st->fetchColumn();
    if (!in_array($to, KWO_NEXT[$from] ?? [], true)) { throw new RuntimeException('その状態には進められません'); }
    $p->prepare('UPDATE orders SET status=?, updated=?' . ($tracking !== '' ? ', tracking=?' : '') . ' WHERE id=?')
      ->execute($tracking !== '' ? [$to, now(), $tracking, $oid] : [$to, now(), $oid]);
    $p->prepare('INSERT INTO status_log VALUES(?,?,?,?,?)')->execute([$oid, $to, now(), $by, $memo]);
}

function order_lines(int $oid): array
{
    $st = db()->prepare('SELECT * FROM order_lines WHERE order_id=? ORDER BY line'); $st->execute([$oid]);
    return $st->fetchAll();
}

/** CSV の列（この順・この名前で固定。基幹への取り込みはこの形に合わせてもらう） */
const CSV_HEAD = ['受注番号', '注文日時', '状態', '受注日時', '取引先コード', '取引先名', '注文者', '注文者メール', '得意先注文番号',
    '出荷先名', '出荷先郵便番号', '出荷先住所', '出荷先電話', '希望納期', '行', '品番', '品名', '数量', '単位', '単価', '単価の出どころ', '金額', '注文合計', '備考', '送り状番号'];

function csv_rows(array $where = [], array $args = []): Generator
{
    $sql = "SELECT o.*, c.code ccode, c.name cname, u.name uname, u.email uemail,
              (SELECT at FROM status_log s WHERE s.order_id=o.id AND s.status='accepted' ORDER BY at DESC LIMIT 1) accepted_at
            FROM orders o JOIN companies c ON c.id=o.company_id JOIN users u ON u.id=o.user_id"
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY o.created';
    $st = db()->prepare($sql); $st->execute($args);
    foreach ($st as $o) {
        foreach (order_lines((int)$o['id']) as $l) {
            yield [$o['no'], $o['created'], KWO_STATUS[$o['status']] ?? $o['status'], $o['accepted_at'] ?? '', $o['ccode'], $o['cname'], $o['uname'], $o['uemail'],
                $o['customer_po'], $o['ship_name'], $o['ship_zip'], $o['ship_addr'], $o['ship_tel'], $o['want_date'], $l['line'], $l['code'], $l['name'],
                $l['qty'] + 0, $l['unit'], $l['price'], PRICE_SRC[$l['price_src']] ?? $l['price_src'], $l['amount'], $o['total'], $o['note'], $o['tracking']];
        }
    }
}

/* ---------------- 画面の枠 ---------------- */
function page_head(string $title, array $o = []): void
{
    sess();
    $noindex = $o['noindex'] ?? true;
    $desc = $o['desc'] ?? '取引先がメールアドレスで登録して、品番で注文し、出荷先を選べるBtoBのWEB受注システム。商社側は受注・出荷のステータスを管理してCSVで基幹に取り込めます。';
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title><meta name="description" content="' . h($desc) . '">';
    if ($noindex) { echo '<meta name="robots" content="noindex">'; }
    if (!empty($o['og'])) {
        $u = h(base_url());
        echo '<link rel="canonical" href="' . $u . '"><meta property="og:type" content="website"><meta property="og:title" content="' . h($title) . '">'
           . '<meta property="og:description" content="' . h($desc) . '"><meta property="og:url" content="' . $u . '"><meta property="og:image" content="' . $u . 'kwo_ogp.png">'
           . '<meta property="og:locale" content="ja_JP"><meta name="twitter:card" content="summary_large_image">';
        if (!empty($o['jsonld'])) { echo '<script type="application/ld+json">' . json_encode($o['jsonld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>'; }
    }
    echo '<link rel="icon" href="kwo_favicon.png"><style>' . css() . '</style>' . (string)cfg('track_js') . '</head><body>';
    $me = $o['admin'] ?? false ? null : me();
    echo '<header class="top"><div class="in"><a class="brand" href="' . h($o['home'] ?? './') . '"><img src="https://exbridge.jp/images/logo-mark-128.png" width="28" height="28" alt="">'
       . '<span>' . h(cfg('site')) . '<small>' . h($o['admin'] ?? false ? '商社の管理画面' : cfg('seller')) . '</small></span></a><nav>';
    foreach ($o['nav'] ?? [] as [$href, $label]) { echo '<a href="' . h($href) . '">' . h($label) . '</a>'; }
    echo '</nav></div></header><main class="wrap">';
    if (cfg('demo')) {
        echo '<p class="demo">これはデモです。会社名・商品は架空で、登録したデータは1日で消えます。メールは送らず画面に表示します。</p>';
    }
    foreach ($_SESSION['flash'] ?? [] as [$k, $m]) { echo '<div class="flash ' . h($k) . '">' . nl2br(h($m)) . '</div>'; }
    unset($_SESSION['flash']);
}
function page_foot(): void
{
    echo '</main><footer><div class="in">' . h(cfg('site')) . '（BtoB の WEB 受注・オンプレミス）／提供 <a href="https://exbridge.jp/">株式会社エクスブリッジ</a></div></footer></body></html>';
}
function badge(string $s): string { return '<span class="st st-' . h($s) . '">' . h(KWO_STATUS[$s] ?? $s) . '</span>'; }

function css(): string
{
    return <<<CSS
:root{--ink:#14222f;--mut:#5d6b7a;--line:#dde6ec;--bg:#f4f7f9;--teal:#0a8f85;--teal-d:#07756d;--teal-l:#e6f5f3;--red:#c23a2b;--amber:#fff6dc}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.7 "Noto Sans JP","Hiragino Kaku Gothic ProN",Meiryo,sans-serif}
a{color:var(--teal-d)}header.top{background:#fff;border-bottom:1px solid var(--line)}
.in{max-width:1100px;margin:0 auto;padding:10px 16px;display:flex;align-items:center;gap:8px 16px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:8px;text-decoration:none;color:var(--ink);font-weight:800}.brand small{display:block;font-size:11px;color:var(--mut);font-weight:600}
header nav{margin-left:auto;display:flex;gap:4px 14px;flex-wrap:wrap;font-size:14px}header nav a{text-decoration:none;font-weight:700}
.wrap{max-width:1100px;margin:0 auto;padding:18px 16px 40px}
h1{font-size:22px;margin:6px 0 12px}h2{font-size:17px;margin:20px 0 10px}
.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:16px;margin:0 0 14px}
.demo{background:var(--amber);border:1px solid #ecd9a6;border-radius:10px;padding:8px 12px;font-size:13px;margin:0 0 12px}
.flash{border-radius:10px;padding:10px 12px;margin:0 0 12px;font-size:14px}.flash.ok{background:var(--teal-l)}.flash.ng{background:#fdecea;color:var(--red)}.flash.mail{background:#eef3fb;border:1px dashed #9db5d9;font-size:13px}
label{display:block;font-weight:700;font-size:13px;margin:8px 0 3px}
input,select,textarea{font:inherit;padding:8px 10px;border:1px solid var(--line);border-radius:8px;width:100%;max-width:100%;background:#fff}
.btn{display:inline-block;background:var(--teal);color:#fff;border:0;border-radius:10px;padding:10px 18px;font:inherit;font-weight:800;cursor:pointer;text-decoration:none}
.btn.ghost{background:#fff;color:var(--teal-d);border:1px solid var(--line)}.btn.sm{padding:6px 12px;font-size:13px}.btn.red{background:var(--red)}
.btn:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid #f2b705;outline-offset:1px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}@media(max-width:720px){.grid2{grid-template-columns:1fr}}
.tw{overflow-x:auto}table{border-collapse:collapse;width:100%;font-size:14px}th,td{border-bottom:1px solid var(--line);padding:7px 8px;text-align:left;vertical-align:top}
th{background:#f1f5f7;font-size:12.5px;white-space:nowrap}td.n{text-align:right;font-variant-numeric:tabular-nums}
table.lines input{padding:6px 8px}table.lines{min-width:620px}
.st{display:inline-block;border-radius:999px;padding:1px 10px;font-size:12px;font-weight:700;background:#eef3f6;white-space:nowrap}
.st-requested{background:#fff1c9}.st-accepted{background:#dff3ee}.st-preparing{background:#dfeafb}.st-shipped{background:#e7e2fb}.st-done{background:#e9eef1;color:#556}.st-cancelled,.st-declined{background:#fbe3e0;color:var(--red)}
.note{color:var(--mut);font-size:13px}.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.filters{display:flex;gap:8px;flex-wrap:wrap;align-items:end}.filters>div{min-width:140px}
footer{border-top:1px solid var(--line);background:#fff;font-size:12.5px;color:var(--mut)}
.src-customer{color:#a05a00;font-weight:700}
CSS;
}
