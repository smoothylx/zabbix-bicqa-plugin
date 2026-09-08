#!/usr/bin/env bash
#
# Zabbix BIC-QA 插件部署脚本
# 适用环境：CentOS 8 + Nginx + PHP-FPM（Zabbix 7.0 LTS，RPM 安装）
#
# 前提：
#   1. 本脚本与 bicqa/ 目录放在同一目录（从 bicqa.tar.gz 解压后即可）
#   2. 以 root 身份执行
#
# 用法：bash deploy-centos8-nginx.sh

set -euo pipefail

UI_DIR="/usr/share/zabbix"             # Zabbix 前端根目录（RPM 实际布局，无 /ui 子目录）
MODULES_DIR="${UI_DIR}/modules"        # 模块目录

echo "==> [1/4] 校验前端目录"
if [ ! -d "$MODULES_DIR" ]; then
    echo "[错误] 未找到 $MODULES_DIR"
    echo "  请用以下命令定位真实 modules 目录，再修改脚本顶部 UI_DIR 后重试："
    echo "    find / -type d -name scheduledreports 2>/dev/null"
    exit 1
fi
if [ ! -f "bicqa/manifest.json" ]; then
    echo "[错误] 当前目录下没有 bicqa/ 目录，请先解压 bicqa.tar.gz 到脚本同目录。"
    exit 1
fi
echo "  -> 前端目录：$MODULES_DIR"

echo "==> [2/4] 复制模块文件"
if [ -d "$MODULES_DIR/bicqa" ]; then
    echo "  -> 检测到旧版本，先移除"
    rm -rf "$MODULES_DIR/bicqa"
fi
cp -r bicqa "$MODULES_DIR/"
echo "  -> 已复制到 $MODULES_DIR/bicqa"

echo "==> [3/4] 检测 php-fpm 运行用户"
PHP_USER=$(ps -eo user,comm | awk '$2 ~ /php-fpm/ && $1 != "root" {print $1; exit}')
if [ -z "$PHP_USER" ]; then
    PHP_USER=$(grep -rhoE '^[[:space:]]*user[[:space:]]*=[[:space:]]*[A-Za-z0-9_-]+' /etc/php-fpm.d/ 2>/dev/null | tail -1 | grep -oE '[A-Za-z0-9_-]+$')
fi
PHP_USER=${PHP_USER:-apache}
echo "  -> php-fpm 用户：$PHP_USER"

echo "==> [4/4] 授权 data 目录"
chown -R "$PHP_USER":"$PHP_USER" "$MODULES_DIR/bicqa/data"
chmod 775 "$MODULES_DIR/bicqa/data"
echo "  -> data 目录已授权"

echo ""
echo "================================================================"
echo "  自动部署完成！还需手动完成以下步骤："
echo ""
echo "  ① Nginx 加 deny 规则（防止 API Key 被浏览器直读）"
echo "     编辑 /etc/nginx/conf.d/zabbix.conf，在 server { } 块内加："
echo ""
echo "         location ^~ /modules/bicqa/data/ { deny all; }"
echo ""
echo "     （若你的 Zabbix 访问 URL 带 /zabbix 前缀，则路径相应前移）"
echo "     改完执行：nginx -t && systemctl reload nginx"
echo ""
echo "  ② 后台启用模块"
echo "     登录 Zabbix -> Administration -> General -> Modules"
echo "     -> Scan directory -> 把「BIC-QA 智能分析」切换为 Enabled"
echo ""
echo "  ③ （可选）检查 PHP 超时"
echo "     Zabbix 官方 php-fpm 配置通常已设 max_execution_time=300；"
echo "     若没有，请在 /etc/php-fpm.d/zabbix.conf 加："
echo "         php_value[max_execution_time] = 300"
echo "     然后 systemctl restart php-fpm"
echo "================================================================"
