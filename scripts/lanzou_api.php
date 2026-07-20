<?php
/**
 * 蓝奏云完整 API 封装
 * 基于 zaxtyson/LanZouCloud-API (Python) 和 hanximeng/LanzouAPI (PHP) 实现
 * 
 * 用法:
 *   php lanzou_api.php <action> [options...]
 * 
 * 认证: 从 ~/.lanzou_cookie.json 读取 Cookie
 */

error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

// ==================== 配置 ====================
$LANZOU_HOST = 'https://pan.lanzouo.com';
$LANZOU_DOUUPLOAD = 'https://pc.woozooo.com/doupload.php';
$LANZOU_ACCOUNT = 'https://pc.woozooo.com/account.php';
$LANZOU_MYDISK = 'https://pc.woozooo.com/mydisk.php';
$LANZOU_FILEUP = 'https://pc.woozooo.com/fileup.php';

$BACKUP_DOMAINS = [
    'lanzouw.com',
    'lanzoui.com', 
    'lanzoux.com',
    'lanzouo.com',
];

$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

// 允许上传的文件后缀
$VALID_SUFFIXES = explode(',', 'ppt,xapk,ke,azw,cpk,gho,dwg,db,docx,deb,e,ttf,xls,bat,crx,rpm,txf,pdf,apk,ipa,txt,mobi,osk,dmg,rp,osz,jar,ttc,z,w3x,xlsx,cetrainer,ct,rar,mp3,pptx,mobileconfig,epub,imazingapp,doc,iso,img,appimage,7z,rplib,lolgezi,exe,azw3,zip,conf,tar,dll,flac,xpa,lua,cad,hwt,accdb,ce,xmind,enc,bds,bdi,ssf,it,gz');

// ==================== 全局状态 ====================
$COOKIE_FILE = getenv('HOME') . '/.lanzou_cookie.json';
$GLOBAL_COOKIE = '';
$GLOBAL_UID = 0;

// ==================== 工具函数 ====================

function json_out($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

function json_err($code, $msg) {
    json_out(['code' => $code, 'msg' => $msg]);
    exit(1);
}

function load_cookie() {
    global $COOKIE_FILE, $GLOBAL_COOKIE, $GLOBAL_UID;
    if (!file_exists($COOKIE_FILE)) {
        return false;
    }
    $data = json_decode(file_get_contents($COOKIE_FILE), true);
    if (!$data || empty($data['ylogin']) || empty($data['phpdisk_info'])) {
        return false;
    }
    $GLOBAL_COOKIE = "ylogin={$data['ylogin']}; phpdisk_info={$data['phpdisk_info']}";
    $GLOBAL_UID = intval($data['ylogin']);
    return true;
}

function save_cookie($ylogin, $phpdisk_info) {
    global $COOKIE_FILE;
    $dir = dirname($COOKIE_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($COOKIE_FILE, json_encode([
        'ylogin' => $ylogin,
        'phpdisk_info' => $phpdisk_info,
    ], JSON_PRETTY_PRINT));
}

function rand_ip() {
    $prefixes = [218,66,60,202,204,59,61,222,221,122,211];
    $ip1 = $prefixes[array_rand($prefixes)];
    $ip2 = mt_rand(1, 255);
    $ip3 = mt_rand(1, 255);
    $ip4 = mt_rand(1, 255);
    return "{$ip1}.{$ip2}.{$ip3}.{$ip4}";
}

function calc_acw_sc__v2($arg1) {
    $posList = [15,35,29,24,33,16,1,38,10,9,19,31,40,27,22,23,25,13,6,11,39,18,20,8,14,21,32,26,2,30,7,4,17,5,3,28,34,37,12,36];
    $mask = '3000176000856006061501533003690027800375';
    $outPutList = array_fill(0, 40, '');
    for ($i = 0; $i < strlen($arg1); $i++) {
        $char = $arg1[$i];
        foreach ($posList as $j => $pos) {
            if ($pos == $i + 1) {
                $outPutList[$j] = $char;
            }
        }
    }
    $arg2 = implode('', $outPutList);
    $result = '';
    $length = min(strlen($arg2), strlen($mask));
    for ($i = 0; $i < $length; $i += 2) {
        $strHex = substr($arg2, $i, 2);
        $maskHex = substr($mask, $i, 2);
        $xorResult = dechex(hexdec($strHex) ^ hexdec($maskHex));
        $result .= str_pad($xorResult, 2, '0', STR_PAD_LEFT);
    }
    return $result;
}

function curl_get($url, $extra_headers = []) {
    global $GLOBAL_COOKIE, $UA;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING => 'gzip',
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => $UA,
        CURLOPT_COOKIE => $GLOBAL_COOKIE,
        CURLOPT_HTTPHEADER => array_merge([
            'Accept-Language: zh-CN,zh;q=0.9',
            'X-FORWARDED-FOR: ' . rand_ip(),
            'CLIENT-IP: ' . rand_ip(),
        ], $extra_headers),
    ]);
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    return ['body' => $resp, 'info' => $info];
}

