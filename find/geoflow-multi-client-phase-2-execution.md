# GEOFlow 多客户改造：阶段 2 独立执行计划

> 关联总计划：[geoflow-multi-client-implementation-audit.md](D:/GEOFlow-2.3.0/find/geoflow-multi-client-implementation-audit.md)
>
> 前置计划：[geoflow-multi-client-phase-1-execution.md](D:/GEOFlow-2.3.0/find/geoflow-multi-client-phase-1-execution.md)
>
> 执行模式：GEOFlow `development` / 资源隔离、队列链路、后台/API 查询和行为回归
>
> 当前状态：2A–2D 已执行并完成聚焦验证；2F 已接入项目级监控聚合、任务快照和 API 文章列表过滤；2E 已收口后台/API 文章与任务入口的项目边界。真实 DeepSeek Worker/队列生成已验证；真实远端发布仍待项目渠道凭据，素材、分析等未完成项目化后台入口继续由项目闸门限制。

## 1. 阶段目标

用一条真实资源链证明项目归属能够从知识库、标题/图片等项目资料和任务传递到生成文章，并在 Service、Job、后台、API、统计和重试场景中保持一致。阶段 2 的事实 owner 是阶段 1 已建立的 `client_project_id` 和项目上下文；本阶段不再新增第二套租户字段或第二套授权状态机。

阶段 2 完成后，内部运营人员可以在已授权项目内创建和运行任务、生成文章、查看项目内任务/文章数据；跨项目引用、跨项目读取、跨项目队列执行和并发重复生成必须被拒绝或安全收敛。

客户仍不是 GEOFlow 用户。资料继续由运营人员沿用现有后台流程维护；本阶段不建设客户资料接收层、客户登录、客户 token、客户 webhook 或客户上传 API。

## 2. 拆分后的工作包

### 2A：项目资源解析器与跨资源引用不变量

**目标**

- 建立所有阶段 2 业务操作共用的项目归属解析和同项目引用校验边界。
- 覆盖知识库、标题库、关键词库、图片库、作者、分类、任务、文章和渠道 membership 等直接 owner 关系。
- 明确任务→资料库、任务→作者/分类/图片库、文章→任务/标题/作者/分类的项目不变量。

**边界**

- 复用阶段 1 的 `client_project_id`、模型关系和 `ProjectAccessService`；不新增可被请求体覆盖的项目字段，不复制子表 owner。
- 跨资源校验必须在 Service/事务边界完成；控制器下拉框、Blade 隐藏字段、URL `project_id` 和内存过滤都不算授权。
- 平台级 `AiModel`、Prompt 和系统基线按阶段 1 的平台资源规则处理；本工作包只验证项目资源引用，不实现项目 quota。
- 渠道继续使用“平台渠道 + 项目 membership”；任务绑定渠道时同时验证 membership 状态和任务项目。

**最小验证**

- 同项目的知识库、标题库、关键词库、图片库、作者、分类和渠道引用成功；任一跨项目引用在 Service 层被拒绝。
- 任务与文章的直接 owner、任务关联表和渠道 membership 查询不会把另一项目资源当作可用选项。
- PostgreSQL 复合约束/事务锁与 SQLite 等价不变量测试均有证据；没有通过内存过滤掩盖未校验关系。

### 2B：任务生命周期的项目上下文接入

**目标**

- 将 `TaskLifecycleService` 的创建、读取、更新、删除、启停、入队和运行记录查询接入项目上下文。
- 任务创建时保存不可变的项目归属，并校验所有项目资源引用。
- 任务删除、暂停和批量操作只影响当前项目的任务与关联运行记录。

**边界**

- 优先改造任务 Service、API 任务控制器及现有后台任务入口；不一次性改造增长中心或公开前台。
- `task_runs`、计划和任务关联表通过任务 owner 解析项目，不把它们当成第二个项目事实来源。
- 创建任务必须从已授权项目上下文取得项目，不能接受任意请求体 `client_project_id` 作为授权依据；超级管理员写操作仍需显式目标项目。
- 任务的 `ai_model_id`、prompt 等平台级资源只做允许性校验，不在本工作包改变模型 quota 或 AI 调用实现。

