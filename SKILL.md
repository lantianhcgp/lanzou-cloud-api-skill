---
name: lanzou-cloud-api
description: 蓝奏云网盘完整 API 操作技能。支持登录、文件上传/下载/删除/重命名、文件夹管理、分享链接解析、直链提取、回收站管理、批量操作等全部功能。当用户提到蓝奏云、蓝奏网盘、lanzou、lanzouq、woozooo、直链解析、网盘操作时触发此技能。
---

# 蓝奏云 API 操作技能

本技能提供对蓝奏云网盘的完整操作能力，基于蓝奏云 Web API 实现。

## 核心概念

- **域名**: 主域 `lanzouo.com`，备用 `lanzouw.com` / `lanzoui.com` / `lanzoux.com`
- **API 端点**: `pc.woozooo.com/doupload.php`（文件操作）、`pc.woozooo.com/account.php`（账户）、`pc.woozooo.com/mydisk.php`（登录）
- **认证方式**: Cookie 认证（`ylogin` + `phpdisk_info`）
- **分享链接格式**: `https://www.lanzoui.com/i{5位以上字母数字}`（文件）、`https://www.lanzoui.com/b{7位以上字母数字}`（文件夹）
- **单文件大小限制**: 普通用户 100MB，VIP 可调高
- **允许上传格式**: 有限制（详见 scripts/lanzou_api.py 中的 VALID_SUFFIXES）

## 使用方式

通过 `scripts/lanzou` shell 脚本调用，或直接使用 `scripts/lanzou_api.py` Python 脚本。

### 认证

**方式一：命令行自动登录（推荐）**
```bash
SCRIPT=~/.codex/skills/lanzou-cloud-api/scripts/lanzou
$SCRIPT login -u 用户名 -p 密码
```
脚本会自动处理蓝奏云的 `acw_sc__v2` JS 验证，通过 Node.js 执行反混淆脚本获取 Cookie，无需手动操作。需要系统已安装 Node.js。

**方式二：手动设置 Cookie**
如果自动登录失败，可手动获取：
1. 用浏览器登录 https://pc.woozooo.com/mydisk.php
2. 提取 Cookie 中的 `ylogin` 和 `phpdisk_info` 值
3. 保存到 `~/.lanzou_cookie.json`:
```json
{"ylogin": "你的ylogin值", "phpdisk_info": "你的phpdisk_info值"}
```

或用命令：
```bash
$SCRIPT login-cookie -y <ylogin值> -p <phpdisk_info值>
```

### 文件操作

```bash
SCRIPT=~/.codex/skills/lanzou-cloud-api/scripts/lanzou

# 查看文件列表（-1 为根目录）
$SCRIPT ls [-f 文件夹ID]

# 上传文件
$SCRIPT upload <本地文件路径> [-d 目标文件夹ID]

# 下载文件（by ID）
$SCRIPT download <文件ID> [-o 保存路径]


# 删除文件
$SCRIPT delete <文件ID>

# 重命名文件（需 VIP）
$SCRIPT rename-file <文件ID> <新文件名>

# 获取文件直链
$SCRIPT durl <文件ID>

# 设置文件提取码（2-6位）
$SCRIPT set-pwd <文件ID> <密码>

# 获取文件分享信息
$SCRIPT share-info <文件ID>

# 设置文件描述
$SCRIPT set-desc <文件ID> <描述文本>
```

### 文件夹操作

```bash
# 查看文件夹列表
$SCRIPT ls-dir [-f 父文件夹ID]

# 创建文件夹
$SCRIPT mkdir <文件夹名称> [-p 父文件夹ID] [-d 描述]

# 删除文件夹
$SCRIPT rmdir <文件夹ID>

# 重命名文件夹
$SCRIPT rename-dir <文件夹ID> <新名称>

# 移动文件到文件夹
$SCRIPT move-file <文件ID> <目标文件夹ID>

# 移动文件夹
$SCRIPT move-dir <文件夹ID> <目标父文件夹ID>

# 获取文件夹完整路径
$SCRIPT path [文件夹ID]

# 获取文件夹详情（含子文件列表）
$SCRIPT dir-info <文件夹ID>

```

### 分享链接解析

```bash
# 解析分享链接获取文件信息
$SCRIPT parse <分享链接> [-p 提取码]

# 获取分享直链（不登录）
$SCRIPT resolve <分享链接> [-p 提取码]
```

### 回收站操作

```bash
# 查看回收站
$SCRIPT rec-list

# 恢复文件
$SCRIPT rec-recover <文件ID> [-f 文件夹ID=文件夹类型]

# 彻底删除回收站文件
$SCRIPT rec-delete <文件ID> [-f]

# 清空回收站
$SCRIPT rec-clean
```





## 错误码

| 码 | 含义 |
|----|------|
| 0 | 成功 |
| -1 | 失败 |
| 1 | ID 错误 |
| 2 | 密码错误 |
| 3 | 缺少密码 |
| 6 | URL 无效 |
| 7 | 文件已取消分享 |
| 8 | 路径错误 |
| 9 | 网络错误 |
| 10 | 需要验证码 |
| 11 | 官方限制 |

## 注意事项

- 蓝奏云会频繁更换域名，脚本自动尝试多个备用域名
- 上传文件有格式限制，非白名单格式会自动添加伪装后缀
- VIP 用户可突破 100MB 限制、支持重命名文件
- 分享链接中的 `webpage` 参数需要特殊处理
- Cookie 有效期有限，失效后需重新获取

## VIP 限制

脚本会自动检测账号 VIP 状态并在执行受限操作时提示。

| 功能 | 免费版 | VIP |
|------|--------|-----|
| 单文件上传上限 | 100MB | 550MB |
| 重命名文件 | ❌ | ✅ |
| 关闭提取码 | ❌ | ✅ |
| 回收站 | ❌ | ✅ |
| 手机端分享 APK | ❌ | ✅ |
| 在线预览 | ❌ | ✅ |

使用 `vip-status` 命令查看当前账号状态。
