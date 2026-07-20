# 蓝奏云 API Skill

蓝奏云网盘完整 API 操作技能，支持 Codex CLI 调用。

## 功能

- **认证**: 自动获取 acw_sc__v2 Cookie + 用户名密码登录
- **文件**: 上传、下载、删除、获取直链
- **文件夹**: 创建、列表、重命名、删除、详情
- **分享**: 解析分享链接（无需登录）、获取提取码、设置描述
- **提取码**: 设置/修改文件和文件夹提取码
- **回收站**: 列表、恢复、彻底删除、清空
- **VIP 检测**: 自动识别账号等级并拦截受限操作

## 安装

```bash
# 克隆到 Codex skills 目录
git clone https://github.com/lantianhcgp/lanzou-cloud-api-skill.git ~/.codex/skills/lanzou-cloud-api
```

## 使用

### 首次登录

```bash
~/.codex/skills/lanzou-cloud-api/scripts/lanzou login -u 手机号 -p 密码
```

### 常用命令

```bash
S=~/.codex/skills/lanzou-cloud-api/scripts/lanzou

$S vip-status                                    # 查看 VIP 状态
$S ls                                             # 文件列表
$S upload file.zip                                # 上传文件
$S durl <文件ID>                                  # 获取直链
$S parse "分享链接" -p 提取码                      # 解析分享链接
$S mkdir "文件夹名"                                # 创建文件夹
$S set-pwd <文件ID> <密码>                         # 设置提取码
```

### 完整命令列表

| 类别 | 命令 |
|------|------|
| 认证 | `login`, `login-cookie`, `logout` |
| 文件 | `ls`, `upload`, `download`, `delete`, `durl` |
| 文件夹 | `ls-dir`, `mkdir`, `rmdir`, `rename-dir`, `dir-info`, `path` |
| 分享 | `parse`, `resolve`, `share-info`, `set-pwd`, `set-desc` |
| 移动 | `move-file`, `move-dir`, `move-folders` |
| 回收站 | `rec-list`, `rec-recover`, `rec-delete`, `rec-clean` |
| 账号 | `vip-status` |

## VIP 限制

| 功能 | 免费版 | VIP |
|------|--------|-----|
| 单文件上传上限 | 100MB | 550MB |
| 重命名文件 | ❌ | ✅ |
| 关闭提取码 | ❌ | ✅ |
| 回收站 | ❌ | ✅ |
| 手机端分享 APK | ❌ | ✅ |

## 依赖

- Python 3.8+
- Node.js（用于自动获取 acw_sc__v2 Cookie）

## 参考项目

- [zaxtyson/LanZouCloud-API](https://github.com/zaxtyson/LanZouCloud-API) - 蓝奏云 Python API（805⭐）
- [hanximeng/LanzouAPI](https://github.com/hanximeng/LanzouAPI) - 蓝奏云直链解析

## 免责声明

本项目仅供学习交流使用，不保证稳定性，请自行承担使用风险。