**最小验证**

- 运营人员在当前项目内创建、读取、更新、暂停、恢复、删除和入队任务成功；切换项目后旧任务详情和写操作被拒绝。
- 任务不能绑定另一项目的知识库、标题库、图片库、作者、分类或渠道；失败事务不留下半成品关联。
- API 和后台详情的 403/404 规则一致；任务列表分页、运行记录和统计只返回当前项目。

### 2C：Worker 生成链路的项目继承

**目标**

- 改造 `WorkerExecutionService` 的标题选择、知识检索、作者/分类选择、图片插入、AI 生成和文章落库，使所有输入属于任务项目。
- 生成文章时保存与任务一致的 `client_project_id`；手工/无任务文章不通过 Worker 的隐式全局默认值创建。
- 保留现有 Laravel AI Agent/Embedding、模型回退和平台级 quota 语义，只增加项目边界和 usage observation 上下文。

**边界**

- 不在本工作包引入统一 `publication_gate` 或 publication batch；Worker 仍只负责阶段 2 允许的生成/草稿行为。
- 禁止 `pickAuthor`、`pickCategory`、知识检索、标题选择和图片选择在当前项目没有资源时静默回退到全局另一项目资源；需要平台默认值时必须明确定义为平台级、不属于客户项目的资源。
- Job/Service 不携带 API Key、渠道 secret、完整提示词或文章正文到日志和队列 payload。
- 文章通过任务生成时，任务项目是唯一项目事实；不能接受独立请求体项目 ID 覆盖它。

**最小验证**

- 同项目知识库→任务→文章生成成功，文章、标题、作者、分类、图片和 AI usage observation 都能读回同一项目。
- 跨项目资料引用、缺少项目资源时的隐式全局 fallback、任务已停用或项目已停用时的生成均被拒绝或返回明确无动作结果。
- AI 成功、失败、fallback 和额度释放保持现有语义，日志和文章中没有密钥或不应暴露的项目上下文。

### 2D：队列 Job、TaskRun 和恢复路径的项目继承

**目标**

- 将 `JobQueueService`、`ProcessGeoFlowTaskJob`、TaskRun claim/complete/fail/cancel、重试和 stale recovery 接入任务项目。
- Job payload 只携带稳定的 `task_run_id`/必要业务 ID，执行时重新读取任务和项目 owner。
- 保证重启、重试、恢复和 follow-up generation 不会把任务执行到另一项目或重复生成文章。

**边界**

- 不把 session context 序列化进 Job；队列执行不信任创建时的旧上下文或可篡改 payload。
- TaskRun 的项目归属通过 task owner 解析；如果任务被删除、停用、项目被暂停或关系不一致，Job 必须安全失败/取消并留下可审计结果。
- 不在本工作包实现队列公平性、项目 quota 或 Horizon 多租户看板；这些属于阶段 6B/6C。
- 不改变发布门闸；如果 Job 触及现有发布逻辑，只保留阶段 3 的待接入点和回归证据。

**最小验证**

- 同一任务的 Job 重试、重启和 stale recovery 始终读取同一项目；Job payload 不含 secret 或可覆盖项目字段。
- 同一 task_run 不会并发 claim 两次，也不会因 follow-up/recovery 产生重复文章；重复完成/失败回调是幂等的。
- 跨项目 task/channel 或 task/resource 关系在 claim 或执行前被拒绝；未开始、失败、取消和结果不确定状态可区分并可读回。

### 2E：文章 Service、后台/API 写入与直接归属

