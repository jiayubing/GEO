# GEOFlow 多客户改造实现审计与修订实施计划

> 审计日期：2026-08-22  
> 适用代码：当前工作区 GEOFlow 2.3.0  代码与 `composer.json` 依赖  
> 文档性质：对既有多客户方案的实现审计与分阶段开发计划；本次仅修改计划文档，未修改生产代码、数据库或部署配置

## 1. 审计结论

现有方案的业务方向正确，应继续采用：

```text
员工账号 → 客户项目上下文 → 项目资料/任务/文章 → 运营审核 → 发布批次 → 平台负责人发布/分发
```

但原方案还不能直接进入编码，需要先补齐以下实现约束：

1. 客户、客户项目、员工账号必须是三个实体。`enterprise_knowledge_projects` 是企业知识原子化流程对象，不能直接当作客户工作空间。
2. 当前 Worker 会自动发布“已审核草稿”。如果不先加入项目级发布门闸，运营提交后平台负责人仍无法统一控制公开发布。
3. 不能只给 `articles` 增加项目字段，也不能只靠前端下拉框。任务、素材引用、队列、分发渠道和 API 都必须验证同一项目。
4. AI SDK 已经是当前项目的正式调用层，不应另起一套 AI 客户端或把 Laravel AI SDK 替换成向量库 SaaS。项目隔离应加在模型选择、提示词输入、用量事件和任务上下文边界，而不是改写 Agent/Embedding 的事实所有者。
5. `publication_gate` 必须是所有公开状态转换和外部发布副作用共用的领域规则，不能只在 Worker 中加判断。
6. 发布批次必须按“文章 × 目标”建模，冻结文章版本和目标快照，并明确与 `article_distributions`、`manual_publications` 的事实关系。
7. 迁移必须先做只读预检和 legacy 回填，再增加跨项目约束；回滚只能撤销新增结构，不得删除现有业务表。

因此本计划的执行门槛是：

> 先完成“版本/数据预检 + 项目上下文 + 数据边界 + 统一发布门闸”的最小闭环，再做发布批次、运营规模化和可选项目官网。

## 2. 本次审计的事实依据

### 2.1 项目技术栈与开发方式

- Laravel 12、PHP 8.3+，队列使用 Laravel Queue/Redis，Horizon 负责监控。
- AI 依赖为 `laravel/ai:^0.10.3`，当前代码已经使用官方 `Laravel\Ai\` 命名空间。
- 管理后台采用 Blade + 控制器 + Service；任务和分发的核心状态由 `app/Services`、`app/Jobs`、模型和迁移共同负责。
- 数据库主结构在 PostgreSQL 迁移中使用原生 SQL；测试环境另有 SQLite 最小结构迁移。新增迁移必须同时考虑两套结构和现有数据回填。
- 项目当前没有可用的 Windows PHP CLI；Python 解释器可用，但工作区未找到 GEOFlow discovery/preflight 脚本。本次未运行 `artisan route:list`、迁移或测试；已按 GEOFlow development 路线完成静态源码审计，不能把本次结果描述为运行时验证。

### 2.2 当前 AI 调用链

当前 AI 链路并不是一个待从零设计的模块：

| 能力 | 当前事实所有者 | 当前方式 | 改造原则 |
| --- | --- | --- | --- |
| 正文生成 | `ArticleContentGenerationService`、`MarkdownContentWriterAgent` | `Agent::prompt/stream` | 保留 Agent；由任务项目决定允许使用的模型和提示词输入 |
| 标题生成 | `TitleAiGenerationService` | `Laravel\Ai\agent()` | 保留服务和回退规则；校验标题库与项目一致 |
| 知识切片语义规划 | `KnowledgeChunkSyncService` | Agent 调用 | 保留原文稳定重建和 fallback，不把 LLM 输出当作事实 |
| 向量生成 | `KnowledgeChunkSyncService` | `Laravel\Ai\Embeddings::for()`，写入 `knowledge_chunks` | 保留现有 pgvector/JSON 双写语义；暂不切换 Laravel AI Vector Stores |
| 企业知识草稿 | `EnterpriseKnowledgeDraftService` | `MarkdownContentWriterAgent` | 该对象挂到客户项目，但不把它改名成租户边界 |
| URL 采集 | `UrlImportProcessingService` | Agent + 资料解析 | 导入任务必须携带目标客户项目 |
| 用量控制 | `AiUsageQuotaService` | 当前按 `ai_models` 计数 | 阶段 1–2 保留模型级硬上限并记录项目事件；阶段 6 增加项目级账单/配额 |

`AiModel` 当前存放 Provider URL、加密 API Key、模型标识和全局用量，因此第一阶段应继续由超级管理员管理，运营人员只选择被允许的模型。API Key 不得进入队列 payload、日志、文章或项目导出包。

### 2.3 当前发布与分发事实

- `WorkerExecutionService::publishDueDraftArticle()` 会选择 `review_status` 为 `approved/auto_approved` 的草稿，并按 `next_publish_at` 调用 `ArticleWorkflowTransitionService` 发布。
- `DistributionOrchestrator::enqueueForArticle()` 会根据文章任务的 `publish_scope` 和渠道选择进入已有分发队列。
- 现有 `manual_publications` 是外部平台人工执行工单，不是客户项目隔离或平台发布批次。
- 现有 `admins` 只有角色、登录和审计；`admin`/`super_admin` 是操作权限，不是客户数据边界。

这意味着新流程必须明确区分：

```text
文章内容审核：pending → approved / rejected
发布提交批次：draft → submitted → approved / returned → publishing → completed / partial / uncertain / failed
```

运营审核通过不应自动等于平台批准公开发布。

## 3. 对原计划的修正项

### 3.1 “客户项目”命名要避开现有企业知识项目

建议使用：

```text
Client（客户）
  └── ClientProject（客户项目/工作空间）
        ├── 知识库、关键词库、标题库、图片库
        ├── 任务、文章、文章审核
        ├── 运营成员
        └── 渠道与发布策略