function curl_post($url, $data, $extra_headers = [], $referer = '') {
    global $GLOBAL_COOKIE, $UA;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => $UA,
        CURLOPT_COOKIE => $GLOBAL_COOKIE,
        CURLOPT_HTTPHEADER => array_merge([
            'Accept-Language: zh-CN,zh;q=0.9',
            'X-FORWARDED-FOR: ' . rand_ip(),
            'CLIENT-IP: ' . rand_ip(),
        ], $extra_headers),
    ]);
    if ($referer) {
        curl_setopt($ch, CURLOPT_REFERER, $referer);
    }
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    return ['body' => $resp, 'info' => $info];
}

function curl_post_multipart($url, $fields, $extra_headers = [], $referer = '') {
    global $GLOBAL_COOKIE, $UA;
    $boundary = '----WebKitFormBoundary' . md5(uniqid());
    $body = '';
    foreach ($fields as $key => $val) {
        if (is_array($val) && count($val) == 3) {
            // 文件字段: [filename, content, mime]
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$key}\"; filename=\"{$val[0]}\"\r\n";
            $body .= "Content-Type: {$val[2]}\r\n\r\n";
            $body .= $val[1] . "\r\n";
        } else {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$key}\"\r\n\r\n";
            $body .= $val . "\r\n";
        }
    }
    $body .= "--{$boundary}--\r\n";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 3600,
        CURLOPT_USERAGENT => $UA,
        CURLOPT_COOKIE => $GLOBAL_COOKIE,
        CURLOPT_HTTPHEADER => array_merge([
            "Content-Type: multipart/form-data; boundary={$boundary}",
            'Accept-Language: zh-CN,zh;q=0.9',
            'X-FORWARDED-FOR: ' . rand_ip(),
            'CLIENT-IP: ' . rand_ip(),
        ], $extra_headers),
    ]);
    if ($referer) {
        curl_setopt($ch, CURLOPT_REFERER, $referer);
    }
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    return ['body' => $resp, 'info' => $info];
}

function post_api($task, $extra = []) {
    global $LANZOU_DOUUPLOAD;
    $data = array_merge(['task' => $task], $extra);
    $resp = curl_post($LANZOU_DOUUPLOAD, $data);
    if (!$resp['body']) return null;
    return json_decode($resp['body'], true);
}

function ensure_login() {
    if (!load_cookie()) {
        json_err(401, '未登录，请先执行 login 命令或创建 ~/.lanzou_cookie.json');
    }
}

// ==================== API 函数 ====================

// --- 认证 ---
function api_login($user, $pass) {
    global $LANZOU_MYDISK, $LANZOU_ACCOUNT;
    
    // 获取 formhash
    $resp = curl_get($LANZOU_ACCOUNT);
    if (!$resp['body']) json_err(500, '网络错误，无法访问蓝奏云');
    
    preg_match('/name="formhash" value="(.+?)"/', $resp['body'], $m);
    if (!$m) json_err(500, '获取 formhash 失败');
    
    $login_data = [
        'task' => '3',
        'setSessionId' => '',
        'setToken' => '',
        'setSig' => '',
        'setScene' => '',
        'uid' => $user,
        'pwd' => $pass,
        'formhash' => $m[1],
    ];
    
    $phone_header = ['User-Agent: Mozilla/5.0 (Linux; Android 5.0) Mobile Safari/537.36'];
    $resp = curl_post($LANZOU_MYDISK, $login_data, $phone_header);
    
    if (!$resp['body']) json_err(500, '登录请求失败');
    
    $result = json_decode($resp['body'], true);
    if (isset($result['info']) && strpos($result['info'], '成功') !== false) {
        // 从 set-cookie 提取
        $cookies = [];
        if (preg_match_all('/Set-Cookie:\s*(.+?);/i', $resp['info']['header'] ?? '', $cm)) {
            foreach ($cm[1] as $c) {
                if (preg_match('/^(ylogin|phpdisk_info)=(.+)$/i', $c, $cv)) {
                    $cookies[strtolower($cv[1])] = $cv[2];
                }
            }
        }
        if (!empty($cookies['ylogin']) && !empty($cookies['phpdisk_info'])) {
            save_cookie($cookies['ylogin'], $cookies['phpdisk_info']);
            json_out(['code' => 0, 'msg' => '登录成功', 'ylogin' => $cookies['ylogin']]);
        } else {
            json_err(500, '登录成功但未获取到 Cookie');
        }
    } else {
        json_err(401, '登录失败: ' . ($result['info'] ?? '未知错误'));
    }
}

function api_login_cookie($ylogin, $phpdisk_info) {
    save_cookie($ylogin, $phpdisk_info);
    json_out(['code' => 0, 'msg' => 'Cookie 已保存']);
}

function api_logout() {
    global $LANZOU_ACCOUNT;
    ensure_login();
    $resp = curl_get($LANZOU_ACCOUNT . '?action=logout');
    json_out(['code' => 0, 'msg' => '已注销']);
}