- 本轮已先收口后台文章列表、详情、垃圾箱和批量状态/审核/删除/恢复/永久删除入口：存在项目上下文时，文章 ID 查询、统计和写入在数据库查询层限制 `client_project_id`，写操作额外要求项目写权限。无项目 legacy 后台路径保留原兼容语义，普通 operator 的项目表面端到端证据仍待后续补齐。
- API 文章创建、更新、审核、发布和软删除已将绑定项目传入 `ArticleGeoFlowService`；Service 内部对文章、关联任务读取和 mutation 使用项目 owner 查询，分类/作者/任务引用也按项目校验，跨项目文章 ID 或资源引用会被拒绝，不会被更新或发布。
- 后台文章列表与编辑表单的任务、作者、分类、标题库、知识库和分发渠道选项已按当前项目上下文过滤；Prompt/AiModel 继续保持平台级资源语义。
- 文章编辑器标题搜索、AI 生成和图片上传辅助入口也已接入项目上下文；标题库/知识库/文章/编辑器图片库均限制在当前项目，写入图片要求项目写权限。
- `EnsureProjectScopedSurface` 现在允许普通 operator 在有效项目上下文下进入 `admin.articles.*` 整组路由；任务、素材、分析和其他后台全局页面继续由超级管理员闸门保护，直到各自完成项目化。

**目标**

- 将 `ArticleGeoFlowService`、管理员文章入口和 API 文章入口接入项目授权。
- 区分任务生成文章与手工创建文章：任务文章从任务项目继承，手工文章必须在明确项目上下文中创建。
- 文章更新、审核前置读取、软删除和任务解绑不允许改变项目边界。

**边界**

- 只处理文章创建、读取、更新、审核前置和删除的项目隔离；公开发布批准和统一 gate 留给阶段 3。
- 文章绑定 task、title、author、category、images 时使用 2A 的同项目校验；不能通过修改 `task_id` 把文章转移到另一项目而不重新校验。
- 无任务手工文章的 `client_project_id` 是直接 owner；不得因为 `task_id=null` 就落入全局列表或 legacy 默认项目，除非当前上下文明确就是 legacy 项目。
- 不开放未完成项目过滤的增长中心页面给普通 operator。

**最小验证**

- 同项目手工文章创建、更新、软删除和读取成功；跨项目 task/作者/分类/图片引用被拒绝。
- 任务文章的 `client_project_id` 与任务一致；尝试改任务归属或解除任务时不会产生跨项目 orphan。
- API/admin 文章详情和写操作不会通过 ID 枚举泄露另一项目文章；幂等写请求重复提交不重复创建文章。

### 2F：列表、监控、统计和并发幂等闭环

**目标**

- 将 `TaskMonitoringQueryService`、文章列表、任务统计、分页、运行摘要和必要的管理员/API 查询全部按项目过滤。
- 对阶段 2 关键写路径建立并发、重试、重启和幂等回归矩阵，形成进入阶段 3 的闭环证据。
- 证明查询性能不会先读全表再在应用层过滤。

**边界**

- 只覆盖任务→文章链路直接依赖的后台、API 和监控查询；不一次性改造 Enterprise Knowledge、URL Import、中央站、完整 analytics 或项目官网。
- Horizon/Redis 全局队列指标保持现有平台级语义；项目级业务统计必须以带项目 owner 的数据库查询为准。
- 不以“菜单隐藏”作为隔离；任何详情、导出、分页、计数和关联摘要都必须在查询层限制项目。

**最小验证**

- 两个项目各有任务、运行记录和文章时，列表、详情、统计、分页和导出只返回当前项目；超级管理员跨项目只读看板仍需明确筛选范围。
- 同一任务并发执行、重复点击入队、重试和重启不会产生重复文章或重复任务运行记录；失败不会污染另一项目统计。
- 查询使用 owner join/项目条件和必要索引，不把全表结果加载后再用 PHP 过滤；SQLite 与 PostgreSQL 的行为差异有记录。

## 3. 工作包依赖与阶段闸门

