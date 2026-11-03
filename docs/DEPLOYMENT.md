# PuffSqueeze 长期部署准备

代码准备日期：2026-10-16。主网站和数据库尚未公开上线，尚未购买或连接主网站服务器。

玩偶 Wi-Fi 控制转发服务已单独部署至 `https://puffsqueeze-grip.1850190464.workers.dev`，Cloudflare 确认 `GRIP_ROOMS` 的 SQLite Durable Object 绑定已创建；详细记录见 `deploy/wireless/README.md`。这不代表主网站、网页无线控制或小智/铜箔合并固件已经接通。

## 当前主网站路线：Serv00 免费托管

用户选择免费、免绑卡方案，已提交 Serv00 注册申请，当前等待人工审核。对应上传包生成器、PHP 入口与路由设置在 `deploy/serv00/`，具体步骤见 `deploy/serv00/README.md`。该路线运行托管 PHP 8.3 与 MySQL，主网站上传和数据库迁移需在账号获批后进行。以下 Docker 章节保留为另有 Linux 服务器时的部署方式。

## 已准备的结构

```text
HTTPS 域名 → Caddy → Nginx（React 网页）
                       └ /api/* → PHP-FPM → MariaDB 持久卷
```

入口是 `deploy/compose.yaml`。数据库和 PHP-FPM 不向公网开放端口。Caddy 在域名解析、80/443 端口满足要求后申请和续期证书。Node 只在镜像构建时使用；运行网站不需要 `npm run dev` 或 PHP 内置开发服务器。

部署需要：支持 Docker Engine 与 Compose 插件的 Linux 服务器、指向服务器的域名，以及域名对应的 HTTPS 网络条件。服务器套餐和托管平台尚未选择。

## 1. 准备代码与密钥

上传项目源码与 `package-lock.json`，保留 `src`、`public`、`backend`、`deploy` 和构建配置文件。`node_modules`、旧 `dist`、本机 `.env.local`、`backend/.env`、数据库备份和硬件固件不进入构建上下文。

在服务器的项目目录执行：

```sh
cd deploy
cp .env.example .env
```

编辑 `deploy/.env`：

- `SITE_ADDRESS`：真实域名，例如 `puffsqueeze.your-domain.com`。
- `DB_PASSWORD` 与 `DB_ROOT_PASSWORD`：两个不同的随机强密码。可以分别执行 `openssl rand -hex 24` 生成。
- `GEMINI_API_KEY`：后端 AI 密钥。空值时普通页面可运行，AI 接口需要配置后才能使用。

密钥只放服务器环境，前端不包含 AI 密钥。生产前端默认调用同一网站的 `/api`。本机 `.env.local` 仍用于本地 PHP 代理；`.env.production` 清空生产接口配置，防止编译成用户电脑的 localhost 地址。

## 2. 迁移现有数据库（要保留旧账号时）

1. 在本机 phpMyAdmin 选择 `puffsqueeze`，导出**所有表**为 SQL，包括账号、动态、压力、聊天、养成等表。
2. 把 SQL 放在服务器的非公开目录，例如项目目录下 `puffsqueeze.sql`；设置文件权限为仅管理员可读。备份里包含用户个人数据，不放进 Git 或网页目录。
3. 只启动数据库：

```sh
docker compose up -d db
docker compose logs db
```

数据库就绪后，导入上传的 SQL（以下命令在 `deploy` 目录运行）：

```sh
docker compose exec -T db sh -c 'export MYSQL_PWD="$MARIADB_PASSWORD"; exec mariadb -u"$MARIADB_USER" "$MARIADB_DATABASE"' < ../puffsqueeze.sql
```

新网站没有旧数据库时可跳过导入。现有容器数据库有数据时，不要反复导入旧 SQL，以免覆盖较新的记录。

## 3. 构建与启动

```sh
docker compose up -d --build
docker compose logs --tail=100 php web gateway
```

PHP 启动前会自动运行 `backend/scripts/prepare-production.php`：补齐表结构、把旧明文密码转为哈希并清空旧明文列。已有账号密码保持可用，新注册密码至少 8 个字符。此迁移可以重复运行。

打开 `https://你的域名/`。新部署需要实际检查注册、登录、退出、个人资料、压力记录、动态发布、账号同步和 HTTPS。配置文件已编写，但本次没有运行构建、容器或这些检查。

## 4. 本机 XAMPP 开发继续使用

MySQL 与 PHP 保持运行，现有 `.env.local` 继续指向本机 PHP。需要主动完成所有旧密码与表结构迁移时，在项目根目录运行：

