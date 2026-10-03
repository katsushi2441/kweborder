<?php
/**
 * Kurage Web Order — 取引先の画面（登録・ログイン・品番で注文・出荷先・注文履歴・同じ会社の人）。
 * 商社の画面は admin.php。
 */
declare(strict_types=1);
require __DIR__ . '/kwo_lib.php';
sess();
db();
$p = (string)($_GET['p'] ?? '');
$me = me();
$NAV_IN = [['./?p=order', '品番で注文'], ['./?p=history', '注文履歴'], ['./?p=shipto', '出荷先'], ['./?p=members', '会社の人'], ['./?p=logout', 'ログアウト']];
$NAV_OUT = [['./', 'ログイン'], ['./?p=register', '新規登録']];

/* ---------- 品番の候補（JSON） ---------- */
if ($p === 'suggest') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$me || $me['company_status'] !== 'active') { echo '[]'; exit; }
    $q = trim((string)($_GET['q'] ?? ''));
    $st = db()->prepare("SELECT code, name, unit FROM products WHERE active=1 AND (code LIKE ? OR name LIKE ?) ORDER BY code LIMIT 12");
    $st->execute([$q . '%', '%' . $q . '%']);
    $out = [];
    foreach ($st as $r) { [$pr, $src] = price_for((int)$me['company_id'], $r['code']); $out[] = $r + ['price' => $pr, 'src' => $src]; }
    echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
}