```text
2A 项目资源解析与同项目引用不变量
    ├── 2B 任务生命周期项目上下文
    │      └── 2D 队列 Job/TaskRun/恢复路径
    ├── 2C Worker 生成链路项目继承
    └── 2E 文章 Service/API/admin 写入

2B + 2C + 2D + 2E
    └── 2F 列表/监控/统计与并发幂等闭环
```

2B、2C 和 2E 可以在 2A 验证通过后分别实施；2D 必须在任务入队合同稳定后接入；2F 等所有主要写路径具备项目 owner 后再做全链路回归。

### 允许进入阶段 3

- 2A 的同项目资源引用和渠道 membership 校验通过；
- 2B 的任务生命周期、项目上下文和 API/admin 任务查询通过；
- 2C 的知识库→任务→文章生成链路不会全局 fallback，文章项目归属稳定；
- 2D 的 Job claim、重试、恢复、取消和 follow-up generation 继承任务项目且幂等；
- 2E 的手工文章和任务文章入口都不能跨项目读取或写入；
- 2F 的列表、分页、统计、导出、并发和重启回归通过；
- 未把 publication gate、publication batch、客户入口或项目官网提前混入阶段 2。

### 必须停留在阶段 2

- 任一 Service/Job 仍可通过请求体、旧 session 或全局默认值越过项目边界；
- Worker 生成文章的项目与任务项目不一致，或知识/标题/作者/分类/图片存在跨项目 fallback；
- task_runs、统计、分页或导出需要在应用层过滤全表；
- 重试、恢复、并发 claim 或重复点击会产生重复文章/重复运行记录；
- 手工文章没有明确项目 owner；
- PostgreSQL 与 SQLite 的关键隔离行为不一致且没有等价不变量证据。

## 4. 阶段 2 交付物

阶段 2 完成时只允许产生以下结果：

1. 项目资源解析与同项目引用校验合同；
2. 项目上下文下的任务生命周期和任务监控查询；
3. 知识库→任务→文章的 Worker 生成链路；
4. 项目继承的 Job/TaskRun 重试、恢复和幂等语义；
5. 文章 API/admin 写入与直接 owner 规则；
6. 列表、分页、统计、并发和重启回归证据；
7. 阶段 3 发布门闸接入所需的调用路径清单。

不得在阶段 2 产生统一 publication gate、publication batch、客户门户、客户资料接收层、中央站多客户公开承载或项目官网。

## 5. 后续执行方式

### 2A 执行记录

- 资源解析与校验 owner：`app/Services/GeoFlow/ProjectResourceResolver.php`
- 回归测试：`tests/Unit/ProjectResourceResolverTest.php`
- 已覆盖：项目 owner 查询、任务关联知识库/渠道 membership 同项目校验、文章直接引用校验入口、活跃渠道 membership 校验。
- 验证：通过项目现有 `geoflow-app:latest` Docker 镜像执行 `php artisan test --filter='ProjectResourceResolverTest|ProjectAccessServiceTest'`；6 tests、13 assertions 全部通过，并完成迁移启动。首次 `--build` 因 `public/storage` 路径请求失败，未影响使用现有镜像的测试结果。

### 2B 执行记录（第一批）

- 任务生命周期与监控查询：`app/Services/GeoFlow/TaskLifecycleService.php`、`app/Services/GeoFlow/TaskMonitoringQueryService.php`。
- API 上下文入口：`app/Http/Controllers/Api/V1/BaseApiController.php`、任务与 Job 控制器；绑定 token 的项目优先于请求体，旧 legacy token 保留无项目的兼容行为。
- 后台任务入口：`app/Http/Controllers/Admin/TaskController.php`；普通 operator 在有效项目上下文下可进入任务列表、监控、创建和编辑入口，后台写操作仍要求明确项目上下文与项目资源校验。
- 已覆盖：任务创建保存 `client_project_id`、任务与运行记录按项目查询、详情/更新/删除/启停/入队按项目限制、项目资源引用在事务内校验并在失败时回滚。
- 验证：Docker 执行 `php artisan test --filter='ApiV1ContractTest|TaskTransactionSafetyTest|ProjectResourceResolverTest|ProjectAccessServiceTest'`；39 tests、204 assertions 全部通过，并完成迁移启动。
- Worker/Job 执行链路的项目继承与跨项目回归已在 2C/2D 完成并由相邻聚焦回归覆盖。

