# GEOFlow 多客户改造：阶段 0A 版本与能力清单

> 执行包：0A（版本、工作区和运行时合同盘点）  
> 执行日期：2026-08-22（Asia/Shanghai）  
> 工作区：`D:\GEOFlow-2.3.0`  
> 执行模式：GEOFlow `development` / 只读发现

## 结论

- **代码版本已锁定（静态证据）**：分支 `codex/phase0`，HEAD `9454c43cd7c40730b849410cc0577936402a2d96`，工作区无未提交变更；`version.json` 为 `2.3.0`，发布日期 `2026-08-09`。
- **依赖合同已锁定（静态证据）**：Laravel Framework `^12.0`（lock：`v12.64.0`）、PHP `^8.3`、Laravel AI `^0.10.3`（lock：`v0.10.3`）、Horizon `^5.45`（lock：`v5.45.6`）、Sanctum `^4.3`、Redis/队列相关能力已声明。
- **数据库合同已识别（静态证据）**：生产/legacy schema 以 PostgreSQL migration 为主；另有仅在 `APP_ENV=testing` 且 driver 为 SQLite 时启用的最小测试 schema。两套结构必须在阶段 1 分别验证。
- **运行时发现能力未通过**：当前 Windows 环境找不到 `php` 和 `composer`，因此不能执行 `artisan`、`bin/geoflow`、迁移、测试、实际 route:list 或数据库连接检查。不得把静态结果描述为运行时通过。
- **GEOFlow 专用 discovery/preflight 脚本未找到**：工作区未发现 `scripts/discover_geoflow_workspace.py` 或 `scripts/geoflow_preflight.sh`；未复制或伪造替代脚本。

> 后续通过项目 Docker 运行时完成了部分原先的环境验证：容器内 PHP 8.4、artisan、GEOFlow CLI 和 PostgreSQL 均可用。下文“运行时复核”以实际命令结果为准；宿主机仍未安装 PHP/Composer。

## 版本与工作区

| 项目 | 结果 | 证据类型 |
| --- | --- | --- |
| 分支 | `codex/phase0` | 静态：`git branch -vv` |
| HEAD | `9454c43cd7c40730b849410cc0577936402a2d96` | 静态：`git rev-parse HEAD` |
| HEAD 时间 | `2026-08-22T14:23:04+08:00` | 静态：`git log -1` |
| 工作区 | clean（无 porcelain 输出） | 静态：`git status --porcelain=v1` |
| 应用版本 | `2.3.0` | 静态：`version.json` |
| 上游 remotes | `origin=yaojingang/GEOFlow`；`destination=jiayubing/GEO` | 静态：`git remote -v` |

本次未比较远端主线内容，也未执行网络发布、拉取或写操作。

## 依赖与运行时能力

### 已确认（静态）

- `composer.json` 声明：Laravel 12、Laravel AI `^0.10.3`、Horizon `^5.45`、Reverb、Sanctum `^4.3`。
- `composer.lock` 锁定：Laravel AI `v0.10.3`、Framework `v12.64.0`、Horizon `v5.45.6`、Sanctum `v4.3.1`。
- `vendor/autoload.php` 存在，因此依赖目录已入工作区；没有 PHP 不能证明 autoload 可运行。
- `.env.example` 默认合同：`DB_CONNECTION=pgsql`、`QUEUE_CONNECTION=redis`、`CACHE_STORE=database`、Redis 客户端为 `phpredis`。
- 代码中实际使用 Laravel AI Agent/Embedding、Horizon provider 和 Redis 队列配置；未发现以向量库 SaaS 替换现有 AI SDK 的事实。

### 初始未验证项（宿主机）

| 能力 | 状态 | 原因 |
| --- | --- | --- |
| PHP 8.3+ | `UNVERIFIED` | `php` executable not found |
| Composer | `UNVERIFIED` | `composer` executable not found |
| `php artisan --version` / `route:list` | `UNVERIFIED` | PHP 不可用 |
| `php bin/geoflow --help` | `UNVERIFIED` | PHP 不可用 |
| Laravel migration status/fresh migration | `UNVERIFIED` | PHP 与安全只读 DB 证据均不可用 |
| PostgreSQL 连接与 legacy 数据计数 | `UNVERIFIED` | 0A 未使用数据库凭据；留给 0B 的只读预检 |
| SQLite 测试迁移实际执行 | `UNVERIFIED` | PHP/PHPUnit 不可用 |
| 队列/Horizon 实际运行 | `UNVERIFIED` | 未启动进程；PHP 不可用 |
| AI provider/API 调用 | `UNVERIFIED` | 未调用外部服务 |