```

这里的 `Client` 只是内部客户档案和归属标签，不是 GEOFlow 登录主体、客户账号或外部 API principal；不保存客户登录凭据，不给客户创建管理员、Sanctum token 或 GEOFlow session。`ClientProject` 是内部员工操作的工作空间。

不要把 `EnterpriseKnowledgeProject` 改名或复用。它当前有自己的来源、草稿、修订和发布知识库关系；正确做法是给它增加 `client_project_id`，使其成为客户项目下的一个资料加工流程。

### 3.2 不机械地给所有子表复制项目字段

项目归属的唯一事实应放在资源的直接拥有者上：

| 资源 | 第一阶段的项目字段 | 子资源处理 |
| --- | --- | --- |
| `knowledge_bases` | `client_project_id` | `knowledge_chunks` 通过知识库归属；切片同步按知识库 ID 查项目 |
| `keyword_libraries` | `client_project_id` | `keywords` 通过 `library_id` 归属 |
| `title_libraries` | `client_project_id` | `titles` 通过 `library_id` 归属 |
| `image_libraries` | `client_project_id` | `images` 通过 `library_id` 归属 |
| `tasks` | `client_project_id` | `task_runs`、计划、文章通过 `task_id` 归属 |
| `articles` | `client_project_id` | 手工创建的文章没有任务来源，必须保存直接归属 |
| `authors`、`categories` | `client_project_id` | 项目品牌/分类不得跨项目引用 |
| `distribution_channels` | 阶段 0 决策的 owner 或 project membership | 任务绑定渠道时锁行并校验授权关系一致 |
| `enterprise_knowledge_projects` | `client_project_id` | 来源、修订通过企业知识项目归属 |
| `url_import_jobs` | `client_project_id` | 生成的知识/标题/关键词库必须继承该项目 |

`keywords`、`titles`、`images`、`knowledge_chunks`、`article_images` 等表不应随意复制第二个 `client_project_id`。若为了索引或队列观测确实需要快照，必须明确它是不可授权的派生快照，不能成为第二个事实来源。所有授权查询、统计、分页和队列执行都必须通过直接 owner join 解析项目；禁止先查全表再在 Blade 或 Job 内存中过滤。

跨项目关系的数据库策略必须在阶段 0 结束前定稿：直接 owner 表增加 `(id, client_project_id)` 唯一键，子表使用包含 `client_project_id` 的复合外键；无法在 legacy/SQLite 结构上表达的关系，必须由同一事务内的锁行校验和不变量测试补足，并记录例外。不能只依赖控制器校验，也不能把复制到子表的字段当作第二个授权事实。

`distribution_channels` 不能未经决策就直接增加单值 `client_project_id`。阶段 0 必须先盘点当前渠道是否被多个任务/项目共享，并在以下模型中选择一个：

- 试点默认采用“项目独占渠道”：渠道、密钥、站点设置和目标包均归一个项目，迁移时显式处理共享旧渠道；或
- 确有共享需求时采用“平台渠道 + 项目成员关系”：渠道本体仍由平台持有，新增 project-channel 授权表，密钥 scope、任务绑定和删除均按授权关系校验。

在该决策落库前，不得编写渠道归属迁移或把渠道当作项目 owner。

平台级资源暂不项目化：`ai_models`、敏感词基线、管理员账号、系统更新、全局站点设置和默认提示词。需要项目定制时，使用“平台模板 + 项目覆盖”的明确优先级，不让项目直接修改全局配置。

### 3.3 不能让现有自动发布绕过平台审批

增加客户项目的发布门闸，例如：

```text
publication_gate = legacy_auto | platform_approval
```

该字段必须有数据库允许值和默认值：新项目默认 `platform_approval`，legacy 项目显式回填为 `legacy_auto`。它与任务的 `publish_scope`、文章 `review_status` 和 `central_site_allowed` 是四个独立条件，不能用其中一个字段替代另外三个。

当项目为 `platform_approval` 时：

- Worker 只生成草稿，不执行 `publishDueDraftArticle()` 的公开状态转换；
- 运营人员审核后创建发布批次；
- 平台负责人批准批次后，批次服务复用 `ArticleWorkflowTransitionService`；
- 本站发布成功后，再复用 `DistributionOrchestrator` 进入外部渠道队列；
- 未配置目标的文章保持草稿/私有，不默认发布到中央站。

旧的单站点数据先进入 `legacy` 客户项目，继续保持原自动发布行为，避免一次迁移改变已有部署语义。

## 4. 目标权限与项目上下文

### 4.1 角色职责

- `super_admin`：管理 AI 模型、渠道密钥、系统配置、客户项目和全局发布策略；可以跨项目查看，但每次写入、发布和批量操作仍必须带明确目标项目。
- `platform_approver`：查看各项目提交批次，批准、退回和处理异常；不拥有 AI Key 读取权限，也不能修改项目资料事实。
- `operator`：只能访问已分配项目；上传资料、维护库、创建任务、审核文章、提交批次；不能批准平台发布。
- 客户不是 GEOFlow 的系统用户。客户资料如何交付给运营人员不属于本计划；运营人员沿用现有后台流程，在选定项目下创建和上传知识库、关键词库、标题库、图片库等资料，GEOFlow 只负责项目归属、资料事实和处理审计。

角色值、迁移、创建/撤销权限和成员唯一性必须在阶段 1 落地。一个管理员可以属于多个项目，但每个项目的角色必须是显式成员记录，不通过 `admins.role` 推断项目访问。

### 4.2 登录后的项目切换

运营人员仍然使用一个员工账号。登录后从自己被分配的项目中选择或切换当前项目；这不是“一个账号绑定一个客户”，也不是把项目 ID 当作安全凭证。

每次请求都必须：

1. 从会话或新项目路由解析当前项目；
2. 使用 `ProjectAccessService` 验证管理员成员关系和操作能力；
3. 在查询、创建、更新、删除、导入、队列和分发前再次校验项目；
4. 对超级管理员允许跨项目只读看板，但仍要求在操作前明确目标项目；批量请求包含多个项目 ID 时一律拒绝，除非是明确设计的跨项目只读报表。
5. 项目切换必须重新从数据库读取成员关系；成员被撤销、项目被停用或会话上下文过期时，旧 session context 立即失效。
6. 详情接口对“无权资源”的 404/403 规则统一；不得因不同控制器实现差异泄露资源存在性。

前端隐藏下拉框、URL 中传入 `project_id` 或使用客户编号命名都不能代替后端授权。

### 4.3 API 边界

当前 `/api/v1` scope 只表示“能读/写任务或文章”，不表示“只能访问某客户”。任何 GEOFlow API token 都不发给客户；它们只供内部员工或内部 CLI 使用。客户资料的外部交付方式不进入 GEOFlow API 合同。

内部自动化 API 必须使用不可由请求体覆盖的项目上下文：优先采用绑定单一项目的 Sanctum Token；路由项目 ID 只作为目标标识，仍需与 token binding 比对。旧 token 在迁移期标记为 `legacy_global`，仅允许超级管理员使用，设定撤销期限并记录每次跨项目操作；不得把任意 `project_id` 字段当作授权凭证。`articles:publish` 还必须经过项目发布门闸，scope 不能绕过平台审批。客户或客户使用的外部工具不直接调用 GEOFlow。

## 5. 分阶段实现设计

每个阶段必须同时满足“目标、边界、最小验证”三项；最小验证未通过时不得进入下一阶段。阶段编号是实施顺序，不代表必须一次提交完成。

### 阶段 0：版本冻结与只读数据预检

本阶段已拆为独立执行计划：[geoflow-multi-client-phase-0-execution.md](D:/GEOFlow-2.3.0/find/geoflow-multi-client-phase-0-execution.md)。执行顺序为 `0A` 版本/运行时盘点 → `0B` legacy 不变量报告 → `0C` 渠道归属决策；每个工作包仍分别执行目标、边界和最小验证。

**目标**

- 锁定实际部署代码版本、数据库驱动和目标分支；记录本地快照与上游主线差异。
- 生成不修改业务数据的 invariant report，为 legacy 回填和渠道归属决策提供事实。

**边界**

- 只读扫描，不创建项目、不写迁移、不启动发布、不导入资料。
- 统计 owner 表数量、孤儿引用、跨项目候选 mismatch、可空字段、删除后文章来源、现有渠道共享关系、`manual_publications` 无文章工单和 URL import 重复提交风险。
- 同时检查 PostgreSQL legacy schema 与 SQLite 测试 schema；无法运行 Python/PHP 时只记录静态证据，不伪造运行结果。

**最小验证**

- 产出带版本号的 preflight 报告，所有异常都有分类、数量和后续 owner。
- 明确 `distribution_channels` 采用“项目独占”还是“共享 membership”，并冻结决策记录。
- 只有在没有未分类数据风险、目标版本已确认时，阶段 0 才算通过。

### 阶段 1：项目基础、授权合同与 legacy 回填

本阶段已拆为独立执行计划：[geoflow-multi-client-phase-1-execution.md](D:/GEOFlow-2.3.0/find/geoflow-multi-client-phase-1-execution.md)。执行顺序为 `1A` 项目/成员 Schema → `1B` owner 与渠道 membership → `1C` legacy 回填 → `1D` 项目上下文 → `1E` 内部 token；`1F` AI usage observation 在项目上下文可用后接入。

**目标**

- 建立 `clients`、`client_projects`、`client_project_members`、角色枚举和项目上下文。
- 为直接 owner 表增加项目字段、索引和可回滚迁移；创建 `legacy` 客户/项目承载旧数据。
- 落实后台 session context、项目成员授权、API token binding 和跨项目查询的统一入口。
- 建立不可变的项目级 `ai_usage_events`/observation 记录入口，但暂不改变平台级模型 quota。

**边界**

- 迁移顺序固定为：新表/nullable 字段 → preflight 复核 → legacy 回填 → 计数与不变量校验 → 非空/复合约束。
- 首批 owner 覆盖知识库、标题/关键词/图片库、任务、文章、作者、分类、Enterprise Knowledge Project、URL import job、渠道（按阶段 0 决策）和手工发布工单。
- 旧 token 标记为 `legacy_global`，只允许超级管理员在兼容期使用；新的内部自动化 token 必须绑定单一项目，绝不向客户分发。
- 用量事件只记录项目、模型、操作、attempt、units、outcome 等审计字段，不记录 API Key、提示词正文或渠道 secret。
- 不在本阶段实现客户登录、客户门户、客户 token、批次发布、批量开户或项目官网；资料仍由 `operator` 使用现有后台页面维护，也不把未完成过滤的页面开放给 `operator`。
- 回滚只删除本阶段新增表、字段、索引和约束；禁止回滚 legacy 基线 migration 或执行 `CASCADE` 删除业务表。

**最小验证**

- PostgreSQL 和 SQLite 两套结构均可执行新增迁移；fresh migration、回填、约束和 down-safety 有测试或静态等价证据。
- legacy 回填前后各 owner 表 count 一致；NULL、孤儿和跨项目 mismatch 为零或有书面例外。
- `operator` 只能访问所属项目；被撤销成员、停用项目、过期 session context、混合项目批量请求均被拒绝。
- 新旧 token 的授权矩阵、403/404 规则和审计字段有 feature test；超级管理员写操作必须显式目标项目。
- 用量事件在重复请求、失败和回滚场景下保持追加式、可追溯且不可被项目成员修改。

### 阶段 2：知识库 → 任务 → 文章的真实隔离

本阶段已拆为独立执行计划：[geoflow-multi-client-phase-2-execution.md](D:/GEOFlow-2.3.0/find/geoflow-multi-client-phase-2-execution.md)。执行顺序为 `2A` 项目资源解析与同项目引用 → `2B` 任务生命周期 → `2C` Worker 生成 → `2D` 队列 Job/TaskRun → `2E` 文章 Service/API/admin → `2F` 列表、监控、统计和并发幂等闭环。

**目标**

- 用一条最小资源链证明项目归属会从知识库/任务传递到生成文章，并在队列重试、并发和重启后保持不变。
- 让 Service、Job、后台列表、统计和 API 均先按项目授权再查询、分页或聚合。

**边界**

- 优先改造 `KnowledgeBase`、`TaskLifecycleService`、`WorkerExecutionService`、文章创建/查询和必要的标题库/作者/分类引用校验；不一次性改造全部增长中心页面。
- 任务的模型、提示词等平台级资源必须经过允许列表；知识库、库、作者、分类、渠道等项目资源必须同项目。
- Job payload 只携带稳定 ID；执行时重新读取 owner 和项目，不能信任请求体或旧 session context；不携带 API Key/渠道 secret。
- 未完成项目过滤的页面仅允许 `super_admin`，不隐藏菜单后继续返回全局数据。

**最小验证**

- 同项目的知识库→任务→文章创建、读取、更新、删除成功；跨项目绑定在服务层和数据库/事务不变量层均被拒绝。
- Job 重试、重启、并发执行时文章始终继承任务项目；同一任务不会产生重复文章。
- 目录、分页、统计、API 列表均不会返回其他项目数据；日志、文章和 Job payload 不含密钥。
- PostgreSQL 复合约束/事务锁和 SQLite 等价行为各有最小回归测试。

### 阶段 3：统一项目发布门闸

本阶段已拆为独立执行计划：[geoflow-multi-client-phase-3-execution.md](D:/GEOFlow-2.3.0/find/geoflow-multi-client-phase-3-execution.md)。执行顺序为 `3A` 发布门闸合同与状态矩阵 → `3B` 统一状态转换 owner → `3C/3D/3E` 分别接入 Worker/队列/调度/CLI、API/admin 和分发/人工发布副作用 → `3F` 全入口矩阵、legacy 回归与幂等闭环。

**目标**

- 让 `publication_gate = legacy_auto | platform_approval` 成为所有公开状态转换和外部发布副作用的统一规则。
- 保留 legacy 项目的既有自动发布语义，同时阻止新项目在运营审核后自动公开。

**边界**

- gate 必须覆盖 Worker、API review/publish、后台创建/更新/批量状态、CLI/调度、`ArticleWorkflowTransitionService`、`DistributionOrchestrator` 和 ManualPublication 的外部副作用。
- 内容审核状态（`pending/approved/rejected`）与平台发布批准状态分离；`approved` 不是公开发布许可。
- `platform_approval` 项目中，Worker 只能生成草稿；无目标文章保持 draft/private；不得在控制器或 Blade 侧绕过 gate。
- 本阶段先不引入完整 publication batch，但要为批次服务保留统一 transition 和幂等入口。

**最小验证**

- 建立“入口 × gate × 目标”的状态矩阵：每条现有发布路径在 `legacy_auto` 和 `platform_approval` 下均有正向/拒绝测试。
- 运营审核通过后，Worker、API、后台单条/批量和 CLI 均不能在 `platform_approval` 下公开发布。
- legacy 项目原有自动发布回归通过；分发入队失败能被调用方区分为未开始、失败或不确定，不再只记录日志吞掉结果。
- 重复请求、并发 transition 和风险门闸失败不会产生重复公开状态或重复外部副作用。

### 阶段 4：目标级发布批次、渠道和人工工单

**目标**

- 建立平台负责人审批的 `publication_batches` / `publication_batch_items`，并与现有分发和人工发布事实正确衔接。
- 用冻结快照、版本校验和幂等键保证批准的内容与实际发布目标一致。

**边界**

- `publication_batch_items` 按“文章 × 目标”建模；目标类型至少区分 `local`、`channel`、`manual`，不能把一篇文章的多个渠道压成一个 item。
- item 保存文章版本/内容 hash、目标 canonical identity、提交时目标快照、幂等键、状态和结果分类。批次不能跨项目。
- `item.approved` 只表示平台批准；运营内容审核仍由文章 `review_status` 表示。明确 batch 与 `article_distributions` 的一对一/引用关系；现有 `(article_id, distribution_channel_id, action)` 唯一键不能默认为新版本幂等合同，必须在迁移前决定是扩展为版本/批次投影，还是由 batch item 保存每次发布 generation。
- manual item 必须引用或创建对应 `manual_publications`，评论类无 article 工单也要有项目 owner；`ManualPublicationService` 继续拥有人工工单状态，batch item 只保存编排结果和投影，不让两个状态机互相覆盖。
- 幂等键至少包含 project、article revision/content hash、target type、target canonical identity 和 action，并由数据库唯一约束保护；submit/approve 本身也必须有幂等结果。
- 外部渠道发布使用冻结快照；如果现有 `DistributionOrchestrator` 重新读取可变配置，必须在调用前校验版本或改为传入快照。
- 远端请求已发出但结果无法确认时进入 `uncertain`，只能 readback/reconcile 后再决定，不得普通自动重试。

推荐状态：

```text
batch: draft → submitted → approved / returned → publishing → completed / partial / uncertain / failed
item : pending → approved → publishing → local_published / remote_synced / manual_ready / failed / uncertain
```

**最小验证**

- 同一文章新版本会使旧 batch item 失效并要求重新提交；批准、提交、发布重复点击不会重复创建本站或远端对象。
- batch、item、`article_distributions`、`manual_publications` 的状态转换和审计人可追溯；部分成功、失败、不确定均可读回。
- 共享/独占渠道决策在真实旧数据上完成迁移回归；跨项目 task-channel binding 被拒绝，密钥不会进入快照或日志。
- 平台负责人只能批准有权限项目；运营人员只能提交、不能批准；异常项目支持逐 item 审核。

### 阶段 5：Enterprise Knowledge、URL Import 与中央站过滤

**目标**

- 关闭剩余后台入口和公开前台的全局查询缺口，使企业知识、URL 导入和中央站都遵守项目 owner、发布门闸和中央站许可。

**边界**

- `EnterpriseKnowledgeProject` 增加项目归属；其 published KnowledgeBase、source、revision 必须同项目，发布时重新校验 owner。
- `UrlImportJob` 从持久化 job 读取项目；run/status/show/history/commit 均按项目授权，commit 幂等且生成的知识库/关键词库/标题库继承 job 项目。
- 首页、archive、category、article、related、sitemap 和 SEO 查询同时满足 `published`、`publication_gate` 和 `central_site_allowed`；不把中央站许可等同于项目发布批准。
- 明确项目站点 slug 是否按 project/channel 唯一；未决定前不修改全局唯一合同。
- 本阶段不实现任何客户登录、客户门户、客户 token 或客户直连 API；资料录入继续复用现有知识库、关键词库、标题库和图片库后台流程。

**最小验证**

- Enterprise、URL import 全部详情/修改/提交路径均有跨项目拒绝测试；重复 commit 不重复创建资源。
- 中央站不会显示 private、distribution-only、未允许中央站或未通过 gate 的内容；项目站点和全局 slug 冲突有明确错误。
- URL import worker/commit 重启后能从 job owner 恢复，日志不泄露密钥和客户正文。

### 阶段 6：运营资料导入与规模化运营

**目标**

- 在项目边界稳定后，沿用现有后台的知识库、关键词库、标题库和图片库流程，补充项目级用量审计、配额和队列公平性，而不是复制管理员账号或让客户进入 GEOFlow。

**边界**

- 客户资料的外部交付方式不属于 GEOFlow 改造范围；不新增外部接收层、客户 webhook、客户上传 API、客户 token 或客户导入身份。
- 运营人员在选定项目上下文下，继续使用现有后台页面和 Service 创建/上传知识库、关键词库、标题库、图片库，并由同一套项目授权和 owner 约束保护。
- 不改变现有资料录入方式；未来如需批量导入，应另立需求和验证计划，不在本次多客户隔离改造中顺手引入。
- 基于阶段 2 已建立的不可变 `ai_usage_events`/observation，实施项目 quota、存储、文章数和并发限制；事件继续记录 project、model、operation、attempt、units、outcome 和 reservation 生命周期。
- 队列优先级、失败告警、审计报表按项目聚合；平台级 AI Key、模型和敏感词基线仍由超级管理员管理。
- 内部 CLI/API（如继续使用）必须使用项目绑定 token；不把 project ID、quota 或 secret 放进可篡改 payload。

**最小验证**

- 运营人员在同一项目下通过现有后台流程创建/上传资料成功；切换项目后不能看到或修改其他项目的库和条目。
- AI 成功、失败、fallback、重试和不确定调用均产生一条可追溯 usage observation；共享模型 quota 的消耗可按项目审计。
- 项目级并发/配额拒绝、队列公平性和失败告警有最小测试；500 项目聚合查询不读取全表后在应用层过滤。

### 阶段 7：可选项目官网和内部交付

**目标**

- 仅在产品确认交付需要时，提供项目官网、渠道站点或人工交付；客户仍不登录 GEOFlow，资料和状态由内部员工管理。

**边界**

- 不建设客户门户、客户账号、客户 session 或客户 API；项目官网是交付产物/公开站点，不是 GEOFlow 管理入口。
- 项目官网/渠道复用现有 GEOFlow Agent、WordPress REST、Generic HTTP API 和目标包能力；中央站仍是平台级站点。
- 项目官网和渠道是交付产物，不提供客户管理入口；下载、回执和反馈由内部交付流程控制，不反向开放 GEOFlow 管理权限。

**最小验证**

- GEOFlow 不存在客户登录/客户 token 访问路径；内部管理员与内部 CLI token 的权限边界可审计。
- 目标渠道签名、能力协商、静态包和远端回执回归通过；项目内容不会未经 `central_site_allowed` 混入中央站。

### 5.8 阶段拆分与后续执行方式

当前阶段顺序可以保留，但不能把每个阶段当作一个不可再拆的开发任务。阶段 0 可以作为一个只读决策阶段；阶段 1–5 应拆成可独立评审、可独立回滚或可独立验收的工作包；阶段 6 应定位为试点后的规模化运营加固，不应重复实现阶段 1–2 已经完成的资料页面；阶段 7 必须按具体交付目标逐个执行。

这不是一份给外部团队估算人力的排期。后续由本助手按工作包顺序直接实施、验证和汇报，因此不预设“人日”“日历周”或固定完成日期。实际推进以证据和闸门为准：一个工作包的最小验证未通过，就停留在该工作包修复，不进入下一个工作包。

| 阶段 | 是否需要继续拆分 | 本助手实际执行的工作包 | 执行难度与依赖 |
| --- | --- | --- | --- |
| 0 | 拆成 0A–0C 三个只读工作包 | `0A` 版本/Schema 盘点；`0B` invariant report；`0C` 渠道独占或共享决策 | 中；只读，不修改业务数据；0C 未决不能开始渠道归属实现 |
| 1 | 拆成 1A–1F | `1A` 项目/成员 Schema；`1B` owner 字段与渠道 membership；`1C` legacy carrier 与幂等回填；`1D` 项目上下文与成员授权；`1E` 内部 token 绑定；`1F` AI usage observation | 很高；1A/1B 未验证前不回填，1C 未验证前不切换运营流程，1D/1E 未验证前不开项目写接口；1F 不改变平台 quota |
| 2 | 拆成 2A–2F | `2A` 项目资源解析与同项目引用；`2B` 任务生命周期；`2C` Worker 生成链路；`2D` 队列 Job/TaskRun/恢复；`2E` 文章 Service/API/admin；`2F` 列表、监控、统计和并发幂等 | 高；先闭合同项目资源不变量，再分别接入任务、Worker、文章和队列，最后做全链路查询与并发回归；publication gate、批次和客户入口不得混入 |
| 3 | 拆成 3A–3F | `3A` gate 合同、默认值和状态矩阵；`3B` 统一状态转换与门闸 owner；`3C` Worker/队列/调度/CLI；`3D` API/admin 审核、发布与批量状态；`3E` 分发与人工发布外部副作用；`3F` 全入口矩阵、legacy 回归与幂等闭环 | 高；3A/3B 是共享合同与事实 owner，3C/3D/3E 分入口收口，3F 才能证明没有旁路 |
| 4 | 拆成 4A–4D，是风险最高阶段 | `4A` batch/item Schema 与状态机；`4B` 提交/退回/批准；`4C` local 目标发布；`4D` channel 与 manual 工单、readback/reconcile | 很高；4C 通过才做本站受控试点，4D 通过前不走真实远程批量发布 |
| 5 | 拆成 5A–5C | `5A` Enterprise Knowledge；`5B` URL Import；`5C` 中央站/项目站查询和 slug 合同 | 高；每个工作包都要完成跨项目拒绝和公开前台过滤验证 |
| 6 | 拆成 6A–6C | `6A` 现有后台资料流程项目回归；`6B` 项目 quota/存储/文章数/并发；`6C` 队列公平性、告警和聚合报表 | 中高；6A 是资料试点前置，6B/6C 是试点后的规模化加固；不新增客户接收层 |
| 7 | 按具体交付目标拆分 | `7A` 目标包/渠道合同；`7B` 单个渠道连接器；`7C` 回执、重试、回滚和内部交付 | 中高；没有明确渠道目标时只做 7A，不提前建设项目官网 |

#### 子阶段的完成规则

上表的工作包不是降低验收要求的“任务列表”。每个工作包开始前仍须写明目标、边界和最小验证；最小验证通过后才允许进入同一阶段的下一个工作包。特别是：

1. 阶段 1 的 `1A/1B` 通过前，不得执行 legacy 回填；`1C` 通过前，不得把普通运营人员切换到新的项目上下文；`1D/1E` 通过前，不得开放任何依赖项目 ID 的写接口。
2. 阶段 2 的 `2D` 和阶段 3 的 `3A–3F` 通过前，不得让新项目进入自动公开发布或真实外部发布；阶段 6A 仍负责验证运营人员在项目上下文中沿用现有资料后台流程。
3. 阶段 4 的 `4C` 通过后可以做“本站点、单项目、少量文章”的内部试点；`4D` 通过前，不应把真实远程渠道或人工发布工单作为批量生产路径。
4. 阶段 5 完成前，中央站只能继续承载已确认的 legacy 语义；不能用前台过滤补偿后台尚未完成的项目隔离。
5. 阶段 6 的 `6A` 是真实多客户资料试点的前置条件，但不应把 `6B/6C` 的 quota、队列公平性和规模化报表阻塞在首个小规模试点之前；阶段 7 始终是可选项。

#### 后续执行顺序

不再使用固定周数作为里程碑，改用以下顺序和闸门：

```text
阶段 0：完成版本、数据不变量和渠道模型决策
阶段 1：按 `1A–1F` 完成项目基础、legacy 回填、授权合同和用量事件
阶段 2：按 `2A–2F` 完成知识库→任务→文章的真实隔离及队列验证
阶段 6A：验证运营人员继续使用现有资料后台流程
阶段 3：按 `3A–3F` 封闭全部公开发布入口的项目门闸
阶段 4A–4C：完成本站发布批次闭环；通过后才做受控本站试点
阶段 4D：完成远程渠道和人工工单的事实衔接
阶段 5：封闭 Enterprise、URL Import 和中央站缺口
阶段 6B–6C：根据试点暴露的规模化问题实施配额、队列和报表加固
阶段 7：仅按已确认的具体交付渠道执行
```

若阶段 0 发现大量孤儿/跨项目关系，或阶段 4 发现现有渠道无法提供稳定 canonical identity，本助手会先处理对应阻塞问题并更新计划，不跳过验证，也不通过压缩测试来维持虚构的日期。

## 6. Laravel/AI SDK 实现边界

### 6.1 保留现有 Agent 和服务分层

不直接在控制器中调用 `agent()`、`Embeddings::for()` 或解密 API Key。新项目上下文应在任务创建、知识库同步和发布批次服务边界完成授权，AI 服务只接收已经校验过的 `AiModel` 与提示词。

建议的调用顺序：

```text
ProjectAccessService
    ↓