/* ---------- POST ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string)($_POST['a'] ?? '');
    try {
        if ($a === 'login') {
            $email = strtolower(trim((string)$_POST['email']));
            $key = 'u:' . $email;
            if (too_many($key)) { throw new RuntimeException('ログインの失敗が続いたため、15分ほど止めています'); }
            $st = db()->prepare('SELECT * FROM users WHERE email=? AND active=1'); $st->execute([$email]);
            $u = $st->fetch();
            if (!$u || !password_verify((string)$_POST['pass'], $u['pass_hash'])) { fail($key); throw new RuntimeException('メールアドレスかパスワードが違います'); }
            if (!$u['verified']) { throw new RuntimeException('メールアドレスの確認がまだです。登録のときに届いたメールのリンクを開いてください'); }
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            db()->prepare('UPDATE users SET last_login=? WHERE id=?')->execute([now(), $u['id']]);
            go('./?p=order');
        }
        if ($a === 'register') {
            $email = strtolower(trim((string)$_POST['email']));
            $name = trim((string)$_POST['name']);
            $pass = (string)$_POST['pass'];
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('メールアドレスの形が正しくありません'); }
            if ($name === '') { throw new RuntimeException('お名前を入れてください'); }
            if (strlen($pass) < 8) { throw new RuntimeException('パスワードは8文字以上にしてください'); }
            $st = db()->prepare('SELECT 1 FROM users WHERE email=?'); $st->execute([$email]);
            if ($st->fetchColumn()) { throw new RuntimeException('このメールアドレスは登録済みです。パスワードを忘れた場合は再設定してください'); }
            $invite = strtoupper(trim((string)($_POST['invite'] ?? '')));
            if ($invite !== '') {
                $st = db()->prepare('SELECT id FROM companies WHERE invite_code=?'); $st->execute([$invite]);
                $cid = (int)$st->fetchColumn();
                if (!$cid) { throw new RuntimeException('招待コードが見つかりません。同じ会社ですでに使っている人に確かめてください'); }
            } else {
                $cname = trim((string)$_POST['company']);
                if ($cname === '') { throw new RuntimeException('会社名を入れるか、招待コードを入れてください'); }
                db()->prepare('INSERT INTO companies(name,tel,status,invite_code,created) VALUES(?,?,?,?,?)')
                  ->execute([$cname, trim((string)($_POST['tel'] ?? '')), 'pending', rand_code(8), now()]);
                $cid = (int)db()->lastInsertId();
                if (cfg('admin_mail')) { send_mail((string)cfg('admin_mail'), '【WEB受注】新しい取引先の登録：' . $cname, "会社名：{$cname}\n登録者：{$name}（{$email}）\n管理画面で承認してください。\n" . base_url() . 'admin.php?p=companies'); }
            }
            $tok = bin2hex(random_bytes(16));
            db()->prepare('INSERT INTO users(company_id,email,pass_hash,name,verify_token,created) VALUES(?,?,?,?,?,?)')
              ->execute([$cid, $email, password_hash($pass, PASSWORD_DEFAULT), $name, $tok, now()]);
            send_mail($email, '【' . cfg('site') . '】メールアドレスの確認', "{$name} 様\n\n次のリンクを開くと登録が完了します。\n" . base_url() . '?p=verify&t=' . $tok);
            flash('確認のメールを送りました。メールのリンクを開くと登録が完了します。');
            go('./');
        }
        if ($a === 'forgot') {
            $email = strtolower(trim((string)$_POST['email']));
            $st = db()->prepare('SELECT id FROM users WHERE email=? AND active=1'); $st->execute([$email]);
            if ($uid = $st->fetchColumn()) {
                $tok = bin2hex(random_bytes(16));
                db()->prepare('UPDATE users SET reset_token=?, reset_exp=? WHERE id=?')->execute([$tok, date('Y-m-d H:i:s', time() + 3600), $uid]);
                send_mail($email, '【' . cfg('site') . '】パスワードの再設定', "次のリンクから、1時間以内に新しいパスワードを決めてください。\n" . base_url() . '?p=reset&t=' . $tok);
            }
            flash('登録があれば、再設定のメールを送りました。');   // 登録の有無を答えない
            go('./');
        }
        if ($a === 'reset') {
            $st = db()->prepare('SELECT id FROM users WHERE reset_token=? AND reset_exp>=?'); $st->execute([(string)$_POST['t'], now()]);
            $uid = $st->fetchColumn();
            if (!$uid) { throw new RuntimeException('リンクの有効期限が切れています。もう一度再設定を申し込んでください'); }
            if (strlen((string)$_POST['pass']) < 8) { throw new RuntimeException('パスワードは8文字以上にしてください'); }
            db()->prepare('UPDATE users SET pass_hash=?, reset_token=NULL, reset_exp=NULL, verified=1 WHERE id=?')->execute([password_hash((string)$_POST['pass'], PASSWORD_DEFAULT), $uid]);
            flash('パスワードを変えました。ログインしてください。'); go('./');
        }
        if (!$me) { go('./'); }
        if ($a === 'order') {
            if ($me['company_status'] !== 'active') { throw new RuntimeException('会社の登録が承認されてから注文できます'); }
            $lines = [];
            foreach ((array)($_POST['code'] ?? []) as $i => $code) {
                $lines[] = [$code, (float)str_replace(',', '', (string)($_POST['qty'][$i] ?? '0')), $_POST['price'][$i] ?? ''];
            }
            $oid = create_order((int)$me['company_id'], (int)$me['id'], (int)$_POST['shipto'], $lines,
                trim((string)$_POST['want']), trim((string)$_POST['po']), trim((string)$_POST['note']), 'web', (string)($_POST['free_ship'] ?? ''));
            $st = db()->prepare('SELECT no FROM orders WHERE id=?'); $st->execute([$oid]); $no = $st->fetchColumn();
            send_mail($me['email'], '【' . cfg('site') . '】ご注文を受け付けました（' . $no . '）', "{$me['name']} 様\n\nご注文を受け付けました。{$no}\n商社が内容を確かめて「受注済」にした時点で注文が成立します。\n" . base_url() . '?p=view&id=' . $oid);
            if (cfg('admin_mail')) { send_mail((string)cfg('admin_mail'), '【WEB受注】新しい注文 ' . $no . '（' . $me['company'] . '）', base_url() . 'admin.php?p=order&id=' . $oid); }
            flash('注文を受け付けました（' . $no . '）。商社が受注済にすると成立します。'); go('./?p=view&id=' . $oid);
        }
        if ($a === 'shipto_add') {
            $name = trim((string)$_POST['name']); $addr = trim((string)$_POST['addr']);
            if ($name === '' || $addr === '') { throw new RuntimeException('出荷先名と住所を入れてください'); }
            db()->prepare('INSERT INTO shipto(company_id,label,name,zip,addr,tel) VALUES(?,?,?,?,?,?)')
              ->execute([$me['company_id'], trim((string)$_POST['label']), $name, trim((string)$_POST['zip']), $addr, trim((string)$_POST['tel'])]);
            flash('出荷先を登録しました。'); go('./?p=shipto');
        }
        if ($a === 'shipto_default' || $a === 'shipto_del') {
            $sid = (int)$_POST['id'];
            if ($a === 'shipto_default') {
                db()->prepare('UPDATE shipto SET is_default=(id=?) WHERE company_id=?')->execute([$sid, $me['company_id']]);
            } else {
                db()->prepare('DELETE FROM shipto WHERE id=? AND company_id=?')->execute([$sid, $me['company_id']]);
            }
            go('./?p=shipto');
        }
        if ($a === 'cancel') {
            $oid = (int)$_POST['id'];
            $st = db()->prepare("SELECT status FROM orders WHERE id=? AND company_id=?"); $st->execute([$oid, $me['company_id']]);
            if ($st->fetchColumn() !== 'requested') { throw new RuntimeException('受注済になった注文は、ここでは取り消せません。商社に連絡してください'); }
            set_status($oid, 'cancelled', 'customer:' . $me['id'], '取引先が取り消し');
            flash('注文を取り消しました。'); go('./?p=view&id=' . $oid);
        }
    } catch (Throwable $e) {
        flash($e instanceof RuntimeException ? $e->getMessage() : 'うまく処理できませんでした。もう一度お試しください', 'ng');
        go($_SERVER['HTTP_REFERER'] ?? './');
    }
}

/* ---------- GET ---------- */
if ($p === 'logout') { $_SESSION = []; session_destroy(); go('./'); }
if ($p === 'verify') {
    $st = db()->prepare('SELECT id FROM users WHERE verify_token=?'); $st->execute([(string)($_GET['t'] ?? '')]);
    if ($uid = $st->fetchColumn()) {
        db()->prepare('UPDATE users SET verified=1, verify_token=NULL WHERE id=?')->execute([$uid]);
        flash('メールアドレスを確認しました。ログインしてください。');
    } else { flash('確認のリンクが古いか、使用済みです。', 'ng'); }
    go('./');
}

