#!/usr/bin/env python3
"""
蓝奏云完整 API 封装 (Python)
基于 zaxtyson/LanZouCloud-API 的逆向分析实现

用法:
  python3 lanzou_api.py <action> [options...]

认证: 从 ~/.lanzou_cookie.json 读取 Cookie
"""

import os
import re
import sys
import json
import hashlib
import tempfile
import mimetypes
from urllib.parse import urlencode
from urllib.request import Request, urlopen, build_opener, HTTPCookieProcessor
from urllib.error import URLError, HTTPError
from urllib.parse import urlparse
import subprocess
import http.cookiejar
from http.cookiejar import CookieJar, Cookie

# ==================== 配置 ====================
LANZOU_HOST = 'https://pan.lanzouo.com'
LANZOU_DOUUPLOAD = 'https://pc.woozooo.com/doupload.php'
LANZOU_ACCOUNT = 'https://pc.woozooo.com/account.php'
LANZOU_MYDISK = 'https://pc.woozooo.com/mydisk.php'
LANZOU_FILEUP = 'https://pc.woozooo.com/html5up.php'

BACKUP_DOMAINS = ['lanzouw.com', 'lanzoui.com', 'lanzoux.com', 'lanzouo.com']

UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'



# ==================== 全局状态 ====================
COOKIE_FILE = os.path.expanduser('~/.lanzou_cookie.json')
_jar = CookieJar()
_opener = build_opener(HTTPCookieProcessor(_jar))

def json_out(data):
    print(json.dumps(data, ensure_ascii=False, indent=2))

def json_err(code, msg):
    json_out({'code': code, 'msg': msg})
    sys.exit(1)

def load_cookie():
    global _jar
    if not os.path.exists(COOKIE_FILE):
        return False
    try:
        data = json.load(open(COOKIE_FILE))
        if not data or not data.get('ylogin') or not data.get('phpdisk_info'):
            return False
        # 手动添加 cookie
        from http.cookiejar import CookieJar, Cookie
        for name, val in [('ylogin', data['ylogin']), ('phpdisk_info', data['phpdisk_info'])]:
            c = Cookie(0, name, val, None, False, '.woozooo.com', True, True,
                       '/', True, False, None, False, None, {}, {})
            _jar.set_cookie(c)
        return True
    except Exception:
        return False

def save_cookie(ylogin, phpdisk_info):
    os.makedirs(os.path.dirname(COOKIE_FILE), exist_ok=True)
    with open(COOKIE_FILE, 'w') as f:
        json.dump({'ylogin': ylogin, 'phpdisk_info': phpdisk_info}, f, indent=2)

def rand_ip():
    import random
    prefixes = [218,66,60,202,204,59,61,222,221,122,211]
    return f"{random.choice(prefixes)}.{random.randint(1,255)}.{random.randint(1,255)}.{random.randint(1,255)}"

def calc_acw_sc__v2(arg1):
    pos_list = [15,35,29,24,33,16,1,38,10,9,19,31,40,27,22,23,25,13,6,11,39,18,20,8,14,21,32,26,2,30,7,4,17,5,3,28,34,37,12,36]
    mask = '3000176000856006061501533003690027800375'
    out = [''] * 40
    for i, ch in enumerate(arg1):
        for j, pos in enumerate(pos_list):
            if pos == i + 1:
                out[j] = ch
    arg2 = ''.join(out)
    result = ''
    for i in range(0, min(len(arg2), len(mask)), 2):
        s = int(arg2[i:i+2], 16)
        m = int(mask[i:i+2], 16)
        result += f'{s ^ m:02x}'
    return result

def _headers(extra=None):
    h = {
        'User-Agent': UA,
        'Accept-Language': 'zh-CN,zh;q=0.9',
        'X-FORWARDED-FOR': rand_ip(),
        'CLIENT-IP': rand_ip(),
    }
    if extra:
        h.update(extra)
    return h

def curl_get(url, extra_headers=None):
    req = Request(url, headers=_headers(extra_headers))
    try:
        resp = _opener.open(req, timeout=15)
        raw = resp.read()
        if raw[:2] == b'\x1f\x8b':
            import gzip
            raw = gzip.decompress(raw)
        return {'body': raw.decode('utf-8', errors='replace'), 'url': resp.url, 'code': resp.status}
    except (URLError, HTTPError) as e:
        body = ''
        if hasattr(e, 'read'):
            raw = e.read()
            if raw[:2] == b'\x1f\x8b':
                import gzip
                raw = gzip.decompress(raw)
            body = raw.decode('utf-8', errors='replace')
        return {'body': body, 'url': url, 'code': getattr(e, 'code', 0)}

def curl_post(url, data, extra_headers=None, referer=''):
    headers = _headers(extra_headers)
    if referer:
        headers['Referer'] = referer
    if isinstance(data, dict):
        data = urlencode(data).encode('utf-8')
    req = Request(url, data=data, headers=headers, method='POST')
    try:
        resp = _opener.open(req, timeout=30)
        raw = resp.read()
        if raw[:2] == b'\x1f\x8b':
            import gzip
            raw = gzip.decompress(raw)
        return {'body': raw.decode('utf-8', errors='replace'), 'url': resp.url}
    except (URLError, HTTPError) as e:
        body = ''
        if hasattr(e, 'read'):
            raw = e.read()
            if raw[:2] == b'\x1f\x8b':
                import gzip
                raw = gzip.decompress(raw)
            body = raw.decode('utf-8', errors='replace')
        return {'body': body, 'url': url}