Task/Knowledge/Article service 校验项目引用
    ↓
AiModel 解析 + AiUsageQuotaService 预占
    ↓
MarkdownContentWriterAgent / agent() / Embeddings::for()
    ↓
文章或切片事务写入
```

### 6.2 第一阶段不要切换 Laravel AI Vector Stores

当前实现已经把 embedding 写入 `knowledge_chunks`，并保留 fallback 向量、模型 ID、维度和供应商信息。切换到 `Laravel\Ai\Stores` 会引入新的远程文件/向量事实来源、清理流程和跨项目存储边界，不能作为客户隔离的顺手改造。

只有在后续明确需要远程向量库、并完成项目级 Store 映射、删除和配额设计后，才单独评审该替换。

### 6.3 先记录项目级用量事件，再实现项目 quota

阶段 1 起新增不可变 `ai_usage_events`/observation，至少记录项目、模型、任务/文章/知识库操作、attempt、units、outcome、fallback 和 reservation 生命周期。阶段 1–2 仍可使用平台级 `ai_models.daily_limit` 作为硬上限，但不能因此丢失项目成本和公平性事实；阶段 6 再基于事件实现项目级账单、存储、文章数和并发 quota。`AiUsageReservation` 只承载一次调用生命周期，不把项目配额塞进 API Key 或可篡改 Job payload。

任何新 AI 功能都必须先查当前安装版本的 Laravel AI SDK 文档和本地 `vendor/laravel/ai` 合同，使用 SDK 的 Agent、Embedding、Fake 和断言能力；不得直接调用底层 Prism。

## 7. 最小验收矩阵

### 数据与权限

1. 运营人员只能看到已分配项目的知识库、关键词库、标题库、图片库、任务和文章。
2. 访问未分配项目的详情、修改、删除、导入、批量操作和 API 均拒绝；不是只隐藏菜单。
3. 任务无法绑定另一项目的库、作者、分类、模型绑定或渠道。
4. 超级管理员跨项目看板可用，但批量发布必须带明确项目和目标快照。
5. 项目切换、成员撤销、项目停用和过期 token 会立即失效；混合项目 ID 的批量写入全部拒绝。
6. 旧 `legacy_global` token 只能由超级管理员使用，并有可审计的撤销期限；新 token 永远绑定单一项目。
7. 客户没有 GEOFlow 登录、session、token 或直连写入路径；运营人员通过现有后台资料流程的操作可按管理员、项目和资源审计。

### 队列与 AI

8. `ProcessGeoFlowTaskJob` 重试、重启和并发执行时，文章始终继承任务项目。
9. 项目上下文不会进入日志中的 API Key、文章正文或外部渠道密钥。
10. Agent/Embedding 调用失败时，额度释放、fallback 和失败状态与现有行为一致。
11. 同一任务不能并发生成两篇重复文章；不同项目的任务不会共享标题/知识库引用。
12. 每次 AI attempt 都有项目级 usage observation；成功、失败、fallback 和不确定结果可按项目读回。

### 审核、发布和分发

13. `platform_approval` 项目中，运营审核通过后，Worker、API、后台单条/批量、CLI、调度和人工发布路径都不会绕过 gate 公开发布。
14. 批次提交后文章版本改变必须重新提交，不能发布旧快照或静默覆盖。
15. 重复点击批准/发布不会产生重复本站文章或重复远程对象。
16. 本站成功、远程成功、远程失败和远程结果不确定分别记录；不确定状态不能盲目重试。
17. 未配置目标的客户文章保持内部状态；中央站公开必须同时有项目许可和平台发布批准。
18. batch item、`article_distributions`、`manual_publications` 的状态和审计人可追溯，评论类无文章工单也不会丢失项目 owner。

### 迁移与兼容

19. 旧数据全部归入 `legacy` 项目，文章、任务、渠道、企业知识、URL import 和人工发布行为可回归验证。
20. 迁移在 PostgreSQL 和 SQLite 测试结构均能执行；回滚只删除新增结构，不删除业务数据。
21. 未完成项目化的页面不会向普通运营人员泄露全局数据。
22. migration preflight 能报告 orphan、NULL、cross-project mismatch 和重复 slug；所有例外有明确 owner。
23. 运营人员通过现有知识库、关键词库、标题库和图片库流程创建资料时，项目 owner、重复提交和跨项目引用均可验证。

## 8. 开发顺序与验证要求

真正开始编码时应按 GEOFlow `development` 路线：

1. 先运行工作区发现脚本和可用的 route/CLI discovery；若运行环境没有 Python/PHP，记录缺失的验证层，不伪造结果；
2. 先读相邻迁移、模型、服务、控制器、Blade 页面和测试；
3. 每个阶段保持“迁移/模型 → 服务/策略 → 路由/控制器 → 页面 → 行为测试”的顺序；
4. Laravel PHP 改动同时遵守 `laravel-best-practices`，AI 改动遵守 `ai-sdk-development`；
5. 每个阶段先执行该阶段的最小验证；通用最小测试覆盖跨项目拒绝、同项目引用、队列上下文、发布门闸、批次快照、远程不确定状态和幂等，再扩大测试范围；
6. 生产操作与代码开发分开，迁移、导入和发布都要有明确目标、读回和回滚边界。

建议的首个实现提交只包含阶段 1 的项目基础/迁移合同，以及阶段 2 的知识库 → 任务 → 文章隔离所需的最小代码和测试；不得同时引入发布批次、批量导入、项目官网或无关格式化。阶段 2、阶段 6A 和阶段 3 的最小验证通过后，才允许接入真实试点资料；阶段 6B/6C 不应被首个小规模试点反向扩大为前置范围。

## 9. 外部仓库与当前证据限制

本次以 `D:\GEOFlow-2.3.0` 工作区源码、迁移、路由、现有测试、README 和本地 `vendor/laravel/ai` 为主证据。已通过 GitHub API/raw README 核对上游主线：当前 main commit 为 `9d70db04ee9c5d308f5fa29b4c65834229af9eea`，主线包含 `bin/geoflow`、Enterprise Knowledge 和分发能力，但没有发现 `Client/Tenant/Multi-client` 现成隔离实现。当前工作区缺少主线中的 `bin/geoflow`，因此实施前必须锁定实际部署 commit，不能把本地快照和主线能力混为一谈。运行时仍受 Windows 缺少 PHP CLI、工作区缺少 GEOFlow discovery/preflight 脚本的限制，本计划不把静态检查描述为迁移或测试已通过。

## 10. 最终决策建议

方向可以保留，但只有在阶段 0–3 以及阶段 6A（运营人员沿用现有后台资料流程的项目回归）的最小验证通过后，才可以接入真实多客户试点资料；此时仅允许内部处理和审核，不允许公开发布。完成阶段 4C 后，才可以进行受控的本站发布试点。完整顺序为：

```text
项目表/成员/上下文
    ↓