// --- 文件列表 ---
function api_get_file_list($folder_id = -1) {
    ensure_login();
    $result = post_api('47', ['folder_id' => $folder_id, 'file_type' => 0, 'page' => 1, 'limit' => 1000]);
    if (!$result || !isset($result['text'])) json_err(500, '获取文件列表失败');
    
    $files = [];
    if (isset($result['text']) && is_array($result['text'])) {
        foreach ($result['text'] as $f) {
            $files[] = [
                'name' => $f['name'] ?? '',
                'id' => intval($f['id'] ?? 0),
                'time' => $f['time'] ?? '',
                'size' => $f['size'] ?? '',
                'type' => $f['icon'] ?? '',
                'downs' => $f['downs'] ?? 0,
                'has_pwd' => ($f['onof'] ?? '0') == '1',
            ];
        }
    }
    json_out(['code' => 0, 'files' => $files, 'count' => count($files)]);
}

function api_get_dir_list($folder_id = -1) {
    ensure_login();
    $result = post_api('47', ['folder_id' => $folder_id, 'file_type' => 1, 'page' => 1, 'limit' => 1000]);
    if (!$result || !isset($result['text'])) json_err(500, '获取文件夹列表失败');
    
    $dirs = [];
    if (isset($result['text']) && is_array($result['text'])) {
        foreach ($result['text'] as $d) {
            $dirs[] = [
                'name' => $d['name'] ?? '',
                'id' => intval($d['id'] ?? 0),
                'has_pwd' => ($d['onof'] ?? '0') == '1',
                'desc' => $d['des'] ?? '',
            ];
        }
    }
    json_out(['code' => 0, 'folders' => $dirs, 'count' => count($dirs)]);
}