def curl_post_multipart(url, fields, extra_headers=None, referer=''):
    """multipart/form-data POST"""
    import io
    boundary = '----PythonBoundary' + hashlib.md5(str(id(fields)).encode()).hexdigest()[:16]
    body = io.BytesIO()
    
    for key, val in fields.items():
        body.write(f'--{boundary}\r\n'.encode())
        if isinstance(val, tuple) and len(val) == 3:
            filename, content, mime = val
            body.write(f'Content-Disposition: form-data; name="{key}"; filename="{filename}"\r\n'.encode())
            body.write(f'Content-Type: {mime}\r\n\r\n'.encode())
            if isinstance(content, str):
                content = content.encode('utf-8')
            body.write(content)
            body.write(b'\r\n')
        else:
            body.write(f'Content-Disposition: form-data; name="{key}"\r\n\r\n'.encode())
            body.write(str(val).encode('utf-8'))
            body.write(b'\r\n')
    
    body.write(f'--{boundary}--\r\n'.encode())
    body_bytes = body.getvalue()
    
    headers = _headers(extra_headers)
    headers['Content-Type'] = f'multipart/form-data; boundary={boundary}'
    if referer:
        headers['Referer'] = referer
    
    req = Request(url, data=body_bytes, headers=headers, method='POST')
    try:
        resp = _opener.open(req, timeout=3600)
        raw = resp.read()
        if raw[:2] == b'\x1f\x8b':
            import gzip
            raw = gzip.decompress(raw)
        return {'body': raw.decode('utf-8', errors='replace'), 'url': resp.url}
    except (URLError, HTTPError) as e:
        body2 = ''
        if hasattr(e, 'read'):
            raw = e.read()
            if raw[:2] == b'\x1f\x8b':
                import gzip
                raw = gzip.decompress(raw)
            body2 = raw.decode('utf-8', errors='replace')
        return {'body': body2, 'url': url}

def post_api(task, extra=None):
    data = {'task': str(task)}
    if extra:
        data.update({k: str(v) for k, v in extra.items()})
    resp = curl_post(LANZOU_DOUUPLOAD, data)
    if not resp['body']:
        return None
    try:
        return json.loads(resp['body'])
    except json.JSONDecodeError:
        return None


def detect_vip():
    """检测当前账号是否为 VIP"""
    ensure_login()
    # 通过上传限制变量判断：免费版 upsizeb=104857600 (100MB)
    # VIP 版 upsizeb=576716800 (550MB)
    global _vip_status
    if hasattr(sys.modules[__name__], '_vip_status') and _vip_status is not None:
        return _vip_status
    
    uid = ''
    for c in _jar:
        if c.name == 'ylogin':
            uid = c.value
            break
    
    if not uid:
        _vip_status = False
        return False
    
    req = Request(f'https://pc.woozooo.com/mydisk.php?item=files&action=index&u={uid}', headers=_headers({
        'Accept-Encoding': 'gzip, deflate',
    }))
    try:
        import gzip as _gzip
        resp = urlopen(req, timeout=10)
        data = resp.read()
        try:
            body = _gzip.decompress(data).decode('utf-8', errors='replace')
        except:
            body = data.decode('utf-8', errors='replace')
        
        # 提取 upsizeb 变量
        m = re.search(r'upsizeb\s*=\s*[\'"]*(\d+)', body)
        if m:
            upsizeb = int(m.group(1))
            _vip_status = upsizeb > 104857600  # 大于 100MB 就是 VIP
        else:
            _vip_status = False
        return _vip_status
    except Exception:
        _vip_status = False
        return False

_vip_status = None



def require_vip(feature_name=""):
    """检查是否为 VIP，不是则提示"""
    if not detect_vip():
        msg = f'此功能需要 VIP 会员'
        if feature_name:
            msg += f'（{feature_name}）'
        msg += '。当前为免费版账号。'
        json_err(11, msg)

# 允许上传的文件后缀（官方白名单）
VALID_SUFFIXES = set('ppt,xapk,ke,azw,cpk,gho,dwg,db,docx,deb,e,ttf,xls,bat,crx,rpm,txf,pdf,apk,ipa,txt,mobi,osk,dmg,rp,osz,jar,ttc,z,w3x,xlsx,cetrainer,ct,rar,mp3,pptx,mobileconfig,epub,imazingapp,doc,iso,img,appimage,7z,rplib,lolgezi,exe,azw3,zip,conf,tar,dll,flac,xpa,lua,cad,hwt,accdb,ce,xmind,enc,bds,bdi,ssf,it,gz'.split(','))

FREE_MAX_SIZE = 100 * 1024 * 1024  # 100MB
VIP_MAX_SIZE = 550 * 1024 * 1024   # 550MB