if (!$me) {
    if ($p === 'register') {
        page_head('新規登録｜' . cfg('site'), ['nav' => $NAV_OUT]);
        echo '<h1>取引先の新規登録</h1><div class="card" style="max-width:640px"><form method="post">' . csrf_field() . '<input type="hidden" name="a" value="register">'
           . '<label for="email">メールアドレス（ログインIDになります）</label><input id="email" name="email" type="email" required autocomplete="email">'
           . '<label for="name">お名前</label><input id="name" name="name" required>'
           . '<label for="pass">パスワード（8文字以上）</label><input id="pass" name="pass" type="password" minlength="8" required autocomplete="new-password">'
           . '<h2>会社</h2><p class="note">同じ会社の人がすでに使っていれば、その人から<b>招待コード</b>を聞いて入れてください。同じ会社として注文履歴と出荷先を共有します。初めての会社は会社名を入れてください（商社の承認後に注文できます）。</p>'
           . '<label for="invite">招待コード</label><input id="invite" name="invite" placeholder="例：MINATO24" autocomplete="off">'
           . '<p class="note" style="margin:10px 0 0">— または —</p>'
           . '<label for="company">会社名（初めての会社）</label><input id="company" name="company">'
           . '<label for="tel">会社の電話</label><input id="tel" name="tel" type="tel">'
           . '<p style="margin-top:14px"><button class="btn">確認のメールを送る</button></p></form></div>';
        page_foot(); exit;
    }
    if ($p === 'forgot') {
        page_head('パスワードの再設定｜' . cfg('site'), ['nav' => $NAV_OUT]);
        echo '<h1>パスワードの再設定</h1><div class="card" style="max-width:520px"><form method="post">' . csrf_field() . '<input type="hidden" name="a" value="forgot">'
           . '<label for="email">登録したメールアドレス</label><input id="email" name="email" type="email" required><p style="margin-top:12px"><button class="btn">再設定のメールを送る</button></p></form></div>';
        page_foot(); exit;
    }
    if ($p === 'reset') {
        page_head('新しいパスワード｜' . cfg('site'), ['nav' => $NAV_OUT]);
        echo '<h1>新しいパスワード</h1><div class="card" style="max-width:520px"><form method="post">' . csrf_field() . '<input type="hidden" name="a" value="reset"><input type="hidden" name="t" value="' . h($_GET['t'] ?? '') . '">'
           . '<label for="pass">新しいパスワード（8文字以上）</label><input id="pass" name="pass" type="password" minlength="8" required autocomplete="new-password"><p style="margin-top:12px"><button class="btn">変更する</button></p></form></div>';
        page_foot(); exit;
    }
    // ログイン（トップ）
    $ld = ['@context' => 'https://schema.org', '@graph' => [
        ['@type' => 'SoftwareApplication', 'name' => 'Kurage Web Order', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'PHP 8 / SQLite',
         'description' => '取引先がメールアドレスで登録し、品番で注文・出荷先を選べるBtoBのWEB受注システム。受注ステータス管理とCSV書き出しつき。',
         'publisher' => ['@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/']],
        ['@type' => 'FAQPage', 'mainEntity' => [
            ['@type' => 'Question', 'name' => '取引先はどうやって使い始めますか？', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'メールアドレスをIDにして自分で登録します。初めての会社は商社が承認してから注文でき、同じ会社の2人目からは招待コードで同じ会社に入ります。']],
            ['@type' => 'Question', 'name' => '注文はいつ成立しますか？', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => '取引先が注文すると「注文受付」になり、商社が内容を確かめて「受注済」にした時点で成立し、出荷手配に進みます。']],
            ['@type' => 'Question', 'name' => '取引先ごとに価格を変えられますか？', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => '得意先別の価格を登録すればそれを使い、無ければ標準単価、どちらも無い品番は取引先が単価を入力します。どの単価を使ったかは受注に記録されます。']],
        ]]]];
    page_head('Kurage Web Order｜品番で注文できるBtoBのWEB受注（デモ）', ['nav' => $NAV_OUT, 'noindex' => false, 'og' => true, 'jsonld' => $ld]);
    if (cfg('demo')) {
        // デモの入口を2つ：発注する得意先の画面と、受注する商社の画面
        echo '<h1>どちらの立場で試しますか？</h1><div class="grid2">'
           . '<div class="card"><h2 style="margin-top:0">得意先（発注する側）として試す</h2><p class="note">品番で注文・出荷先を選ぶ・注文履歴を見る。デモの取引先「株式会社みなと食品」の佐藤さんとしてログインします。</p>'
           . '<form method="post">' . csrf_field() . '<input type="hidden" name="a" value="login"><input type="hidden" name="email" value="demo@example.com"><input type="hidden" name="pass" value="demo2026"><button class="btn">得意先として試す</button></form>'
           . '<p class="note" style="margin-top:8px">demo@example.com ／ demo2026</p></div>'
           . '<div class="card"><h2 style="margin-top:0">商社（受注する側）として試す</h2><p class="note">受注一覧・受注済にする・出荷済にする・CSV・得意先の代わりに注文を登録（代理入力）・取引先と価格の管理。</p>'
           . '<form method="post" action="admin.php">' . csrf_field() . '<input type="hidden" name="a" value="login"><input type="hidden" name="user" value="admin"><input type="hidden" name="pass" value="demo2026"><button class="btn">商社として試す</button></form>'
           . '<p class="note" style="margin-top:8px">admin ／ demo2026</p></div></div>'
           . '<p class="note">新しい会社として登録する流れを試すなら <a href="./?p=register">新規登録</a>（デモではメールを送らず、確認のリンクを画面に出します）。</p>'
           . (cfg('buy_url') ? '<p><a class="btn ghost" href="' . h(cfg('buy_url')) . '">自社のサーバーに置く導入版を見る（Kurage App Store）</a></p>' : '');
    }
    echo '<div class="grid2"><div><h2>取引先の方のログイン</h2><div class="card"><form method="post">' . csrf_field() . '<input type="hidden" name="a" value="login">'
       . '<label for="email">メールアドレス</label><input id="email" name="email" type="email" required autocomplete="email">'
       . '<label for="pass">パスワード</label><input id="pass" name="pass" type="password" required autocomplete="current-password">'
       . '<p style="margin-top:12px" class="row"><button class="btn">ログイン</button> <a href="./?p=forgot">パスワードを忘れた</a></p></form></div>'
       . '<p>はじめての方は <a class="btn ghost sm" href="./?p=register">新規登録</a></p></div>'
       . '<div><h2>このシステムでできること</h2><div class="card"><ul style="margin:0;padding-left:1.2em">'
       . '<li>メールアドレスをIDにして、取引先が自分で登録（同じ会社の人は招待コードで）</li>'
       . '<li>品番と数量を並べて注文。品番は途中まで打つと候補が出る</li>'
       . '<li>出荷先は登録したものから選ぶか、その場で自由に書く</li>'
       . '<li>得意先別の価格 → 標準単価 → 取引先が入力、の順で単価を決める</li>'
       . '<li>商社が「受注済」にした時点で成立。出荷手配中・出荷済まで状態が見える</li>'
       . '<li>FAX・電話の注文は、商社が得意先の代わりに登録（代理入力）</li>'
       . '<li>商社側は受注一覧・ステータス管理・CSVで基幹へ</li></ul></div></div></div>';
    page_foot(); exit;
}