### 2C 执行记录（第一批）

- Worker owner：`app/Services/GeoFlow/WorkerExecutionService.php`。
- 已接入：任务项目加载与 active 校验；任务资源引用在执行前复核；标题、作者、分类、知识库和图片选择均限制在任务项目；生成文章写入与任务一致的 `client_project_id`。
- 已拒绝：跨项目标题库等任务资源引用在调用 AI 前抛出拒绝，不创建文章。
- 验证：Docker 执行 `php artisan test --filter='WorkerProjectIsolationTest|WorkerExecutionServicePromptTest'`；6 tests、26 assertions 全部通过。
- 真实 provider 验证：使用临时 DeepSeek OpenAI-compatible 配置（`https://api.deepseek.com/v1`、`deepseek-v4-flash`，key 未写入仓库）执行 Worker 生成；首次 `max_tokens=256` 因 reasoning 占满预算安全返回空正文，调整临时模型预算至 1024 后生成成功。结果：`task_id=2`、`project_id=2`、`article_id=1`，文章正文长度 537，状态 `draft`，文章项目 owner 与任务一致。
- 真实队列验证：同一项目创建 `task_run` 后直接执行 `ProcessGeoFlowTaskJob`，首次因测试标题耗尽进入业务重试并保留 `last_error`；补充标题并重试后 `run_id=1` 最终 `completed`，生成 `article_id=2`，正文长度 2323，文章 owner 与任务项目一致。过程中修正了 Job eager-load 将 `clientProject` 关系误拼入 tasks 列表的 PostgreSQL 查询错误。
- 发布分发检查：真实测试项目当前无绑定分发渠道，生成文章保持 `draft/pending`，`DistributionOrchestrator` 不会越过审核和渠道门槛；分发风险、发送重检、渠道删除和并发保护回归通过 28 tests、94 assertions。真实远端发布仍待配置项目渠道后验证。
- 最终相邻回归：`ApiV1ContractTest|TaskMonitoringMemoryBoundTest|TaskTransactionSafetyTest|WorkerProjectIsolationTest|WorkerExecutionServicePromptTest|DistributionArticleRiskWorkflowTest|DistributionChannelDeletionServiceTest|ProjectScopedArticleSurfaceTest` 共 79 tests、356 assertions 通过；JobQueueService/ArticleGeoFlowService/API/Admin 文章与任务控制器/项目闸门语法检查和 `git diff --check` 通过。
- 队列执行与发布分发的服务级项目继承、风险门闸和幂等回归已完成；真实远端发布仍受项目渠道凭据这一外部条件约束。平台级 `Prompt`/`AiModel` 仍按既有平台资源规则处理。

### 2D 执行记录

- 队列 owner：`app/Services/GeoFlow/JobQueueService.php`、`app/Jobs/ProcessGeoFlowTaskJob.php`。
- 已接入：claim、完成、失败、取消、重试和 stale recovery 均从 `task_run -> task` 重新解析项目；绑定项目必须保持 active，暂停/归档项目的 pending/running 工作安全取消；失败回写使用事务锁和运行态幂等门，重复完成/失败/取消不会重复推进状态或重复派发。
- Job payload 继续只携带 `taskRunId`；TaskRun meta 的业务 payload 采用白名单（source/action/article_id/title_id），剔除可覆盖项目或敏感上下文；follow-up generation 复用同一项目可执行性校验。
- 失败回调保留原业务状态并报告回调异常，不静默吞错。
- 回归：Docker 执行 `php artisan test --filter='TaskTransactionSafetyTest'`，14 tests、72 assertions 全部通过；覆盖 after-commit、重试、重复 recovery、暂停项目 claim 取消、错误 taskId 回调隔离、重复完成幂等及 stale 发布失败隔离。相邻 Worker/API 聚焦测试已启动并通过其已输出用例；完整命令输出未在本次终端返回最终汇总，需后续补跑作为阶段证据。