def check_upload_limits(file_path):
    """检查上传限制，返回 (allowed, warnings, need_disguise)"""
    warnings = []
    need_disguise = False
    
    filename = os.path.basename(file_path)
    ext = filename.rsplit('.', 1)[-1].lower() if '.' in filename else ''
    file_size = os.path.getsize(file_path)
    is_vip = detect_vip()
    max_size = VIP_MAX_SIZE if is_vip else FREE_MAX_SIZE
    
    # 检查文件大小
    if file_size > max_size:
        size_mb = file_size / (1024 * 1024)
        limit_mb = max_size / (1024 * 1024)
        warnings.append(f'文件大小 {size_mb:.1f}MB 超过 {"VIP" if is_vip else "免费版"} 限制 {limit_mb}MB')
    
    # 检查格式
    if ext not in VALID_SUFFIXES:
        need_disguise = True
        warnings.append(f'文件格式 .{ext} 不在白名单中，将自动伪装后缀上传')
    
    # 手机端 APK 分享限制
    if ext == 'apk' and not is_vip:
        warnings.append('注意：免费版手机端无法分享 APK 文件（电脑端可以）')
    
    return len(warnings) == 0 or (not any('超过' in w for w in warnings)), warnings, need_disguise


def ensure_login():
    if not load_cookie():
        json_err(401, '未登录，请先执行 login 命令或创建 ~/.lanzou_cookie.json')

# ==================== API 函数 ====================

def _get_acw_cookie():
    """通过 Node.js 执行蓝奏云 JS 获取 acw_sc__v2 cookie"""
    try:
        url = 'https://accounts.woozooo.com/accounts.php?action=login&ref=pc.woozooo.com'
        req = Request(url, headers=_headers({'Accept-Encoding': 'gzip, deflate'}))
        resp = urlopen(req, timeout=10)
        import gzip
        data = gzip.decompress(resp.read()).decode('utf-8', errors='replace')
        
        m = re.search(r'<script>(.*?)</script>', data, re.DOTALL)
        if not m:
            return None
        
        node_script = f"""
var cv = '';
var document = {{ set cookie(v) {{ cv = v; }}, location: {{ reload: function() {{}} }} }};
try {{ {m.group(1)} }} catch(e) {{}}
var match = cv.match(/acw_sc__v2=([^;]+)/);
if (match) console.log(match[1]);
"""
        tmp = os.path.join(tempfile.gettempdir(), 'acw_lanzou.js')
        with open(tmp, 'w') as f:
            f.write(node_script)
        result = subprocess.run(['node', tmp], capture_output=True, text=True, timeout=5)
        os.remove(tmp)
        cookie_val = result.stdout.strip()
        return cookie_val if cookie_val else None
    except Exception as e:
        return None

def api_login(user, passwd):
    """完整登录流程：获取 acw → POST 登录 → 保存 Cookie"""
    # Step 1: 获取 acw_sc__v2
    acw = _get_acw_cookie()
    if not acw:
        json_err(500, '无法获取 acw_sc__v2，请确保已安装 Node.js')
    
    # Step 2: 构建 opener 并设置 acw cookie
    cj = http.cookiejar.CookieJar()
    opener = build_opener(HTTPCookieProcessor(cj))
    c = Cookie(0, 'acw_sc__v2', acw, None, False, '.woozooo.com', True, True, '/', True, False, None, False, None, {}, {})
    cj.set_cookie(c)
    
    # Step 3: POST 登录到 accounts.woozooo.com (表单格式，错误信息更清晰)
    from urllib.parse import urlencode as _urlencode
    login_data = _urlencode({
        'task': 'uselogin',
        'username': user,
        'password': passwd,
        'ref': 'pc.woozooo.com',
    }).encode('utf-8')
    
    req = Request(
        'https://accounts.woozooo.com/accounts.php',
        data=login_data,
        headers={
            'User-Agent': UA,
            'Content-Type': 'application/x-www-form-urlencoded',
            'Origin': 'https://accounts.woozooo.com',
            'Referer': 'https://accounts.woozooo.com/accounts.php?action=login&ref=pc.woozooo.com',
        },
        method='POST'
    )
    
    resp = opener.open(req, timeout=15)
    raw = resp.read()
    if raw[:2] == b'\x1f\x8b':
        import gzip
        raw = gzip.decompress(raw)
    body = raw.decode('utf-8', errors='replace')
    
    try:
        result = json.loads(body)
    except:
        json_err(500, f'登录响应异常: {body[:200]}')
    
    # zt=1 表示成功，zt=0 表示失败，msgs 包含错误信息
    if result.get('zt') == 1:
        # 提取 Cookie
        ylogin = phpdisk_info = ''
        for cookie in cj:
            if cookie.name == 'ylogin':
                ylogin = cookie.value
            elif cookie.name == 'phpdisk_info':
                phpdisk_info = cookie.value
        
        if ylogin and phpdisk_info:
            save_cookie(ylogin, phpdisk_info)
            json_out({'code': 0, 'msg': '登录成功', 'ylogin': ylogin})
        else:
            json_err(500, '登录成功但未获取到完整 Cookie，请尝试 login-cookie 方式')
    else:
        msg = result.get('msgs', result.get('msg', '未知错误'))
        json_err(401, f'登录失败: {msg}')