$cid = (int)$me['company_id'];
$pending = $me['company_status'] !== 'active';

if ($p === 'shipto') {
    page_head('出荷先｜' . cfg('site'), ['nav' => $NAV_IN]);
    $st = db()->prepare('SELECT * FROM shipto WHERE company_id=? ORDER BY is_default DESC, id'); $st->execute([$cid]);
    echo '<h1>出荷先</h1><p class="note">' . h($me['company']) . 'の出荷先です。同じ会社の人と共有します。</p><div class="card tw"><table><tr><th>名前</th><th>出荷先</th><th>住所</th><th>電話</th><th></th></tr>';
    foreach ($st as $s) {
        echo '<tr><td>' . h($s['label']) . ($s['is_default'] ? ' <span class="st st-accepted">いつもの</span>' : '') . '</td><td>' . h($s['name']) . '</td><td>〒' . h($s['zip']) . ' ' . h($s['addr']) . '</td><td>' . h($s['tel']) . '</td><td class="row">'
           . ($s['is_default'] ? '' : '<form method="post">' . csrf_field() . '<input type="hidden" name="a" value="shipto_default"><input type="hidden" name="id" value="' . (int)$s['id'] . '"><button class="btn ghost sm">いつもの出荷先にする</button></form>')
           . '<form method="post" onsubmit="return confirm(\'この出荷先を消しますか？\')">' . csrf_field() . '<input type="hidden" name="a" value="shipto_del"><input type="hidden" name="id" value="' . (int)$s['id'] . '"><button class="btn ghost sm">消す</button></form></td></tr>';
    }
    echo '</table></div><h2>出荷先を足す</h2><div class="card"><form method="post" class="grid2">' . csrf_field() . '<input type="hidden" name="a" value="shipto_add">'
       . '<div><label for="label">呼び名（例：第二倉庫）</label><input id="label" name="label"></div><div><label for="sname">出荷先名（宛名）</label><input id="sname" name="name" required></div>'
       . '<div><label for="zip">郵便番号</label><input id="zip" name="zip"></div><div><label for="tel">電話</label><input id="tel" name="tel"></div>'
       . '<div style="grid-column:1/-1"><label for="addr">住所</label><input id="addr" name="addr" required></div><div><button class="btn">登録する</button></div></form></div>';
    page_foot(); exit;
}