可用的辅助工具：Python `3.13.14`、Node `v24.16.0`、npm `11.13.0`。这些工具不能替代 Laravel/PHP 运行时证据。

### 运行时复核（Docker，2026-08-22）

| 检查 | 结果 | 证据 |
| --- | --- | --- |
| PHP/Laravel | `PASS`：Laravel Framework `12.64.0`（应用容器 PHP 8.4 镜像） | `docker compose exec -T app php artisan --version` |
| GEOFlow CLI | `PASS`：GEOFlow CLI `0.2.0`，帮助信息可加载 | `docker compose exec -T app php bin/geoflow --help` |
| PostgreSQL | `PASS`：容器 healthy，`pg_isready` accepting connections | `docker compose ps` / PostgreSQL healthcheck |
| 路由装载 | `PASS`：`php artisan route:list --json` 成功；API、后台、Horizon、Sanctum 路由可见 | Docker artisan 输出 |
| 版本映射测试 | `PASS`：3 tests / 11 assertions | `tests/Unit/ReleaseVersionMappingTest.php` |
| 分发 schema 静态合同测试 | `PASS`：1 test / 15 assertions | `tests/Unit/DistributionSchemaMigrationTest.php` |
| migration status | `PASS（只读）`：可读取状态；已有多项 pending migration | `php artisan migrate:status` |
| migration 执行 | `NOT RUN` | 本阶段禁止写入/迁移 |

宿主机没有 PHP/Composer 仍不再构成阶段 0 的唯一运行时阻塞，因为项目 Docker 运行时已提供可复现执行层。后续应优先继续使用同一容器，避免宿主机与部署版本漂移。

## 路由、CLI、迁移和测试静态盘点

- 路由文件：`routes/api.php`、`routes/channels.php`、`routes/console.php`、`routes/web.php`。
- API 静态入口为 `/api/v1`，包含 Sanctum/Bearer 中间件、tasks/materials/articles/jobs/catalog 等 scope；发布入口包括 `articles/{article}/publish`。实际注册路径和中间件链尚未通过 `route:list` 验证。
- CLI 入口存在：`artisan` 与 `bin/geoflow`；两者均依赖 PHP，当前不可执行。
- migration 文件数：124（静态文件计数）。关键结构由 `2026_04_18_120000_geoflow_legacy_schema.php`（pgsql guard）与 `2026_04_18_120002_sqlite_geoflow_minimal_for_testing.php`（sqlite + testing guard）分别承载。
- 静态规模计数：53 个 Model、123 个 Service、10 个 Job、126 个测试文件。计数用于范围盘点，不是运行时覆盖率证据。

## 阶段 1 输入与阻塞

1. 0B 已通过应用容器的数据库连接完成基础计数：`tasks`、`articles`、`knowledge_bases`、`keyword_libraries`、`title_libraries`、`image_libraries`、`authors`、`categories`、`distribution_channels`、`task_distribution_channels`、`article_distributions`、`url_import_jobs`、`enterprise_knowledge_projects` 均为 0；`manual_publications` 尚未建立（对应 migration pending）。这表示当前库是空 legacy 基线，不等于已完成迁移验证。
2. 0B 必须同时检查 testing-only SQLite schema 与 PostgreSQL schema 的表/列/约束差异；迁移前不得假设两套结构等价。
3. Docker 运行时已解除 artisan/测试/CLI 的执行阻塞；仍不得执行 pending migration，除非另有明确授权和回滚边界。
4. 0C 的渠道模型决策依赖 0B 的真实 `distribution_channels` / `task_distribution_channels` 使用关系；本 0A 不预先选择独占或共享 membership。

## 执行证据与安全边界

本包仅读取 Git 元数据、版本/依赖文件、配置样例（仅输出键名/驱动，不输出值）、路由、迁移、模型/服务/Job/测试文件名和源码中的能力声明。未读取或输出 API Key、Cookie、Token、数据库密码、渠道 secret 或客户资料正文；未写数据库、未创建迁移、未启动队列、未发布文章、未调用外部渠道写接口。

**0A 状态：静态盘点完成；Docker 运行时复核通过。** 宿主机 PHP/Composer 缺失已由项目容器覆盖；剩余阶段 0 阻塞转移到 0B 的完整 invariant 检查、SQLite fresh migration 的独立验证，以及 pending migration 的明确处理策略。未执行任何业务数据库写入。