def api_login_cookie(ylogin, phpdisk_info):
    save_cookie(ylogin, phpdisk_info)
    json_out({'code': 0, 'msg': 'Cookie 已保存'})

def api_logout():
    ensure_login()
    curl_get(LANZOU_ACCOUNT + '?action=logout')
    json_out({'code': 0, 'msg': '已注销'})

def api_get_file_list(folder_id=-1):
    ensure_login()
    result = post_api(5, {'folder_id': folder_id, 'pg': 1})
    if not result or 'text' not in result:
        json_err(500, '获取文件列表失败')
    
    files = []
    if isinstance(result.get('text'), list):
        for f in result['text']:
            files.append({
                'name': f.get('name', ''),
                'id': int(f.get('id', 0)),
                'time': f.get('time', ''),
                'size': f.get('size', ''),
                'downs': f.get('downs', 0),
                'has_pwd': f.get('onof', '0') == '1',
            })
    json_out({'code': 0, 'files': files, 'count': len(files)})

def api_get_dir_list(folder_id=-1):
    ensure_login()
    result = post_api(47, {'folder_id': folder_id, 'pg': 1})
    if not result or 'text' not in result:
        json_err(500, '获取文件夹列表失败')
    
    dirs = []
    if isinstance(result.get('text'), list):
        for d in result['text']:
            dirs.append({
                'name': d.get('name', ''),
                'id': int(d.get('fol_id', d.get('id', 0))),
                'has_pwd': d.get('onof', '0') == '1',
                'desc': d.get('folder_des', d.get('des', '')),
            })
    json_out({'code': 0, 'folders': dirs, 'count': len(dirs)})

def api_upload_file(file_path, folder_id=-1):
    ensure_login()
    if not os.path.isfile(file_path):
        json_err(8, f'文件不存在: {file_path}')
    
    filename = os.path.basename(file_path)
    ext = filename.rsplit('.', 1)[-1].lower() if '.' in filename else ''
    
    # 检查上传限制
    is_vip = detect_vip()
    file_size = os.path.getsize(file_path)
    max_size = VIP_MAX_SIZE if is_vip else FREE_MAX_SIZE
    
    if file_size > max_size:
        size_mb = file_size / (1024 * 1024)
        limit_mb = max_size / (1024 * 1024)
        json_err(11, f'文件大小 {size_mb:.1f}MB 超过{"VIP" if is_vip else "免费版"}限制 {limit_mb}MB')
    
    need_disguise = ext not in VALID_SUFFIXES
    if need_disguise:
        disguise_name = filename + '.cdn.baidupan.com'
        tmp_path = os.path.join(tempfile.gettempdir(), disguise_name)
        with open(file_path, 'rb') as src, open(tmp_path, 'wb') as dst:
            dst.write(src.read())
        upload_path = tmp_path
        upload_name = disguise_name
    else:
        upload_path = file_path
        upload_name = filename
    
    with open(upload_path, 'rb') as f:
        content = f.read()
    
    mime = mimetypes.guess_type(upload_name)[0] or 'application/octet-stream'
    fields = {
        'task': '1', 'vie': '2', 've': '2',
        'folder_id_bb_n': str(folder_id),
        'name': upload_name,
        'upload_file': (upload_name, content, mime),
    }
    
    resp = curl_post_multipart(LANZOU_FILEUP, fields, referer='https://pc.woozooo.com/mydisk.php?item=files&action=index')
    
    if need_disguise and os.path.exists(tmp_path):
        os.remove(tmp_path)
    
    if not resp['body']:
        json_err(9, '上传请求失败')
    
    try:
        result = json.loads(resp['body'])
    except:
        json_err(500, '解析上传结果失败')
    
    if result.get('zt') == 1:
        file_id = result['text'][0]['id'] if result.get('text') else 0
        json_out({'code': 0, 'msg': '上传成功', 'file_id': int(file_id), 'name': upload_name})
    else:
        json_err(-1, f"上传失败: {json.dumps(result, ensure_ascii=False)}")

def api_download_file(file_id, save_path=None):
    ensure_login()
    # task=24 获取直链
    info = post_api(24, {'file_id': file_id})
    if not info or info.get('zt') != 1:
        json_err(500, '获取下载地址失败')
    
    down_url = info.get('info', '')
    if not down_url or 'http' not in down_url:
        json_err(-1, '无法获取下载地址')
    
    # 获取文件名
    name_result = post_api(12, {'file_id': file_id})
    name = name_result.get('text', 'unknown') if name_result else 'unknown'
    
    if not save_path:
        save_path = './' + name
    
    # 下载
    req = Request(down_url, headers={
        'User-Agent': UA,
        'Accept-Language': 'zh-CN,zh;q=0.9',
        'Referer': 'https://developer.lanzoug.com',
    })
    try:
        resp = _opener.open(req, timeout=3600)
        with open(save_path, 'wb') as f:
            while True:
                chunk = resp.read(65536)
                if not chunk:
                    break
                f.write(chunk)
        json_out({'code': 0, 'msg': '下载完成', 'path': os.path.abspath(save_path), 'size': os.path.getsize(save_path)})
    except Exception as e:
        json_err(-1, f'下载失败: {e}')