if ($p === 'members') {
    page_head('会社の人｜' . cfg('site'), ['nav' => $NAV_IN]);
    $st = db()->prepare('SELECT name, email, verified, last_login FROM users WHERE company_id=? AND active=1 ORDER BY id'); $st->execute([$cid]);
    echo '<h1>' . h($me['company']) . 'の人</h1><div class="card"><p>同じ会社の人を増やすときは、この<b>招待コード</b>を伝えて、新規登録の画面で入れてもらってください。</p>'
       . '<p style="font-size:22px;font-weight:800;letter-spacing:.08em">' . h($me['invite_code']) . '</p></div><div class="card tw"><table><tr><th>お名前</th><th>メールアドレス</th><th>最後のログイン</th></tr>';
    foreach ($st as $u) { echo '<tr><td>' . h($u['name']) . '</td><td>' . h($u['email']) . ($u['verified'] ? '' : '（確認待ち）') . '</td><td>' . h($u['last_login'] ?? '—') . '</td></tr>'; }
    echo '</table></div>';
    page_foot(); exit;
}

if ($p === 'history' || $p === 'view') {
    if ($p === 'view') {
        $st = db()->prepare('SELECT o.*, u.name uname FROM orders o LEFT JOIN users u ON u.id=o.user_id WHERE o.id=? AND o.company_id=?'); $st->execute([(int)($_GET['id'] ?? 0), $cid]);
        $o = $st->fetch();
        if (!$o) { http_response_code(404); page_head('見つかりません', ['nav' => $NAV_IN]); echo '<h1>注文が見つかりません</h1>'; page_foot(); exit; }
        page_head('注文 ' . $o['no'] . '｜' . cfg('site'), ['nav' => $NAV_IN]);
        echo '<h1>注文 ' . h($o['no']) . ' ' . badge($o['status']) . '</h1><div class="grid2"><div class="card"><b>出荷先</b><br>' . h($o['ship_name']) . '<br>〒' . h($o['ship_zip']) . ' ' . h($o['ship_addr']) . '<br>' . h($o['ship_tel']) . '</div>'
           . '<div class="card">注文日時 ' . h($o['created']) . '<br>注文者 ' . h($o['uname'] ?? '商社が代理入力（' . (SOURCE_LABEL[$o['source']] ?? '') . '）') . '<br>希望納期 ' . h($o['want_date'] ?: '—') . '<br>御社の注文番号 ' . h($o['customer_po'] ?: '—')
           . (ship_text($o) !== '' ? '<br><b>' . h(ship_text($o)) . '</b>' : '') . '<br>備考 ' . h($o['note'] ?: '—') . '</div></div>';
        echo '<div class="card tw"><table><tr><th>行</th><th>品番</th><th>品名</th><th class="n">数量</th><th>単位</th><th class="n">単価</th><th>単価の出どころ</th><th class="n">金額</th></tr>';
        foreach (order_lines((int)$o['id']) as $l) {
            echo '<tr><td>' . (int)$l['line'] . '</td><td>' . h($l['code']) . '</td><td>' . h($l['name']) . '</td><td class="n">' . h($l['qty'] + 0) . '</td><td>' . h($l['unit']) . '</td><td class="n">' . yen($l['price']) . '</td><td class="' . ($l['price_src'] === 'customer' ? 'src-customer' : 'note') . '">' . h(PRICE_SRC[$l['price_src']] ?? '') . '</td><td class="n">' . yen($l['amount']) . '</td></tr>';
        }
        echo '<tr><th colspan="7" style="text-align:right">合計（税抜）</th><th class="n">' . yen($o['total']) . '</th></tr></table></div>';
        $lg = db()->prepare('SELECT * FROM status_log WHERE order_id=? ORDER BY at'); $lg->execute([$o['id']]);
        echo '<h2>状態の記録</h2><div class="card"><ul style="margin:0;padding-left:1.2em">';
        foreach ($lg as $g) { echo '<li>' . h($g['at']) . ' ' . badge($g['status']) . ($g['memo'] ? ' ' . h($g['memo']) : '') . '</li>'; }
        echo '</ul></div>';
        if ($o['status'] === 'requested') {
            echo '<form method="post" onsubmit="return confirm(\'この注文を取り消しますか？\')">' . csrf_field() . '<input type="hidden" name="a" value="cancel"><input type="hidden" name="id" value="' . (int)$o['id'] . '"><button class="btn ghost">注文を取り消す</button> <span class="note">商社が受注済にする前まで取り消せます。</span></form>';
        } elseif (in_array($o['status'], ['accepted', 'preparing'], true)) {
            echo '<p class="note">受注済になった注文は、ここでは取り消せません。取り消しや変更は商社に連絡してください（出荷前なら商社がキャンセルできます）。</p>';
        }
        page_foot(); exit;
    }
    page_head('注文履歴｜' . cfg('site'), ['nav' => $NAV_IN]);
    $st = db()->prepare('SELECT o.*, u.name uname, (SELECT COUNT(*) FROM order_lines l WHERE l.order_id=o.id) n FROM orders o LEFT JOIN users u ON u.id=o.user_id WHERE o.company_id=? ORDER BY o.id DESC LIMIT 200'); $st->execute([$cid]);
    echo '<h1>注文履歴（' . h($me['company']) . '）</h1><div class="card tw"><table><tr><th>受注番号</th><th>注文日時</th><th>状態</th><th>注文者</th><th>出荷先</th><th>出荷予定日</th><th class="n">行</th><th class="n">合計</th></tr>';
    foreach ($st as $o) { echo '<tr><td><a href="./?p=view&id=' . (int)$o['id'] . '">' . h($o['no']) . '</a></td><td>' . h($o['created']) . '</td><td>' . badge($o['status']) . '</td><td>' . h($o['uname'] ?? '商社が代理入力') . '</td><td>' . h($o['ship_name']) . '</td><td>' . h($o['ship_date'] ?: '—') . '</td><td class="n">' . (int)$o['n'] . '</td><td class="n">' . yen($o['total']) . '</td></tr>'; }
    echo '</table></div>';
    page_foot(); exit;
}