知识库 → 任务 → 文章的真实隔离
    ↓
运营资料流程回归（6A）
    ↓
项目发布门闸（先阻止自动公开）
    ↓
目标级发布批次 + 渠道/人工工单
    ↓
Enterprise/URL import/中央站过滤
    ↓
规模化用量、配额与队列公平性（6B/6C）
    ↓
可选项目官网/内部交付
```

Go/No-Go 条件：

- 阶段 0 没有未分类的数据归属异常，且渠道共享模型已定稿；
- 阶段 1 的迁移、legacy 回填、角色/token 授权和回滚安全通过；
- 阶段 2 的跨项目拒绝和队列继承通过；
- 阶段 6A 的知识库、关键词库、标题库和图片库后台流程在项目上下文中可用；
- 阶段 3 的 `3A–3F` 全入口 gate 矩阵、legacy 回归和幂等/失败分类证据通过；
- 阶段 4C 的本站发布幂等、版本快照和失败分类通过后，才允许进行本站发布试点；远程渠道仍需等待 `4D`；
- 阶段 4 才允许平台负责人审批真实发布批次，阶段 5 之后才允许公开前台承载多客户内容。
- 客户始终不获得 GEOFlow 登录、token 或直连写入能力；资料如何从客户交付到运营人员不属于本计划，运营人员沿用现有后台资料流程。

在这些条件完成前，不建议把真实多客户生产资料放进当前全局后台，也不建议用多个 admin 账号、客户编号命名或前端选择框假装已经实现隔离。