def api_delete(fid, is_file=True):
    ensure_login()
    task = 6 if is_file else 3
    key = 'file_id' if is_file else 'folder_id'
    result = post_api(task, {key: fid})
    if not result:
        json_err(9, '网络错误')
    if result.get('zt') == 1:
        json_out({'code': 0, 'msg': '删除成功'})
    else:
        json_err(-1, '删除失败')

def api_mkdir(parent_id, name, desc=''):
    ensure_login()
    name = re.sub(r'[\s]', '_', name)
    name = re.sub(r'[$%^!*<>)(+=`\'"/:;,?]', '', name)
    
    result = post_api(2, {
        'parent_id': parent_id or -1,
        'folder_name': name,
        'folder_description': desc,
    })
    
    if not result:
        json_err(9, '网络错误')
    if result.get('zt') == 1:
        # 获取新文件夹 ID
        folder_id = 0
        list_result = post_api(47, {'folder_id': parent_id or -1, 'pg': 1})
        if isinstance(list_result.get('text'), list):
            for d in list_result['text']:
                if d.get('name') == name:
                    folder_id = int(d.get('fol_id', d.get('id', 0)))
                    break
        json_out({'code': 0, 'msg': '创建成功', 'folder_id': folder_id, 'name': name})
    else:
        json_err(5, '创建文件夹失败')

def api_rename_dir(folder_id, name):
    ensure_login()
    info = post_api(18, {'folder_id': folder_id})
    if not info or 'info' not in info:
        json_err(500, '获取文件夹信息失败')
    
    desc = info['info'].get('des', '')
    name = re.sub(r'[\s]', '_', name)
    name = re.sub(r'[$%^!*<>)(+=`\'"/:;,?]', '', name)
    
    result = post_api(4, {
        'folder_id': folder_id,
        'folder_name': name,
        'folder_description': desc,
    })
    if not result:
        json_err(9, '网络错误')
    json_out({'code': 0, 'msg': '重命名成功'})

def api_set_passwd(fid, passwd, is_file=True):
    ensure_login()
    status = 0 if not passwd else 1
    if is_file:
        result = post_api(23, {'file_id': fid, 'shows': status, 'shownames': passwd})
    else:
        result = post_api(16, {'folder_id': fid, 'shows': status, 'shownames': passwd})
    if not result:
        json_err(9, '网络错误')
    json_out({'code': 0, 'msg': '提取码设置成功'})

def api_get_share_info(fid, is_file=True):
    ensure_login()
    if is_file:
        result = post_api(22, {'file_id': fid})
        if not result or result.get('zt') != 1:
            json_err(500, '获取分享信息失败')
        info = result.get('info', {})
        pwd = info.get('pwd', '') if info.get('onof') == '1' else ''
        url = info.get('is_newd', '') + '/' + info.get('f_id', '')
        name_result = post_api(12, {'file_id': fid})
        name = name_result.get('text', '') if name_result else ''
    else:
        result = post_api(18, {'folder_id': fid})
        if not result or result.get('zt') != 1:
            json_err(500, '获取分享信息失败')
        info = result.get('info', {})
        pwd = info.get('pwd', '') if info.get('onof') == '1' else ''
        url = info.get('new_url', '')
        name = info.get('name', '')
    
    json_out({'code': 0, 'name': name, 'url': url, 'pwd': pwd})

def api_get_durl(fid):
    ensure_login()
    # task=24 直接返回下载链接
    result = post_api(24, {'file_id': fid})
    if not result or result.get('zt') != 1:
        json_err(-1, '无法获取直链')
    
    durl = result.get('info', '')
    if not durl or 'http' not in durl:
        json_err(-1, '直链获取失败')
    
    # 同时获取文件名
    name_result = post_api(12, {'file_id': fid})
    name = name_result.get('text', '') if name_result else ''
    
    json_out({'code': 0, 'name': name, 'durl': durl})

def api_set_desc(fid, desc, is_file=True):
    ensure_login()
    if is_file:
        result = post_api(11, {'file_id': fid, 'desc': desc})
    else:
        info = post_api(18, {'folder_id': fid})
        name = info.get('info', {}).get('name', '') if info else ''
        result = post_api(4, {'folder_id': fid, 'folder_name': name, 'folder_description': desc})
    if not result:
        json_err(9, '网络错误')
    json_out({'code': 0, 'msg': '描述设置成功'})

def api_move_file(file_id, folder_id):
    ensure_login()
    result = post_api(7, {'file_id': file_id, 'folder_id': folder_id})
    if not result:
        json_err(9, '网络错误')
    json_out({'code': 0, 'msg': '移动成功'})

def api_move_folder(folder_id, parent_id):
    ensure_login()
    result = post_api(19, {'folder_id': folder_id, 'folder_id_bb': parent_id})
    if not result:
        json_err(9, '网络错误')
    json_out({'code': 0, 'msg': '移动成功'})