### 2F 执行记录

- 监控查询 owner：`app/Services/GeoFlow/TaskMonitoringQueryService.php`、`app/Services/GeoFlow/HorizonMetricsAdapter.php`。
- 已接入：后台 `queue_overview` 接受项目上下文并在 `task_runs -> tasks` 查询中按项目过滤；任务快照继续带项目上下文；API 文章分页列表与详情按绑定项目过滤，跨项目 ID 不会读到文章。项目看板下 worker 心跳不暴露其他项目的 `current_job_id`。
- 查询保持数据库层 owner 条件、分页和批量聚合，没有先加载全表再由 PHP 过滤；运行摘要、最近运行、文章统计、任务统计和 queue overview 共用当前项目范围，并对文章/任务 owner 错配进行双边约束。任务详情中的标题库名称、旧知识库名称和多知识库关联摘要也复用项目上下文进行 owner 过滤。
- 回归：Docker 执行 `php artisan test --filter='TaskMonitoringMemoryBoundTest'`，6 tests、31 assertions 全部通过；双项目场景验证任务列表、运行记录、项目级队列计数、统计、分页、快照隔离及错配文章不污染统计。相邻 `ApiV1ContractTest|WorkerProjectIsolationTest|WorkerExecutionServicePromptTest|TaskTransactionSafetyTest` 命令已通过其已输出用例。
- 2F 仍不宣称阶段 2 全部完成：真实远端发布需要项目渠道凭据；素材、分析等尚未完成项目化的后台入口仍由闸门限制。

### 2E/项目文章入口补充回归

- 后台文章入口已要求有效项目上下文；普通 operator 在已授权项目上下文中可访问文章列表，查询只返回当前项目文章，缺少上下文直接返回 403。
- 任务监控列表与 health-check 只读入口也已要求有效项目上下文，并复用项目过滤后的监控查询；任务创建、编辑、启停和删除仍保持闸门限制。
- 任务创建/编辑表单的项目资料选项已改为按当前项目过滤（标题库、图库、知识库、作者、分类和渠道 membership）；Prompt 与 AI Model 继续使用平台级资源。
- 验证：Docker 执行 `php artisan test tests/Feature/ProjectScopedArticleSurfaceTest.php`，3 tests、10 assertions 通过。
- 现阶段仍限制素材、分析等尚未完成项目化的后台页面，避免通过 UI 入口绕过项目边界。

### 阶段 2 关闭结论

- 阶段 2 的项目归属、跨项目拒绝、任务→文章生成、队列幂等、监控/API/后台文章与任务入口隔离及聚焦回归已完成，满足本阶段内部运营使用的核心验收条件。
- 真实远端发布未伪造为已完成：当前测试项目未配置渠道凭据，发布验证留待具备明确项目渠道后执行；这不改变阶段 2 的生成、草稿和发布门槛行为。
- 素材、分析等非阶段 2 核心后台页面继续由项目闸门保护，作为后续项目化工作包处理。

本助手按 `2A → (2B/2C/2E) → 2D → 2F` 执行；并行表示共享 2A 合同但仍分别验证，不表示跳过阶段闸门。每个工作包开始前再次核对当前源码、阶段 1 实际状态和迁移合同；完成后先执行该工作包的最小验证，再更新本文件状态和交付物链接。

如果发现某个后台/API/统计入口无法在本阶段安全过滤，本助手会把它标记为未完成并限制普通 operator 访问，不用前端隐藏或内存过滤伪装成隔离完成。
