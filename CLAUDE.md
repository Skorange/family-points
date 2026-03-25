# 南瓜之家 - 项目说明

## 部署规范

**⚠️ 每次部署前必须确认 docker-compose.yml 包含项目名隔离：**

```yaml
name: pumpkin-php
```

### 正确的部署命令
```bash
# 启动
docker compose -p pumpkin-php up -d --build

# 停止（只停此项目，不影响其他）
docker compose -p pumpkin-php down

# 重建
docker compose -p pumpkin-php down && docker compose -p pumpkin-php up -d --build
```

### 必须避免的操作
- ❌ `docker compose down`（不带 `-p`）→ 停掉所有默认项目容器
- ❌ `network_mode: "host"` → 绕过隔离，端口冲突
- ❌ 硬编码 `container_name: family-points` → 与其他项目冲突
- ❌ 在同一目录下混合部署多个 compose 项目

## 技术栈
- 后端：PHP + MySQL
- 前端：PWA (HTML5/JS)
- 部署：Docker + GitHub Webhook

## 数据库
- MySQL 用户：familyuser
- MySQL 密码：family_pass_2024
- 数据库名：family_points