def api_parse_share(url, pwd=''):
    """解析分享链接获取直链（无需登录）"""
    # 标准化域名
    url = re.sub(r'https?://[^/]+/(\?webpage=)?', 'https://www.lanzouf.com/', url)
    
    resp = curl_get(url)
    html = resp.get('body', '')
    
    if '文件取消分享了' in html:
        json_err(7, '文件已取消分享')
    
    # 提取文件名
    name = ''
    for pat in [
        r'style="font-size: 30px.*?>(.*?)</div>',
        r'<div class="n_box_3fn".*?>(.*?)</div>',
        r"var filename = '(.*?)';",
        r'div class="b"><span>(.*?)</span>',
    ]:
        m = re.search(pat, html)
        if m:
            name = m.group(1).strip()
            break
    
    # 提取文件大小
    size = ''
    for pat in [r'大小：(.*?)</div>', r'文件大小：</span>(.*?)<br>']:
        m = re.search(pat, html)
        if m:
            size = m.group(1).strip()
            break
    
    # 带密码
    if 'function down_p(){' in html:
        if not pwd:
            json_err(3, '需要提取码')
        
        signs = re.findall(r"'sign':'(.*?)'", html)
        ajaxm = re.findall(r'ajaxm\.php\?file=(\d+)', html)
        
        if len(signs) < 2 or not ajaxm:
            json_err(-1, '解析失败，页面结构可能已变更')
        
        post_data = urlencode({
            'action': 'downprocess',
            'sign': signs[1],
            'p': pwd,
            'kd': 1,
        }).encode('utf-8')
        
        resp = curl_post(f'https://www.lanzouf.com/ajaxm.php?file={ajaxm[0]}', post_data, referer=url)
        try:
            result = json.loads(resp['body'])
        except:
            json_err(-1, '解析返回数据异常')
        
        if result.get('zt') != 1:
            json_err(2, '提取码错误或解析失败')
        
        name = result.get('inf', name)
        durl = result.get('dom', '') + '/file/' + result.get('url', '')
        
        json_out({'code': 0, 'msg': '解析成功', 'name': name, 'size': size, 'durl': durl})
        return
    
    # 不带密码
    m = re.search(r'<iframe.*?name="[\s\S]*?"\ssrc="/(.*?)"', html)
    if not m:
        json_err(-1, '解析失败，无法提取 iframe 地址')
    
    ifurl = 'https://www.lanzouf.com/' + m.group(1)
    resp2 = curl_get(ifurl)
    html2 = resp2.get('body', '')
    
    wp_signs = re.findall(r"wp_sign = '(.*?)'", html2)
    ajaxdata = re.findall(r"ajaxdata = '(.*?)'", html2)
    ajaxm = re.findall(r'ajaxm\.php\?file=(\d+)', html2)
    
    post_data = urlencode({
        'action': 'downprocess',
        'websignkey': ajaxdata[0] if ajaxdata else '',
        'signs': ajaxdata[0] if ajaxdata else '',
        'sign': wp_signs[0] if wp_signs else '',
        'websign': '',
        'kd': 1,
        'ves': 1,
    }).encode('utf-8')
    
    ajaxm_path = ajaxm[0] if ajaxm else ''
    resp3 = curl_post(f'https://www.lanzouf.com/ajaxm.php?file={ajaxm_path}', post_data, referer=ifurl)
    try:
        result = json.loads(resp3['body'])
    except:
        json_err(-1, '解析返回数据异常')
    
    if result.get('zt') != 1:
        json_err(-1, '解析失败')
    
    durl = result.get('dom', '') + '/file/' + result.get('url', '')
    
    json_out({'code': 0, 'msg': '解析成功', 'name': name, 'size': size, 'durl': durl})

def api_get_rec_list():
    ensure_login()
    result = post_api(5, {'folder_id': -1, 'pg': 1})
    files = []
    if isinstance(result.get('text'), list):
        for f in result['text']:
            files.append({'name': f.get('name', ''), 'id': int(f.get('id', 0)), 'size': f.get('size', '')})
    
    result2 = post_api(47, {'folder_id': -1, 'pg': 1})
    dirs = []
    if isinstance(result2.get('text'), list):
        for d in result2['text']:
            dirs.append({'name': d.get('name', ''), 'id': int(d.get('id', 0))})
    
    json_out({'code': 0, 'files': files, 'folders': dirs})

def api_recovery(fid, is_file=True):
    ensure_login()
    task = 30 if is_file else 32
    key = 'file_id' if is_file else 'folder_id'
    result = post_api(task, {key: fid})
    if not result:
        json_err(9, '网络错误')
    json_out({'code': 0, 'msg': '恢复成功'})

def api_delete_rec(fid, is_file=True):
    ensure_login()
    task = 28 if is_file else 29
    key = 'file_id' if is_file else 'folder_id'
    result = post_api(task, {key: fid})
    if not result:
        json_err(9, '网络错误')
    json_out({'code': 0, 'msg': '彻底删除成功'})

def api_clean_rec():
    ensure_login()
    result = post_api(5, {'folder_id': -1, 'pg': 1})
    if isinstance(result.get('text'), list):
        for f in result['text']:
            post_api(28, {'file_id': f['id']})
    result2 = post_api(47, {'folder_id': -1, 'pg': 1})
    if isinstance(result2.get('text'), list):
        for d in result2['text']:
            post_api(29, {'folder_id': d['id']})
    json_out({'code': 0, 'msg': '回收站已清空'})