```powershell
& 'D:\xampp\php\php.exe' 'backend\scripts\prepare-production.php'
```

执行前先导出数据库备份。这是数据迁移命令，不是测试；本次未替你执行。旧账号也可以在下次成功登录时单独升级密码存储。旧登录仅保存用户 ID、没有有效令牌时，需要重新登录。

## 5. 记录保存的范围与规则

| 内容 | 保存方式 |
| --- | --- |
| 账号、个人资料、公开动态、压力、AI 后端日志 | 原有业务表，接口现在要求登录身份 |
| 动物选择、各动物表情、便签、收藏诗卡、钓鱼图鉴、AI 页面消息、离线养成缓存、成长显示缓存 | 每个账号的 `puffsqueeze_account_documents` |
| 页面进入/离开与时长、问答作答与结果、钓鱼抛竿、完成的飞行、按压和离线养成 | `puffsqueeze_account_records` 与 `puffsqueeze_usage_logs` |
| 网络失败时的待上传内容 | 该浏览器 IndexedDB，重新打开并登录原账号后继续补传 |

- 只保存已经提交的操作和收藏内容，不记录逐键输入。未收藏的小诗和未提交的草稿沿用原来的页面行为。
- 断线期间保留浏览器数据；清空浏览器、无痕窗口关闭或设备损坏会影响尚未上传的记录。界面会提示待同步或存储失败。
- 服务器只在事务提交后确认上传，同一请求 ID 重试不会重复计数或加经验。离线按压会回填统计，离线养成会回到本人对应种类的在线伙伴。
- 先补传待上传内容，再恢复账号资料。首次导入旧浏览器缓存只补服务器缺失的资料；与服务器不同的旧本机数据另存为 `local_import_backup` 记录，便于管理员恢复。
- 同一账号同时在多设备修改同一份便签/收藏等资料时，最近被服务器接受的版本成为当前资料。历史版本在记录表保留；这不是实时多人编辑器。共同养成仍使用独立的事务和成员权限。
- 单条账号记录上限 8 MB、请求上限 12 MB；动态媒体上限 6 MB。聊天消息较长或图片较大时，应关注存储状态并适时导出。
- 本地存储按浏览器来源隔离：`localhost:3000`、局域网 IP 和正式域名不是同一个缓存空间。搬迁前，要在**原来使用的地址**登录并完成同步，再导出数据库。
- 旧 Puff Zen 嵌入游戏可记录进入、退出及停留时间；该旧游戏内部未提供成绩回传接口。Aviator 完成飞行后回传航程、收集数与等级。

## 6. 备份与更新

在服务器 `deploy` 目录运行：

```sh
sh backup.sh
```

输出到 `deploy/backups`，包括数据库当前资料和历史记录；备份目录不在网页根目录中。还应将备份另存到服务器以外的位置。迁移或更新前先备份。

更新源码后重新执行 `docker compose up -d --build`。数据库使用持久卷，容器更新保留数据。`docker compose down -v` 会删除数据库卷，不用于普通更新。

## 7. 上线边界

账号令牌只允许访问本人资料，社交列表只提供公开动态并去掉作者邮箱。退出会在服务器注销当前令牌；个人资料中新增了“修改密码”，验证旧密码后更新并注销其他会话。生产入口对登录、注册、修改密码和 AI 接口限速，错误响应不暴露数据库细节。

仍需部署后的功能与权限验证。旧测试账号的弱密码不会因为哈希迁移而自动变强，正式开放前由账号持有人从个人资料里的“修改密码”更新。旧硬件 HTTP 上传程序现在需要有效的账号认证与正式服务器地址；ESP32-S3 铜箔 IO 测试程序本身不依赖网络。原本的本机 M5 bridge 在生产前端默认关闭，不向公网转发它。

压力统计和日历默认按上海时区计算：`APP_TIMEZONE=Asia/Shanghai`、`DB_TIMEZONE=+08:00`。养成会话和账号原始同步记录仍使用 UTC；养成接口返回带 Z 的 UTC 时间。变更部署时区前需要规划旧数据的日期边界。

数据库持久卷不等于备份；AI 调用仍依赖配置的密钥、网络和供应商服务，不能仅靠部署保证可用。

## 参考

- [PHP 密码哈希](https://www.php.net/manual/en/function.password-hash.php)
- [Docker Compose 环境变量](https://docs.docker.com/compose/how-tos/environment-variables/variable-interpolation/)
- [Caddy 自动 HTTPS](https://caddyserver.com/docs/automatic-https)

本次未运行测试、构建、数据库迁移或线上部署。
