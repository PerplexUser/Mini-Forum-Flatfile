<?php
declare(strict_types=1);
session_start();

/**
 * Mini-Forum (Flatfile) - www.perplex.click
 * - Single file
 * - PHP >= 7.4 (empf. 8.x)
 * - Speichert Threads als JSON in data/threads/
 * - Schutz: CSRF, XSS, einfache Flood-Bremse, flock() beim Schreiben
 */

///////////////////////////
//   Konfiguration
///////////////////////////
const SITE_NAME        = 'Mini-Forum';
const DATA_DIR         = __DIR__ . '/data';
const THREAD_DIR       = DATA_DIR . '/threads';
const COUNTER_FILE     = DATA_DIR . '/counter.txt';
const MAX_TITLE_LEN    = 120;
const MAX_NAME_LEN     = 60;
const MAX_BODY_LEN     = 5000;  // Zeichen
const THREADS_PER_PAGE = 50;    // Anzeige auf der Startseite
const POST_COOLDOWN    = 10;    // Sekunden Flood-Bremse (pro Session)

header('Content-Type: text/html; charset=utf-8');

///////////////////////////
//   Bootstrap / Setup
///////////////////////////
function ensureDirs(): void {
    @mkdir(DATA_DIR, 0777, true);
    @mkdir(THREAD_DIR, 0777, true);
    @chmod(DATA_DIR, 0777);
    @chmod(THREAD_DIR, 0777);

    if (!is_dir(THREAD_DIR) || !is_writable(THREAD_DIR)) {
        http_response_code(500);
        echo '<h1>Fehler</h1><p>Das Verzeichnis <code>data/threads</code> existiert nicht oder ist nicht beschreibbar. Bitte anlegen und mit <code>chmod 777</code> freigeben (nur Demo!).</p>';
        exit;
    }
    if (!file_exists(COUNTER_FILE)) {
        file_put_contents(COUNTER_FILE, "0", LOCK_EX);
        @chmod(COUNTER_FILE, 0666);
    }
}
ensureDirs();

// CSRF
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Utilities
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function now(): int { return time(); }
function clientIp(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function ua(): string { return $_SERVER['HTTP_USER_AGENT'] ?? ''; }

function limitLen(string $s, int $max): string {
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8') : $s;
    }
    return strlen($s) > $max ? substr($s, 0, $max) : $s;
}

function formatText(string $text): string {
    // 1) Escapen
    $safe = h($text);

    // 2) URLs auto-verlinken
    $safe = preg_replace(
        '~(?:(?<=\s)|^)((https?://|ftp://)[^\s<]+)~i',
        '<a href="$1" rel="nofollow ugc noopener" target="_blank">$1</a>',
        $safe
    );

    // 3) Zeilenumbrüche erhalten
    return nl2br($safe);
}

function formatDateTime(int $ts): string {
    // Format: 31.12.2025 23:59
    return date('d.m.Y H:i', $ts);
}

function nextThreadId(): string {
    $fp = fopen(COUNTER_FILE, 'c+');
    if (!$fp) {
        throw new RuntimeException('Counter-Datei kann nicht geöffnet werden.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('Konnte Lock auf Counter-Datei nicht erhalten.');
    }
    rewind($fp);
    $raw = trim((string)stream_get_contents($fp));
    $n = ctype_digit($raw) ? (int)$raw : 0;
    $n++;
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, (string)$n);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return str_pad((string)$n, 8, '0', STR_PAD_LEFT);
}

function threadPath(string $id): string {
    return THREAD_DIR . "/thread_{$id}.json";
}

function loadThread(string $id): ?array {
    if (!preg_match('/^\d+$/', $id)) return null;
    $id = str_pad((string)(int)$id, 8, '0', STR_PAD_LEFT);
    $path = threadPath($id);
    if (!is_file($path)) return null;
    $json = file_get_contents($path);
    $data = json_decode((string)$json, true);
    if (!is_array($data)) return null;
    $data['id'] = $id;
    return $data;
}