def api_get_move_folders():
    ensure_login()
    result = post_api(47, {'folder_id': -1, 'pg': 1})
    dirs = []
    if isinstance(result.get('text'), list):
        for d in result['text']:
            dirs.append({'name': d.get('name', ''), 'id': int(d.get('fol_id', d.get('id', 0)))})
    json_out({'code': 0, 'folders': dirs})

def api_get_folder_info(folder_id):
    ensure_login()
    file_result = post_api(5, {'folder_id': folder_id, 'pg': 1})
    files = []
    if isinstance(file_result.get('text'), list):
        for f in file_result['text']:
            files.append({
                'name': f.get('name', ''), 'id': int(f.get('id', 0)),
                'time': f.get('time', ''), 'size': f.get('size', ''),
            })
    
    dir_result = post_api(47, {'folder_id': folder_id, 'pg': 1})
    sub_dirs = []
    if isinstance(dir_result.get('text'), list):
        for d in dir_result['text']:
            sub_dirs.append({'name': d.get('name', ''), 'id': int(d.get('fol_id', d.get('id', 0)))})
    
    json_out({'code': 0, 'folder_id': folder_id, 'files': files, 'sub_folders': sub_dirs})

# ==================== 命令行入口 ====================

def print_usage():
    print("""
蓝奏云 API 命令行工具 (Python)

用法: python3 lanzou_api.py <命令> [参数...]

认证:
  login -u <用户名> -p <密码>                  登录
  login-cookie -y <ylogin> -p <phpdisk_info>   Cookie 登录
  logout                                        注销

文件操作:
  ls [-f <文件夹ID>]                            文件列表 (默认根目录)
  upload <文件路径> [-d <文件夹ID>]             上传文件
  download <文件ID> [-o <保存路径>]             下载文件
  delete <文件ID>                               删除文件
  durl <文件ID>                                 获取直链

文件夹操作:
  ls-dir [-f <父文件夹ID>]                      文件夹列表
  mkdir <名称> [-p <父文件夹ID>] [-d <描述>]   创建文件夹
  rmdir <文件夹ID>                              删除文件夹
  rename-dir <文件夹ID> <新名称>                重命名文件夹
  dir-info <文件夹ID>                           文件夹详情
  path [文件夹ID]                               目录列表

分享与提取码:
  share-info <ID> [-t file|folder]              分享信息
  set-pwd <ID> <密码> [-t file|folder]         设置提取码
  set-desc <ID> <描述> [-t file|folder]        设置描述
  parse <分享链接> [-p <提取码>]               解析分享链接获取直链
  resolve <分享链接> [-p <提取码>]             获取分享直链

移动:
  move-file <文件ID> <目标文件夹ID>             移动文件
  move-dir <文件夹ID> <目标父文件夹ID>         移动文件夹
  move-folders                                  可用目标文件夹列表

回收站:
  rec-list                                      回收站列表
  rec-recover <ID> [-t file|folder]             恢复
  rec-delete <ID> [-t file|folder]              彻底删除
  rec-clean                                     清空回收站

账号信息:
  vip-status                                    查看 VIP 状态和限制
""")

def parse_args(argv, opts):
    result = {}
    i = 0
    while i < len(argv):
        if argv[i] in opts:
            result[opts[argv[i]]] = argv[i+1] if i+1 < len(argv) else ''
            i += 2
        else:
            i += 1
    return result

