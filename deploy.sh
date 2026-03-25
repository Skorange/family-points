#!/bin/bash
# FamilyPoints 部署脚本

set -e

WEBROOT="/var/www/family-points"
LOG_FILE="/var/log/family-points-deploy.log"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

log "=== 开始部署 ==="

cd "$WEBROOT"

# 拉取最新代码
log "拉取最新代码..."
git fetch origin
git reset --hard origin/main

# 复制 .env 文件（如果不存在）
if [ ! -f .env ]; then
    log "创建 .env 文件..."
    cp .env.example .env 2>/dev/null || true
fi

# 重启 Docker 容器
log "重启 Docker 容器..."
docker-compose down
docker-compose up -d --build

log "=== 部署完成 ==="