function saveThread(array $thread): void {
    if (empty($thread['id'])) {
        throw new InvalidArgumentException('Thread-ID fehlt.');
    }
    $path = threadPath($thread['id']);
    $fp = fopen($path, 'c+');
    if (!$fp) {
        throw new RuntimeException('Thread-Datei kann nicht geöffnet werden.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('Konnte Lock auf Thread-Datei nicht erhalten.');
    }
    $json = json_encode($thread, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $json !== false ? $json : '{}');
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    @chmod($path, 0666);
}

function listThreads(): array {
    $files = glob(THREAD_DIR . '/thread_*.json');
    $items = [];
    foreach ($files as $file) {
        $raw = file_get_contents($file);
        $t = json_decode((string)$raw, true);
        if (!is_array($t) || empty($t['title'])) continue;
        $replies = isset($t['replies']) && is_array($t['replies']) ? $t['replies'] : [];
        $last = (int)($t['created_at'] ?? 0);
        if ($replies) {
            $lastReply = end($replies);
            if (is_array($lastReply) && !empty($lastReply['created_at'])) {
                $last = max($last, (int)$lastReply['created_at']);
            }
        }
        $items[] = [
            'id'         => $t['id'] ?? preg_replace('/\D/', '', basename($file)),
            'title'      => (string)$t['title'],
            'author'     => (string)($t['author'] ?? 'Anonym'),
            'created_at' => (int)($t['created_at'] ?? 0),
            'updated_at' => $last,
            'count'      => count($replies),
        ];
    }
    usort($items, function ($a, $b) {
        return $b['updated_at'] <=> $a['updated_at'];
    });
    return $items;
}

function floodGuard(): void {
    $last = $_SESSION['last_post'] ?? 0;
    if (now() - (int)$last < POST_COOLDOWN) {
        http_response_code(429);
        pageHeader('Zu schnell gepostet');
        echo '<main class="container"><p>Bitte warte ' . (POST_COOLDOWN) . ' Sekunden zwischen Beiträgen.</p><p><a href="board.php">Zurück</a></p></main>';
        pageFooter();
        exit;
    }
    $_SESSION['last_post'] = now();
}

///////////////////////////
//   Rendering
///////////////////////////
function pageHeader(string $title): void {
    $full = h($title) . ' — ' . h(SITE_NAME);
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . $full . '</title>';
    echo '<style>
:root { --bg:#ffffff; --fg:#111; --muted:#666; --card:#f7f7f7; --border:#ddd; }
@media (prefers-color-scheme: dark) {
  :root { --bg:#0b0b0b; --fg:#eee; --muted:#aaa; --card:#161616; --border:#2a2a2a; }
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial,sans-serif}
a{color:inherit}
.container{max-width:900px;margin:0 auto;padding:1rem}
nav{display:flex;gap:.75rem;align-items:center;border-bottom:1px solid var(--border);padding:.75rem 1rem}
nav .brand{font-weight:600}
button,.btn,input[type=submit]{cursor:pointer;border:1px solid var(--border);background:var(--card);padding:.5rem .8rem;border-radius:.4rem}
.card{background:var(--card);border:1px solid var(--border);border-radius:.6rem;padding:1rem;margin:.8rem 0}
.meta{color:var(--muted);font-size:.9rem}
h1,h2,h3{margin:.5rem 0 0}
.list{list-style:none;padding:0;margin:0}
.list li{border-bottom:1px solid var(--border);padding:.75rem 0}
.list li:last-child{border-bottom:none}
.title a{text-decoration:none}
form .row{margin:.6rem 0}
label{display:block;margin-bottom:.2rem}
input[type=text],textarea{width:100%;padding:.6rem;border:1px solid var(--border);border-radius:.4rem;background:transparent;color:inherit}
textarea{min-height:160px;resize:vertical}
.help{font-size:.85rem;color:var(--muted)}
.post{white-space:normal;word-wrap:break-word}
.reply{border-left:3px solid var(--border);padding-left:.8rem;margin:.8rem 0}
.footer{border-top:1px solid var(--border);padding:1rem;margin-top:2rem;color:var(--muted);font-size:.9rem}
    </style>';
    echo '</head><body>';
    echo '<nav><div class="container" style="display:flex;justify-content:space-between;align-items:center">';
    echo '<div><span class="brand">'.h(SITE_NAME).'</span></div>';
    echo '<div style="display:flex;gap:.5rem"><a class="btn" href="board.php">Übersicht</a><a class="btn" href="board.php?action=new">Neues Thema</a></div>';
    echo '</div></nav>';
}

function pageFooter(): void {
    echo '<footer class="footer"><div class="container">Flatfile-Demo · '.h(SITE_NAME).'</div></footer></body></html>';
}

///////////////////////////
//   Actions (POST first)
///////////////////////////
$action = $_GET['action'] ?? 'list';

// Neues Thema anlegen (POST)
if ($action === 'create_thread' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        pageHeader('Ungültiges CSRF-Token');
        echo '<main class="container"><p>Ungültiges CSRF-Token.</p></main>';
        pageFooter();
        exit;
    }
    // einfache Spamfalle (unsichtbares Feld)
    if (!empty($_POST['website'])) {
        http_response_code(400);
        pageHeader('Spam erkannt');
        echo '<main class="container"><p>Spam erkannt.</p></main>';
        pageFooter();
        exit;
    }
    floodGuard();

    $title = limitLen(trim((string)($_POST['title'] ?? '')), MAX_TITLE_LEN);
    $name  = limitLen(trim((string)($_POST['name'] ?? 'Anonym')), MAX_NAME_LEN);
    $body  = limitLen(trim((string)($_POST['body'] ?? '')), MAX_BODY_LEN);

    if ($title === '' || $body === '') {
        pageHeader('Fehlende Angaben');
        echo '<main class="container"><p>Bitte Titel und Nachricht ausfüllen.</p><p><a href="board.php?action=new">Zurück zum Formular</a></p></main>';
        pageFooter();
        exit;
    }

    $id = nextThreadId();
    $thread = [
        'id'         => $id,
        'title'      => $title,
        'author'     => $name === '' ? 'Anonym' : $name,
        'body'       => $body,
        'created_at' => now(),
        'updated_at' => now(),
        // sensible Felder nicht ausgeben
        '_ip'        => hash('sha256', clientIp()),
        '_ua'        => limitLen(ua(), 200),
        'replies'    => [],
    ];
    saveThread($thread);

    header('Location: board.php?action=view&id=' . urlencode($id));
    exit;
}

// Antwort posten (POST)
if ($action === 'post_reply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        pageHeader('Ungültiges CSRF-Token');
        echo '<main class="container"><p>Ungültiges CSRF-Token.</p></main>';
        pageFooter();
        exit;
    }
    if (!empty($_POST['website'])) {
        http_response_code(400);
        pageHeader('Spam erkannt');
        echo '<main class="container"><p>Spam erkannt.</p></main>';
        pageFooter();
        exit;
    }
    floodGuard();

    $id = (string)($_POST['id'] ?? '');
    $thread = loadThread($id);
    if (!$thread) {
        http_response_code(404);
        pageHeader('Thread nicht gefunden');
        echo '<main class="container"><p>Thread nicht gefunden.</p></main>';
        pageFooter();
        exit;
    }

    $name = limitLen(trim((string)($_POST['name'] ?? 'Anonym')), MAX_NAME_LEN);
    $body = limitLen(trim((string)($_POST['body'] ?? '')), MAX_BODY_LEN);

    if ($body === '') {
        pageHeader('Leere Antwort');
        echo '<main class="container"><p>Bitte eine Nachricht eingeben.</p></main>';
        pageFooter();
        exit;
    }

    $replies = isset($thread['replies']) && is_array($thread['replies']) ? $thread['replies'] : [];
    $replyId = count($replies) + 1;

    $replies[] = [
        'rid'        => $replyId,
        'author'     => $name === '' ? 'Anonym' : $name,
        'body'       => $body,
        'created_at' => now(),
        '_ip'        => hash('sha256', clientIp()),
        '_ua'        => limitLen(ua(), 200),
    ];

    $thread['replies']    = $replies;
    $thread['updated_at'] = now();

    saveThread($thread);

    header('Location: board.php?action=view&id=' . urlencode($thread['id']) . '#r' . $replyId);
    exit;
}

///////////////////////////
//   Views (GET)
///////////////////////////
if ($action === 'new') {
    pageHeader('Neues Thema');
    echo '<main class="container">';
    echo '<h1>Neues Thema erstellen</h1>';
    echo '<div class="card"><form method="post" action="board.php?action=create_thread" autocomplete="off" novalidate>';
    echo '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">';
    echo '<div class="row" style="display:none"><label>Website<input type="text" name="website" value=""></label></div>'; // Honeypot
    echo '<div class="row"><label for="title">Titel</label><input type="text" id="title" name="title" maxlength="'.MAX_TITLE_LEN.'" required></div>';
    echo '<div class="row"><label for="name">Name (optional)</label><input type="text" id="name" name="name" maxlength="'.MAX_NAME_LEN.'" placeholder="Anonym"></div>';
    echo '<div class="row"><label for="body">Nachricht</label><textarea id="body" name="body" maxlength="'.MAX_BODY_LEN.'" required></textarea></div>';
    echo '<p class="help">Hinweis: Links werden automatisch erkannt. HTML ist deaktiviert.</p>';
    echo '<div class="row"><input type="submit" class="btn" value="Thema erstellen"></div>';
    echo '</form></div>';
    echo '</main>';
    pageFooter();
    exit;
}

if ($action === 'view') {
    $id = (string)($_GET['id'] ?? '');
    $thread = loadThread($id);
    if (!$thread) {
        http_response_code(404);
        pageHeader('Thread nicht gefunden');
        echo '<main class="container"><p>Thread nicht gefunden.</p><p><a href="board.php">Zur Übersicht</a></p></main>';
        pageFooter();
        exit;
    }
    pageHeader(h($thread['title']));
    echo '<main class="container">';
    echo '<h1>'.h($thread['title']).'</h1>';
    echo '<div class="meta">Erstellt von '.h($thread['author'] ?? 'Anonym').' · '.formatDateTime((int)$thread['created_at']).'</div>';
    echo '<article class="card post">'.formatText((string)$thread['body']).'</article>';

    // Antworten
    $replies = isset($thread['replies']) && is_array($thread['replies']) ? $thread['replies'] : [];
    if ($replies) {
        echo '<h2>'.count($replies).' Antworten</h2>';
        foreach ($replies as $r) {
            $rid = (int)($r['rid'] ?? 0);
            echo '<section class="card reply" id="r'.$rid.'">';
            echo '<div class="meta">#'.$rid.' · '.h($r['author'] ?? 'Anonym').' · '.formatDateTime((int)($r['created_at'] ?? 0)).'</div>';
            echo '<div class="post">'.formatText((string)($r['body'] ?? '')).'</div>';
            echo '</section>';
        }
    } else {
        echo '<p class="meta">Noch keine Antworten.</p>';
    }

    // Antwortformular
    echo '<div class="card"><h2>Antworten</h2>';
    echo '<form method="post" action="board.php?action=post_reply" autocomplete="off" novalidate>';
    echo '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">';
    echo '<input type="hidden" name="id" value="'.h($thread['id']).'">';
    echo '<div class="row" style="display:none"><label>Website<input type="text" name="website" value=""></label></div>'; // Honeypot
    echo '<div class="row"><label for="name">Name (optional)</label><input type="text" id="name" name="name" maxlength="'.MAX_NAME_LEN.'" placeholder="Anonym"></div>';
    echo '<div class="row"><label for="body">Nachricht</label><textarea id="body" name="body" maxlength="'.MAX_BODY_LEN.'" required></textarea></div>';
    echo '<p class="help">Bitte höflich bleiben. HTML ist deaktiviert, Links werden erkannt.</p>';
    echo '<div class="row"><input type="submit" class="btn" value="Antwort senden"></div>';
    echo '</form></div>';

    echo '</main>';
    pageFooter();
    exit;
}

// Übersicht (Default)
if ($action === 'list') {
    $threads = listThreads();
    pageHeader('Übersicht');
    echo '<main class="container">';
    echo '<h1>Aktuelle Themen</h1>';
    if (!$threads) {
        echo '<p>Es gibt noch keine Themen. <a href="board.php?action=new">Jetzt eines erstellen</a>.</p>';
    } else {
        echo '<ul class="list">';
        $i = 0;
        foreach ($threads as $t) {
            if ($i++ >= THREADS_PER_PAGE) break;
            echo '<li>';
            echo '<div class="title"><a href="board.php?action=view&id='.h($t['id']).'">'.h($t['title']).'</a></div>';
            echo '<div class="meta">Von '.h($t['author']).' · gestartet am '.formatDateTime($t['created_at']).' · letzte Aktivität '.formatDateTime($t['updated_at']).' · Antworten: '.(int)$t['count'].'</div>';
            echo '</li>';
        }
        echo '</ul>';
    }
    echo '</main>';
    pageFooter();
    exit;
}

// Fallback
http_response_code(400);
pageHeader('Unbekannte Aktion');
echo '<main class="container"><p>Unbekannte Aktion.</p></main>';
pageFooter();