def main():
    if len(sys.argv) < 2:
        print_usage()
        sys.exit(0)
    
    action = sys.argv[1]
    args_raw = sys.argv[2:]
    
    if action == 'login':
        a = parse_args(args_raw, {'-u': 'user', '-p': 'pass'})
        if not a.get('user') or not a.get('pass'):
            json_err(400, '用法: login -u 用户名 -p 密码')
        api_login(a['user'], a['pass'])
    
    elif action == 'login-cookie':
        a = parse_args(args_raw, {'-y': 'ylogin', '-p': 'phpdisk_info'})
        if not a.get('ylogin') or not a.get('phpdisk_info'):
            json_err(400, '用法: login-cookie -y <ylogin> -p <phpdisk_info>')
        api_login_cookie(a['ylogin'], a['phpdisk_info'])
    
    elif action == 'logout':
        api_logout()
    
    elif action == 'ls':
        a = parse_args(args_raw, {'-f': 'folder_id'})
        api_get_file_list(int(a.get('folder_id', -1)))
    
    elif action == 'ls-dir':
        a = parse_args(args_raw, {'-f': 'folder_id'})
        api_get_dir_list(int(a.get('folder_id', -1)))
    
    elif action == 'upload':
        if not args_raw:
            json_err(400, '用法: upload <文件路径> [-d <文件夹ID>]')
        a = parse_args(args_raw[1:], {'-d': 'folder_id'})
        api_upload_file(args_raw[0], int(a.get('folder_id', -1)))
    
    elif action == 'download':
        if not args_raw:
            json_err(400, '用法: download <文件ID> [-o <保存路径>]')
        a = parse_args(args_raw[1:], {'-o': 'save_path'})
        api_download_file(int(args_raw[0]), a.get('save_path'))
    
    elif action == 'delete':
        if not args_raw:
            json_err(400, '用法: delete <文件ID>')
        api_delete(int(args_raw[0]), True)
    
    elif action == 'rmdir':
        if not args_raw:
            json_err(400, '用法: rmdir <文件夹ID>')
        api_delete(int(args_raw[0]), False)
    
    elif action == 'mkdir':
        if not args_raw:
            json_err(400, '用法: mkdir <名称> [-p <父文件夹ID>] [-d <描述>]')
        a = parse_args(args_raw[1:], {'-p': 'parent_id', '-d': 'desc'})
        api_mkdir(int(a.get('parent_id', -1)), args_raw[0], a.get('desc', ''))
    
    elif action == 'rename-dir':
        if len(args_raw) < 2:
            json_err(400, '用法: rename-dir <文件夹ID> <新名称>')
        api_rename_dir(int(args_raw[0]), args_raw[1])
    
    elif action == 'rename-file':
        if len(args_raw) < 2:
            json_err(400, '用法: rename-file <文件ID> <新名称>')
        ensure_login()
        require_vip('重命名文件')
        result = post_api(46, {'file_id': int(args_raw[0]), 'file_name': args_raw[1], 'type': 2})
        if not result:
            json_err(9, '网络错误')
        if result.get('zt') == 1:
            json_out({'code': 0, 'msg': '重命名成功'})
        else:
            json_err(-1, '重命名失败')
    
    elif action == 'durl':
        if not args_raw:
            json_err(400, '用法: durl <文件ID>')
        api_get_durl(int(args_raw[0]))
    
    elif action == 'share-info':
        if not args_raw:
            json_err(400, '用法: share-info <ID> [-t file|folder]')
        a = parse_args(args_raw[1:], {'-t': 'type'})
        is_file = a.get('type', 'file') != 'folder'
        api_get_share_info(int(args_raw[0]), is_file)
    
    elif action == 'set-pwd':
        if len(args_raw) < 2:
            json_err(400, '用法: set-pwd <ID> <密码> [-t file|folder]')
        a = parse_args(args_raw[2:], {'-t': 'type'})
        is_file = a.get('type', 'file') != 'folder'
        api_set_passwd(int(args_raw[0]), args_raw[1], is_file)
    
    elif action == 'set-desc':
        if len(args_raw) < 2:
            json_err(400, '用法: set-desc <ID> <描述> [-t file|folder]')
        a = parse_args(args_raw[2:], {'-t': 'type'})
        is_file = a.get('type', 'file') != 'folder'
        api_set_desc(int(args_raw[0]), args_raw[1], is_file)
    
    elif action in ('parse', 'resolve'):
        if not args_raw:
            json_err(400, '用法: parse <分享链接> [-p <提取码>]')
        a = parse_args(args_raw[1:], {'-p': 'pwd'})
        api_parse_share(args_raw[0], a.get('pwd', ''))
    
    elif action == 'move-file':
        if len(args_raw) < 2:
            json_err(400, '用法: move-file <文件ID> <目标文件夹ID>')
        api_move_file(int(args_raw[0]), int(args_raw[1]))
    
    elif action == 'move-dir':
        if len(args_raw) < 2:
            json_err(400, '用法: move-dir <文件夹ID> <目标父文件夹ID>')
        api_move_folder(int(args_raw[0]), int(args_raw[1]))
    
    elif action == 'move-folders':
        api_get_move_folders()
    
    elif action == 'dir-info':
        if not args_raw:
            json_err(400, '用法: dir-info <文件夹ID>')
        api_get_folder_info(int(args_raw[0]))
    
    elif action == 'path':
        ensure_login()
        fid = int(args_raw[0]) if args_raw else -1
        result = post_api(47, {'folder_id': fid, 'pg': 1})
        dirs = []
        if isinstance(result.get('text'), list):
            for d in result['text']:
                dirs.append({'name': d.get('name', ''), 'id': int(d.get('fol_id', d.get('id', 0)))})
        json_out({'code': 0, 'current_id': fid, 'folders': dirs})
    
    elif action == 'rec-list':
        api_get_rec_list()
    
    elif action == 'rec-recover':
        if not args_raw:
            json_err(400, '用法: rec-recover <ID> [-t file|folder]')
        a = parse_args(args_raw[1:], {'-t': 'type'})
        is_file = a.get('type', 'file') != 'folder'
        api_recovery(int(args_raw[0]), is_file)
    
    elif action == 'rec-delete':
        if not args_raw:
            json_err(400, '用法: rec-delete <ID> [-t file|folder]')
        a = parse_args(args_raw[1:], {'-t': 'type'})
        is_file = a.get('type', 'file') != 'folder'
        api_delete_rec(int(args_raw[0]), is_file)
    
    elif action == 'rec-clean':
        api_clean_rec()
    
    elif action == 'vip-status':
        is_vip = detect_vip()
        json_out({'vip': is_vip, 'type': 'VIP会员' if is_vip else '免费版', 'max_upload': '550MB' if is_vip else '100MB'})
    
    else:
        print(f"未知命令: {action}\n")
        print_usage()
        sys.exit(1)

if __name__ == '__main__':
    main()