/* ---------- 品番で注文（ログイン後の既定） ---------- */
page_head('品番で注文｜' . cfg('site'), ['nav' => $NAV_IN]);
echo '<h1>品番で注文</h1><p class="note">' . h($me['company']) . '／' . h($me['name']) . ' さん</p>';
if ($pending) {
    echo '<div class="flash ng">会社の登録を商社が確認しています。承認されると注文できるようになります。</div>';
    page_foot(); exit;
}
$st = db()->prepare('SELECT * FROM shipto WHERE company_id=? ORDER BY is_default DESC, id'); $st->execute([$cid]);
$ships = $st->fetchAll();
echo '<form method="post" id="of">' . csrf_field() . '<input type="hidden" name="a" value="order">' . lines_ui('./?p=suggest', '単価の決まっていない品番は、単価を入れてください（商社が確認します）。');
echo '<div class="grid2"><div class="card">' . shipto_ui($ships, './?p=shipto') . '</div><div class="card"><label for="want">希望納期</label><input id="want" name="want" type="date">'
   . '<label for="po">御社の注文番号（任意）</label><input id="po" name="po"><label for="note">備考</label><textarea id="note" name="note" rows="2"></textarea></div></div>';
echo '<p><button class="btn">この内容で注文する</button> <span class="note">商社が「受注済」にした時点で注文が成立します。</span></p></form>';
page_foot();
