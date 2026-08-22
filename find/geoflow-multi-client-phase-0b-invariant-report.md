# GEOFlow 多客户改造：阶段 0B Legacy Invariant Report

> 执行包：0B（legacy 数据不变量和归属风险报告）  
> 执行日期：2026-08-22（Asia/Shanghai）  
> 代码 HEAD：`9454c43cd7c40730b849410cc0577936402a2d96`  
> 数据库：PostgreSQL 18.6，数据库 `geo_flow`，连接 `pgsql`，主机 `postgres`  
> 查询时间：数据库时间 `2026-08-22 06:42:23+00`

## 结论

- 当前数据库可安全只读访问；数据库连接与查询均通过 Docker 应用/数据库容器完成。
- 当前数据库是**空 legacy 基线**：本次盘点的主要 owner 表和关联表均为 0 行。
- 已执行的孤儿引用检查、NULL 检查（适用字段）和重复风险检查均未发现数据异常；空表结果不等于已验证未来回填逻辑。
- 当前已执行 migration 为 61 条，所有 migration 均为 `Ran`；没有 pending migration。
- `manual_publications`、`manual_publication_transitions`、personas 和 accounts 表已建立，当前均为 0 行，人工发布不变量已完成空基线检查。
- `distribution_channels` 与 `task_distribution_channels` 当前均为空，无法从真实使用关系判断渠道是项目独占还是共享 membership；0C 不能仅凭本次空数据自动作出业务决策。

## 数据库与 schema 基线

| 项目 | 结果 | 证据 |
| --- | --- | --- |
| PostgreSQL server | 18.6 | `artisan db:show` |
| 数据库表总数 | 59 | `artisan db:show` |
| 已执行 migrations | 61，全部 `Ran` | `artisan migrate:status` |
| pending migrations | 0 | `artisan migrate:status` |
| PostgreSQL legacy migration 静态表数 | 25 | `2026_04_18_120000_geoflow_legacy_schema.php` |
| SQLite testing migration 静态表数 | 17 | `2026_04_18_120002_sqlite_geoflow_minimal_for_testing.php` |
| SQLite/PG 关系 | 非等价结构；SQLite 仅为 testing-only 最小结构 | 两个 migration guard 与表清单 |

`manual_publications` 相关表已在本次开发数据库迁移中建立；当前空计数是已验证的 0，而非 schema 缺失。

## Owner 表计数

以下查询通过 PostgreSQL 只读连接执行，结果均为 0：

| 表 | 行数 | 归属意义 |
| --- | ---: | --- |
| `tasks` | 0 | 任务 owner |
| `articles` | 0 | 文章直接/任务归属 |
| `knowledge_bases` / `knowledge_chunks` | 0 / 0 | 知识库及切片 |
| `keyword_libraries` / `keywords` | 0 / 0 | 关键词库及条目 |
| `title_libraries` / `titles` | 0 / 0 | 标题库及条目 |
| `image_libraries` / `images` | 0 / 0 | 图片库及条目 |
| `authors` / `categories` | 0 / 0 | 文章可引用元数据 |
| `distribution_channels` | 0 | 渠道本体 |
| `task_distribution_channels` | 0 | 任务-渠道关系 |
| `article_distributions` | 0 | 文章分发投影 |
| `url_import_jobs` / `url_import_job_logs` | 0 / 0 | URL 导入及日志 |
| `enterprise_knowledge_projects` / `enterprise_knowledge_sources` / `enterprise_knowledge_revisions` | 0 / 0 / 0 | 企业知识流程对象 |
| `task_runs` / `article_reviews` | 0 / 0 | 执行与审核记录 |
| `manual_publications` / transitions | 0 / 0 | 人工发布工单及状态转换 |

## 不变量检查

### 已通过（当前数据集）

| 检查规则 | 结果 | 影响阶段 | 后续 owner |
| --- | ---: | --- | --- |
| `articles.task_id` 指向不存在任务 | 0 | 1B | migration/backfill owner |
| `articles.author_id` 指向不存在作者 | 0 | 1B | migration/backfill owner |
| `articles.category_id` 指向不存在分类 | 0 | 1B | migration/backfill owner |
| `task_distribution_channels` 孤儿任务/渠道 | 0 | 0C/1B | channel model owner |
| `article_distributions` 孤儿文章/渠道 | 0 | 1B/4D | distribution owner |
| `knowledge_chunks` 孤儿知识库 | 0 | 1B/2A | knowledge owner |
| `url_import_job_logs` 孤儿 job | 0 | 1B/5B | URL import owner |
| 文章重复 slug（空表） | 0 | 1B/5C | article owner |
| 渠道重复 name（空表） | 0 | 0C | channel model owner |
| URL 导入重复 normalized URL（空表） | 0 | 1B/5B | URL import owner |
| 当前渠道被多个任务共享 | 0（渠道为空） | 0C | channel model owner |

### 未验证或不适用

| 风险 | 状态 | 原因/处理 |
| --- | --- | --- |
| `manual_publications` 无文章工单 | `PASS（空基线）` | 表已建立，当前 0 行；阶段 1B 回填后需重跑 |
| `manual_publications` 项目归属 | `UNVERIFIED（尚未项目化）` | 当前还没有客户项目字段；属于阶段 1B 设计与回填 |
| 跨项目 mismatch | `NOT_APPLICABLE_CURRENTLY` | 当前未有客户项目字段、项目记录或业务数据；阶段 1 回填前需重跑 |
| legacy 回填计数前后保持一致 | `NOT_RUN` | 尚未创建 legacy 项目、未执行回填；属于阶段 1B |
| 生产 PostgreSQL 与 SQLite fresh migration 等价 | `PASS（有限范围）` | Docker 中 PHPUnit SQLite 测试通过：1 test / 15 assertions；SQLite 仍是 testing-only 最小 schema，不等同于 PostgreSQL 结构完全等价 |
| 渠道密钥/配置泄露 | `NOT_INSPECTED` | 本报告不读取 secret；需在阶段 0C/1B 设计快照和日志脱敏检查 |

## 阶段 0B 闸门判断

**0B：数据预检完成。** 在完成 pending migrations、人工发布表复核和 SQLite 分发迁移测试后，0B 的原运行时/schema 阻塞已解除；渠道归属决策由 0C ADR 单独冻结。

已满足：

- 有版本号、数据库版本、migration 状态和查询时间；
- owner 表计数、孤儿规则和适用重复规则均有结果；
- 空数据与“无法验证”严格区分；
- 未修复数据、未执行回填、未添加约束、未删除记录。

后续阶段输入（不再阻塞阶段 0）：

1. 渠道表为空，没有真实共享关系证据；该事实已由 0C ADR 采用“平台渠道 + 项目成员关系”处理。
2. SQLite 已完成当前分发 schema 测试验证（1 test / 15 assertions）；其与 PostgreSQL 非等价的结构边界作为阶段 1 迁移约束保留。

## 安全边界与执行证据

本包只执行 PostgreSQL `SELECT`、Laravel `db:show`/`migrate:status` 和源码静态读取。未执行 `migrate`、`migrate:fresh`、`seed`、回填、删除、队列、发布或外部渠道写操作。查询输出仅保留计数、表名和 schema 状态，不包含客户正文、Token、API Key、Cookie、渠道密钥或完整敏感 URL。