// --- 上传 ---
function api_upload_file($file_path, $folder_id = -1) {
    ensure_login();
    if (!file_exists($file_path)) json_err(8, '文件不存在: ' . $file_path);
    
    $filename = basename($file_path);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    global $VALID_SUFFIXES;
    $need_disguise = !in_array($ext, $VALID_SUFFIXES);
    
    // 如果后缀不允许，添加伪装
    if ($need_disguise) {
        $disguise_name = $filename . '.cdn.baidupan.com';
        $tmp_path = sys_get_temp_dir() . '/' . $disguise_name;
        copy($file_path, $tmp_path);
        $file_path = $tmp_path;
        $filename = $disguise_name;
    }
    
    $file_content = file_get_contents($file_path);
    $fields = [
        'task' => '1',
        'vie' => '2',
        've' => '2',
        'id' => 'WU_FILE_0',
        'folder_id_bb_n' => strval($folder_id),
        'name' => $filename,
        'upload_file' => [$filename, $file_content, 'application/octet-stream'],
    ];
    
    $resp = curl_post_multipart($LANZOU_FILEUP, $fields, [], 'https://pc.woozooo.com/mydisk.php');
    
    if ($need_disguise && file_exists($file_path)) unlink($file_path);
    
    if (!$resp['body']) json_err(9, '上传请求失败');
    $result = json_decode($resp['body'], true);
    
    if (isset($result['zt']) && $result['zt'] == 1) {
        $file_id = isset($result['text'][0]['id']) ? intval($result['text'][0]['id']) : 0;
        json_out(['code' => 0, 'msg' => '上传成功', 'file_id' => $file_id, 'name' => $filename]);
    } else {
        json_err(-1, '上传失败: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

// --- 下载 ---
function api_download_file($file_id, $save_path = null) {
    ensure_login();
    
    // 获取文件信息
    $info = post_api('12', ['file_id' => $file_id]);
    if (!$info) json_err(500, '获取文件信息失败');
    
    $name = $info['text'] ?? 'unknown';
    $durl = $info['url'] ?? '';
    $domain = $info['dom'] ?? '';
    $f_id = $info['f_id'] ?? '';
    
    if (empty($domain) || empty($f_id)) json_err(-1, '无法获取下载地址');
    
    $down_url = $domain . '/file/' . $f_id;
    
    // 尝试获取真实直链
    $resp = curl_get($down_url, ['Accept-Language: zh-CN,zh;q=0.9']);
    if ($resp['info']['redirect_url']) {
        $down_url = $resp['info']['redirect_url'];
    }
    
    if (!$save_path) $save_path = './' . $name;
    
    // 下载文件
    $ch = curl_init($down_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => $UA,
        CURLOPT_COOKIE => $GLOBAL_COOKIE,
        CURLOPT_REFERER => 'https://developer.lanzoug.com',
    ]);
    $fp = fopen($save_path, 'w');
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_exec($ch);
    curl_close($ch);
    fclose($fp);
    
    json_out(['code' => 0, 'msg' => '下载完成', 'path' => $save_path, 'size' => filesize($save_path)]);
}

// --- 删除 ---
function api_delete($fid, $is_file = true) {
    ensure_login();
    $task = $is_file ? '6' : '3';
    $key = $is_file ? 'file_id' : 'folder_id';
    $result = post_api($task, [$key => $fid]);
    if (!$result) json_err(9, '网络错误');
    if (isset($result['zt']) && $result['zt'] == 1) {
        json_out(['code' => 0, 'msg' => '删除成功']);
    } else {
        json_err(-1, '删除失败');
    }
}

// --- 文件夹 ---
function api_mkdir($parent_id, $name, $desc = '') {
    ensure_login();
    $name = preg_replace('/[\s]/', '_', $name);
    $name = preg_replace('/[$%^!*<>)(+=`\'\"\/:;,?]/', '', $name);
    
    $result = post_api('2', [
        'parent_id' => $parent_id ?: -1,
        'folder_name' => $name,
        'folder_description' => $desc,
    ]);
    
    if (!$result) json_err(9, '网络错误');
    if (isset($result['zt']) && $result['zt'] == 1) {
        // 获取新创建文件夹的 ID
        $dirs = [];
        $list = post_api('47', ['folder_id' => $parent_id, 'file_type' => 1, 'page' => 1, 'limit' => 1000]);
        if (isset($list['text']) && is_array($list['text'])) {
            foreach ($list['text'] as $d) {
                if ($d['name'] == $name) {
                    $dirs['id'] = intval($d['id']);
                    break;
                }
            }
        }
        json_out(['code' => 0, 'msg' => '创建成功', 'folder_id' => $dirs['id'] ?? 0, 'name' => $name]);
    } else {
        json_err(5, '创建文件夹失败');
    }
}

function api_rename_dir($folder_id, $name) {
    ensure_login();
    // 获取当前信息
    $info = post_api('18', ['folder_id' => $folder_id]);
    if (!$info) json_err(500, '获取文件夹信息失败');
    
    $desc = $info['des'] ?? '';
    $name = preg_replace('/[\s]/', '_', $name);
    $name = preg_replace('/[$%^!*<>)(+=`\'\"\/:;,?]/', '', $name);
    
    $result = post_api('4', [
        'folder_id' => $folder_id,
        'folder_name' => $name,
        'folder_description' => $desc,
    ]);
    
    if (!$result) json_err(9, '网络错误');
    json_out(['code' => 0, 'msg' => '重命名成功']);
}

// --- 提取码 ---
function api_set_passwd($fid, $passwd, $is_file = true) {
    ensure_login();
    $status = empty($passwd) ? 0 : 1;
    if ($is_file) {
        $result = post_api('23', ['file_id' => $fid, 'shows' => $status, 'shownames' => $passwd]);
    } else {
        $result = post_api('16', ['folder_id' => $fid, 'shows' => $status, 'shownames' => $passwd]);
    }
    if (!$result) json_err(9, '网络错误');
    json_out(['code' => 0, 'msg' => '提取码设置成功']);
}

// --- 分享信息 ---
function api_get_share_info($fid, $is_file = true) {
    ensure_login();
    if ($is_file) {
        $result = post_api('22', ['file_id' => $fid]);
    } else {
        $result = post_api('18', ['folder_id' => $fid]);
    }
    
    if (!$result || !isset($result['info'])) json_err(500, '获取分享信息失败');
    $info = $result['info'];
    
    $pwd = ($info['onof'] ?? '0') == '1' ? ($info['pwd'] ?? '') : '';
    
    if (isset($info['f_id'])) {
        // 文件
        $url = ($info['is_newd'] ?? '') . '/' . $info['f_id'];
        $file_info = post_api('12', ['file_id' => $fid]);
        $name = $file_info['text'] ?? '';
        $desc = $file_info['info'] ?? '';
    } else {
        $url = $info['new_url'] ?? '';
        $name = $info['name'] ?? '';
        $desc = $info['des'] ?? '';
    }
    
    json_out(['code' => 0, 'name' => $name, 'url' => $url, 'pwd' => $pwd, 'desc' => $desc]);
}

// --- 直链 ---
function api_get_durl($fid) {
    ensure_login();
    $result = post_api('12', ['file_id' => $fid]);
    if (!$result) json_err(500, '获取直链失败');
    
    $domain = $result['dom'] ?? '';
    $f_id = $result['f_id'] ?? '';
    $name = $result['text'] ?? '';
    
    if (empty($domain) || empty($f_id)) json_err(-1, '无法获取直链');
    
    $durl = $domain . '/file/' . $f_id;
    
    // 尝试解析最终直链
    $resp = curl_get($durl, [
        'Accept-Language: zh-CN,zh;q=0.9',
        'Cookie: down_ip=1; expires=Sat, 16-Nov-2019 11:42:54 GMT; path=/; domain=.baidupan.com',
    ]);
    
    if ($resp['info']['redirect_url'] && strpos($resp['info']['redirect_url'], 'http') === 0) {
        $durl = $resp['info']['redirect_url'];
    }
    
    // 清除 pid 参数防止 IP 泄露
    $durl = preg_replace('/pid=(.*?).&/', '', $durl);
    
    json_out(['code' => 0, 'name' => $name, 'durl' => $durl]);
}

// --- 描述 ---
function api_set_desc($fid, $desc, $is_file = true) {
    ensure_login();
    if ($is_file) {
        $result = post_api('11', ['file_id' => $fid, 'desc' => $desc]);
    } else {
        $info = post_api('18', ['folder_id' => $fid]);
        $name = $info['info']['name'] ?? '';
        $result = post_api('4', ['folder_id' => $fid, 'folder_name' => $name, 'folder_description' => $desc]);
    }
    if (!$result) json_err(9, '网络错误');
    json_out(['code' => 0, 'msg' => '描述设置成功']);
}

// --- 移动 ---
function api_move_file($file_id, $folder_id) {
    ensure_login();
    $result = post_api('7', ['file_id' => $file_id, 'folder_id' => $folder_id]);
    if (!$result) json_err(9, '网络错误');
    json_out(['code' => 0, 'msg' => '移动成功']);
}

function api_move_folder($folder_id, $parent_id) {
    ensure_login();
    $result = post_api('19', ['folder_id' => $folder_id, 'folder_id_bb' => $parent_id]);
    if (!$result) json_err(9, '网络错误');
    json_out(['code' => 0, 'msg' => '移动成功']);
}

// --- 分享链接解析（无需登录）---
function api_parse_share($url, $pwd = '') {
    global $UA, $LANZOU_DOUUPLOAD;
    
    $url = preg_replace('#https?://[^/]+/(\?webpage=)?#', 'https://www.lanzouf.com/', $url);
    
    $resp = curl_get($url);
    $html = $resp['body'] ?? '';
    
    if (strpos($html, '文件取消分享了') !== false) {
        json_err(7, '文件已取消分享');
    }
    
    // 提取文件名
    $name = '';
    if (preg_match('/style="font-size: 30px.*?>(.*?)<\/div>/', $html, $m)) {
        $name = $m[1];
    } elseif (preg_match('/<div class="n_box_3fn".*?>(.*?)<\/div>/', $html, $m)) {
        $name = $m[1];
    } elseif (preg_match('/var filename = \'(.*?)\';/', $html, $m)) {
        $name = $m[1];
    } elseif (preg_match('/div class="b"><span>(.*?)<\/span>/', $html, $m)) {
        $name = $m[1];
    }
    
    // 提取文件大小
    $size = '';
    if (preg_match('/大小：(.*?)<\/div>/', $html, $m)) {
        $size = $m[1];
    } elseif (preg_match('/文件大小：<\/span>(.*?)<br>/', $html, $m)) {
        $size = $m[1];
    }
    
    // 带密码的处理
    if (strpos($html, 'function down_p(){') !== false) {
        if (empty($pwd)) {
            json_err(3, '需要提取码');
        }
        preg_match_all("/'sign':'(.*?)',/", $html, $signs);
        preg_match_all("/ajaxm\.php\?file=(\d+)/", $html, $ajaxm);
        
        if (empty($signs[1][1]) || empty($ajaxm[0][0])) {
            json_err(-1, '解析失败，页面结构可能已变更');
        }
        
        $post_data = [
            'action' => 'downprocess',
            'sign' => $signs[1][1],
            'p' => $pwd,
            'kd' => 1,
        ];
        $resp = curl_post('https://www.lanzouf.com/' . $ajaxm[0][0], $post_data, [], $url);
        $result = json_decode($resp['body'], true);
        
        if (!$result || ($result['zt'] ?? 0) != 1) {
            json_err(2, '提取码错误或解析失败');
        }
        
        $name = $result['inf'] ?? $name;
        $durl = ($result['dom'] ?? '') . '/file/' . ($result['url'] ?? '');
        
        // 尝试获取最终直链
        $final = curl_get($durl, ['Accept-Language: zh-CN,zh;q=0.9']);
        if ($final['info']['redirect_url'] && strpos($final['info']['redirect_url'], 'http') === 0) {
            $durl = $final['info']['redirect_url'];
        }
        
        json_out(['code' => 0, 'msg' => '解析成功', 'name' => $name, 'size' => $size, 'durl' => $durl]);
    }
    
    // 不带密码
    preg_match("~<iframe.*?name=\"[\\s\\S]*?\"\\ssrc=\"/(.*?)\"~", $html, $link);
    if (empty($link[1])) {
        json_err(-1, '解析失败，无法提取 iframe 地址');
    }
    
    $ifurl = "https://www.lanzouf.com/" . $link[1];
    $resp2 = curl_get($ifurl);
    $html2 = $resp2['body'] ?? '';
    
    preg_match_all("/wp_sign = '(.*?)'/", $html2, $signs);
    preg_match_all("/ajaxdata = '(.*?)'/", $html2, $ajaxdata);
    preg_match_all("/ajaxm\.php\?file=(\d+)/", $html2, $ajaxm);
    
    $post_data = [
        'action' => 'downprocess',
        'websignkey' => $ajaxdata[1][0] ?? '',
        'signs' => $ajaxdata[1][0] ?? '',
        'sign' => $signs[1][0] ?? '',
        'websign' => '',
        'kd' => 1,
        'ves' => 1,
    ];
    
    $ajaxmPath = $ajaxm[0][1] ?? ($ajaxm[0][0] ?? '');
    $resp3 = curl_post('https://www.lanzouf.com/' . $ajaxmPath, $post_data, [], $ifurl);
    $result = json_decode($resp3['body'], true);
    
    if (!$result || ($result['zt'] ?? 0) != 1) {
        json_err(-1, '解析失败');
    }
    
    $durl = ($result['dom'] ?? '') . '/file/' . ($result['url'] ?? '');
    
    // 尝试获取最终直链
    $final = curl_get($durl, ['Accept-Language: zh-CN,zh;q=0.9']);
    if ($final['info']['redirect_url'] && strpos($final['info']['redirect_url'], 'http') === 0) {
        $durl = $final['info']['redirect_url'];
    }
    
    $durl = preg_replace('/pid=(.*?).&/', '', $durl);
    
    json_out(['code' => 0, 'msg' => '解析成功', 'name' => $name, 'size' => $size, 'durl' => $durl]);
}

// --- 回收站 ---
function api_get_rec_list() {
    ensure_login();
    $result = post_api('47', ['folder_id' => -1, 'file_type' => 0, 'page' => 1, 'limit' => 1000, 'status' => 2]);
    $files = [];
    if (isset($result['text']) && is_array($result['text'])) {
        foreach ($result['text'] as $f) {
            $files[] = ['name' => $f['name'] ?? '', 'id' => intval($f['id'] ?? 0), 'size' => $f['size'] ?? ''];
        }
    }
    
    $result2 = post_api('47', ['folder_id' => -1, 'file_type' => 1, 'page' => 1, 'limit' => 1000, 'status' => 2]);
    $dirs = [];
    if (isset($result2['text']) && is_array($result2['text'])) {
        foreach ($result2['text'] as $d) {
            $dirs[] = ['name' => $d['name'] ?? '', 'id' => intval($d['id'] ?? 0)];
        }
    }
    
    json_out(['code' => 0, 'files' => $files, 'folders' => $dirs]);
}

function api_recovery($fid, $is_file = true) {
    ensure_login();
    $task = $is_file ? '30' : '32';
    $key = $is_file ? 'file_id' : 'folder_id';
    $result = post_api($task, [$key => $fid]);
    if (!$result) json_err(9, '网络错误');
    json_out(['code' => 0, 'msg' => '恢复成功']);
}

function api_delete_rec($fid, $is_file = true) {
    ensure_login();
    $task = $is_file ? '28' : '29';
    $key = $is_file ? 'file_id' : 'folder_id';
    $result = post_api($task, [$key => $fid]);
    if (!$result) json_err(9, '网络错误');
    json_out(['code' => 0, 'msg' => '彻底删除成功']);
}

function api_clean_rec() {
    ensure_login();
    // 先获取所有回收站项目，逐个删除
    $result = post_api('47', ['folder_id' => -1, 'file_type' => 0, 'page' => 1, 'limit' => 1000, 'status' => 2]);
    if (isset($result['text']) && is_array($result['text'])) {
        foreach ($result['text'] as $f) {
            post_api('28', ['file_id' => $f['id']]);
        }
    }
    $result2 = post_api('47', ['folder_id' => -1, 'file_type' => 1, 'page' => 1, 'limit' => 1000, 'status' => 2]);
    if (isset($result2['text']) && is_array($result2['text'])) {
        foreach ($result2['text'] as $d) {
            post_api('29', ['folder_id' => $d['id']]);
        }
    }
    json_out(['code' => 0, 'msg' => '回收站已清空']);
}

// --- 获取移动目标文件夹列表 ---
function api_get_move_folders() {
    ensure_login();
    $result = post_api('47', ['folder_id' => -1, 'file_type' => 1, 'page' => 1, 'limit' => 1000]);
    $dirs = [];
    if (isset($result['text']) && is_array($result['text'])) {
        foreach ($result['text'] as $d) {
            $dirs[] = ['name' => $d['name'] ?? '', 'id' => intval($d['id'] ?? 0)];
        }
    }
    json_out(['code' => 0, 'folders' => $dirs]);
}

// --- 获取文件夹详情 ---
function api_get_folder_info_by_id($folder_id) {
    ensure_login();
    // 获取文件夹内文件
    $file_result = post_api('47', ['folder_id' => $folder_id, 'file_type' => 0, 'page' => 1, 'limit' => 1000]);
    $files = [];
    if (isset($file_result['text']) && is_array($file_result['text'])) {
        foreach ($file_result['text'] as $f) {
            $files[] = [
                'name' => $f['name'] ?? '',
                'id' => intval($f['id'] ?? 0),
                'time' => $f['time'] ?? '',
                'size' => $f['size'] ?? '',
            ];
        }
    }
    
    // 获取子文件夹
    $dir_result = post_api('47', ['folder_id' => $folder_id, 'file_type' => 1, 'page' => 1, 'limit' => 1000]);
    $sub_dirs = [];
    if (isset($dir_result['text']) && is_array($dir_result['text'])) {
        foreach ($dir_result['text'] as $d) {
            $sub_dirs[] = [
                'name' => $d['name'] ?? '',
                'id' => intval($d['id'] ?? 0),
            ];
        }
    }
    
    json_out(['code' => 0, 'folder_id' => $folder_id, 'files' => $files, 'sub_folders' => $sub_dirs]);
}

// ==================== 命令行入口 ====================

function print_usage() {
    $usage = <<<USAGE
蓝奏云 API 命令行工具

用法: php lanzou_api.php <命令> [参数...]

认证:
  login -u <用户名> -p <密码>        登录
  login-cookie -y <ylogin> -p <phpdisk_info>  Cookie 登录
  logout                              注销

文件操作:
  ls [-f <文件夹ID>]                  文件列表 (默认根目录)
  upload <文件路径> [-d <文件夹ID>]   上传文件
  download <文件ID> [-o <保存路径>]   下载文件
  delete <文件ID>                     删除文件
  rename-file <文件ID> <新名称>       重命名文件 (需VIP)
  durl <文件ID>                       获取直链

文件夹操作:
  ls-dir [-f <父文件夹ID>]            文件夹列表
  mkdir <名称> [-p <父文件夹ID>] [-d <描述>]  创建文件夹
  rmdir <文件夹ID>                    删除文件夹
  rename-dir <文件夹ID> <新名称>      重命名文件夹
  dir-info <文件夹ID>                 文件夹详情
  path                                完整目录树

分享与提取码:
  share-info <ID> [-t file|folder]    分享信息
  set-pwd <ID> <密码> [-t file|folder] 设置提取码
  set-desc <ID> <描述> [-t file|folder] 设置描述
  parse <分享链接> [-p <提取码>]      解析分享链接
  resolve <分享链接> [-p <提取码>]    获取分享直链

移动:
  move-file <文件ID> <目标文件夹ID>   移动文件
  move-dir <文件夹ID> <目标父文件夹ID> 移动文件夹
  move-folders                        可用目标文件夹列表

回收站:
  rec-list                            回收站列表
  rec-recover <ID> [-t file|folder]   恢复
  rec-delete <ID> [-t file|folder]    彻底删除
  rec-clean                           清空回收站
USAGE;
    echo $usage . "\n";
}

function parse_args($argv, $opts) {
    $result = [];
    for ($i = 0; $i < count($argv); $i++) {
        if (isset($opts[$argv[$i]])) {
            $result[$opts[$argv[$i]]] = $argv[$i + 1] ?? '';
            $i++;
        }
    }
    return $result;
}

// 主入口
if (php_sapi_name() !== 'cli') {
    json_err(403, '请通过命令行调用');
}

if ($argc < 2) {
    print_usage();
    exit(0);
}

$action = $argv[1];

switch ($action) {
    case 'login':
        $args = parse_args(array_slice($argv, 2), ['-u' => 'user', '-p' => 'pass']);
        if (empty($args['user']) || empty($args['pass'])) {
            json_err(400, '用法: login -u 用户名 -p 密码');
        }
        api_login($args['user'], $args['pass']);
        break;
    
    case 'login-cookie':
        $args = parse_args(array_slice($argv, 2), ['-y' => 'ylogin', '-p' => 'phpdisk_info']);
        if (empty($args['ylogin']) || empty($args['phpdisk_info'])) {
            json_err(400, '用法: login-cookie -y <ylogin> -p <phpdisk_info>');
        }
        api_login_cookie($args['ylogin'], $args['phpdisk_info']);
        break;
    
    case 'logout':
        api_logout();
        break;
    
    case 'ls':
        $args = parse_args(array_slice($argv, 2), ['-f' => 'folder_id']);
        $fid = intval($args['folder_id'] ?? -1);
        api_get_file_list($fid);
        break;
    
    case 'ls-dir':
        $args = parse_args(array_slice($argv, 2), ['-f' => 'folder_id']);
        $fid = intval($args['folder_id'] ?? -1);
        api_get_dir_list($fid);
        break;
    
    case 'upload':
        if ($argc < 3) json_err(400, '用法: upload <文件路径> [-d <文件夹ID>]');
        $args = parse_args(array_slice($argv, 3), ['-d' => 'folder_id']);
        $fid = intval($args['folder_id'] ?? -1);
        api_upload_file($argv[2], $fid);
        break;
    
    case 'download':
        if ($argc < 3) json_err(400, '用法: download <文件ID> [-o <保存路径>]');
        $args = parse_args(array_slice($argv, 3), ['-o' => 'save_path']);
        api_download_file(intval($argv[2]), $args['save_path'] ?? null);
        break;
    
    case 'delete':
        if ($argc < 3) json_err(400, '用法: delete <文件ID>');
        api_delete(intval($argv[2]), true);
        break;
    
    case 'rmdir':
        if ($argc < 3) json_err(400, '用法: rmdir <文件夹ID>');
        api_delete(intval($argv[2]), false);
        break;
    
    case 'mkdir':
        if ($argc < 3) json_err(400, '用法: mkdir <名称> [-p <父文件夹ID>] [-d <描述>]');
        $args = parse_args(array_slice($argv, 3), ['-p' => 'parent_id', '-d' => 'desc']);
        $pid = intval($args['parent_id'] ?? -1);
        api_mkdir($pid, $argv[2], $args['desc'] ?? '');
        break;
    
    case 'rename-dir':
        if ($argc < 4) json_err(400, '用法: rename-dir <文件夹ID> <新名称>');
        api_rename_dir(intval($argv[2]), $argv[3]);
        break;
    
    case 'rename-file':
        if ($argc < 4) json_err(400, '用法: rename-file <文件ID> <新名称>');
        ensure_login();
        $result = post_api('46', ['file_id' => intval($argv[2]), 'file_name' => $argv[3], 'type' => 2]);
        if (!$result) json_err(9, '网络错误');
        json_out(['code' => 0, 'msg' => '重命名成功']);
        break;
    
    case 'durl':
        if ($argc < 3) json_err(400, '用法: durl <文件ID>');
        api_get_durl(intval($argv[2]));
        break;
    
    case 'share-info':
        if ($argc < 3) json_err(400, '用法: share-info <ID> [-t file|folder]');
        $args = parse_args(array_slice($argv, 3), ['-t' => 'type']);
        $is_file = ($args['type'] ?? 'file') !== 'folder';
        api_get_share_info(intval($argv[2]), $is_file);
        break;
    
    case 'set-pwd':
        if ($argc < 4) json_err(400, '用法: set-pwd <ID> <密码> [-t file|folder]');
        $args = parse_args(array_slice($argv, 4), ['-t' => 'type']);
        $is_file = ($args['type'] ?? 'file') !== 'folder';
        api_set_passwd(intval($argv[2]), $argv[3], $is_file);
        break;
    
    case 'set-desc':
        if ($argc < 4) json_err(400, '用法: set-desc <ID> <描述> [-t file|folder]');
        $args = parse_args(array_slice($argv, 4), ['-t' => 'type']);
        $is_file = ($args['type'] ?? 'file') !== 'folder';
        api_set_desc(intval($argv[2]), $argv[3], $is_file);
        break;
    
    case 'parse':
        if ($argc < 3) json_err(400, '用法: parse <分享链接> [-p <提取码>]');
        $args = parse_args(array_slice($argv, 3), ['-p' => 'pwd']);
        api_parse_share($argv[2], $args['pwd'] ?? '');
        break;
    
    case 'resolve':
        if ($argc < 3) json_err(400, '用法: resolve <分享链接> [-p <提取码>]');
        $args = parse_args(array_slice($argv, 3), ['-p' => 'pwd']);
        api_parse_share($argv[2], $args['pwd'] ?? '');
        break;
    
    case 'move-file':
        if ($argc < 4) json_err(400, '用法: move-file <文件ID> <目标文件夹ID>');
        api_move_file(intval($argv[2]), intval($argv[3]));
        break;
    
    case 'move-dir':
        if ($argc < 4) json_err(400, '用法: move-dir <文件夹ID> <目标父文件夹ID>');
        api_move_folder(intval($argv[2]), intval($argv[3]));
        break;
    
    case 'move-folders':
        api_get_move_folders();
        break;
    
    case 'dir-info':
        if ($argc < 3) json_err(400, '用法: dir-info <文件夹ID>');
        api_get_folder_info_by_id(intval($argv[2]));
        break;
    
    case 'path':
        ensure_login();
        $fid = $argc >= 3 ? intval($argv[2]) : -1;
        // 获取当前目录路径
        $result = post_api('47', ['folder_id' => $fid, 'file_type' => 1, 'page' => 1, 'limit' => 1000]);
        $dirs = [];
        if (isset($result['text']) && is_array($result['text'])) {
            foreach ($result['text'] as $d) {
                $dirs[] = ['name' => $d['name'] ?? '', 'id' => intval($d['id'] ?? 0)];
            }
        }
        json_out(['code' => 0, 'current_id' => $fid, 'folders' => $dirs]);
        break;
    
    case 'rec-list':
        api_get_rec_list();
        break;
    
    case 'rec-recover':
        if ($argc < 3) json_err(400, '用法: rec-recover <ID> [-t file|folder]');
        $args = parse_args(array_slice($argv, 3), ['-t' => 'type']);
        $is_file = ($args['type'] ?? 'file') !== 'folder';
        api_recovery(intval($argv[2]), $is_file);
        break;
    
    case 'rec-delete':
        if ($argc < 3) json_err(400, '用法: rec-delete <ID> [-t file|folder]');
        $args = parse_args(array_slice($argv, 3), ['-t' => 'type']);
        $is_file = ($args['type'] ?? 'file') !== 'folder';
        api_delete_rec(intval($argv[2]), $is_file);
        break;
    
    case 'rec-clean':
        api_clean_rec();
        break;
    
    default:
        echo "未知命令: {$action}\n\n";
        print_usage();
        exit(1);
}
