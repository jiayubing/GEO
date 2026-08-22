# GEOFlow 多客户改造：阶段 5 独立执行计划

> 关联总计划：[geoflow-multi-client-implementation-audit.md](D:/GEOFlow-2.3.0/find/geoflow-multi-client-implementation-audit.md)
>
> 前置计划：[geoflow-multi-client-phase-4-execution.md](D:/GEOFlow-2.3.0/find/geoflow-multi-client-phase-4-execution.md)
>
> 执行模式：GEOFlow `development` / Enterprise Knowledge、URL Import、公开前台查询和项目站点身份合同
>
> 当前状态：工作包拆分完成，尚未开始阶段 5 生产代码实施。

## 1. 阶段目标

关闭 Enterprise Knowledge、URL Import 和公开前台查询中的项目隔离缺口，让后台资料处理、异步导入、知识库产物和中央站公开内容都遵守同一个 `client_project_id` owner、阶段 3 的 publication gate 和明确的 `central_site_allowed` 规则。

阶段 5 的事实 owner 分别是：Enterprise Knowledge 项目及其 source/revision/发布知识库关系、`UrlImportJob` 及其处理日志/产物、公开站点查询策略。公开前台只读取已经满足发布条件的投影，不反向修改文章、批次或项目事实。

客户仍不是 GEOFlow 用户。客户不登录、不持有 token、不直接上传或调用系统；运营人员继续在选定项目上下文中使用现有后台维护知识库、关键词库、标题库和图片库。阶段 5 不建设客户资料接收层、客户门户、客户 API 或项目官网管理入口。

## 2. 拆分后的工作包

### 5A：Enterprise Knowledge 项目 owner、Schema 与回填

**目标**

- 将 `EnterpriseKnowledgeProject` 明确绑定到 `ClientProject`，并使 source、revision、published KnowledgeBase 和相关文件路径能够沿 owner 追溯。
- 建立 legacy Enterprise Knowledge 数据的只读盘点、回填、孤儿处理和约束收紧顺序。
- 保证 Enterprise Knowledge 项目不能被误当作客户项目实体，也不能作为第二套租户边界。

**边界**

- 覆盖 `enterprise_knowledge_projects`、sources、revisions、published knowledge base 关系、索引、外键/事务约束和 storage path 归属；不改造 URL Import 和公开前台查询。
- 回填只能依据已确认的 legacy 项目映射；无法归属的记录必须保留在隔离待处理状态，不能随意归入任一客户项目。
- 平台级 `AiModel`、管理员和全局敏感词基线继续由平台 owner 管理；不把 AI Key 或完整提示词复制到项目资料事实。
- 文件删除、迁移和回滚必须可审计；不使用 `CASCADE` 删除现有业务资料。

**最小验证**

- PostgreSQL/SQLite fresh migration、回填、复合关系、索引和 down-safety 有证据；project/source/revision/KnowledgeBase 跨项目引用被拒绝。
- legacy 记录回填前后 count、孤儿、重复发布知识库和文件路径均有分类；无未分类 owner 异常才能进入 5B。
- 普通 operator 只能读写所属项目的 Enterprise Knowledge；被撤销成员、停用项目和混合项目批量请求被拒绝。

### 5B：Enterprise Knowledge 草稿、Revision、Worker 与发布知识库

**目标**

- 将 Enterprise Knowledge 的创建、文件/正文解析、草稿生成、autosave、校验、revision restore、发布和删除接入项目上下文。
- 让 `GenerateEnterpriseKnowledgeDraftJob` 执行时重新读取项目 owner，并使发布到 KnowledgeBase 的产物继承同一项目。
- 保留现有后台资料操作语义，确保运营人员继续通过已有页面维护资料，不增加客户入口。

**边界**

- 覆盖 `EnterpriseKnowledgeController`、`EnterpriseKnowledgeDraftService`、`KnowledgeSourceParser`、draft Job、API/后台请求校验和相关 Blade 页面；不建设公开客户编辑器。
- draft/revision 的内容审核和知识库发布状态不能绕过项目授权；job payload 只携带稳定业务 ID，不携带 session、项目覆盖字段、secret 或不必要正文。
- 发布知识库前重新检查项目 active、owner、source/revision 一致性和目标 gate；失败不得留下半完成的 published 关系。
- 不把 Enterprise Knowledge 发布直接等同于文章公开发布，不在此实现 publication batch。

**最小验证**

- 同项目创建、解析、草稿生成、autosave、校验、恢复 revision、发布 KnowledgeBase 和删除成功；跨项目详情、写入和 revision restore 被拒绝。
- job 重试、重启、重复提交和失败回滚不会生成重复 KnowledgeBase、重复 revision 或错误项目产物。
- 文件清理失败不覆盖真实业务错误；日志、progress JSON、队列 payload 和页面回读不泄露密钥、token 或不必要正文。

### 5C：URL Import Job owner、权限与持久化工作流

**目标**

- 让 `UrlImportJob` 从创建开始保存不可变的项目 owner，并让 admin/API/CLI 的 run、status、show、history、commit 全部按项目授权。
- 固定 URL、normalized URL、source domain、options/result/logs 与项目的关系，避免通过 URL 或请求体的 project ID 越权。
- 保留现有 super_admin 兼容入口，同时为项目 operator 提供明确、可审计的项目范围，不把客户当作 URL Import 调用方。

**边界**

- 覆盖 `UrlImportJob`/`UrlImportJobLog` Schema、`UrlImportController`、请求校验、后台权限、项目上下文和已发现的 CLI/API 入口；不在此实现抓取解析和资源 commit。
- job 创建必须从当前项目上下文取得 owner；旧无项目 job 只能走明确的 legacy/super_admin 兼容路径，不能自动猜测项目。
- URL 抓取仍按现有 SSRF、域名、大小、超时和 secret redaction 合同执行；不允许把客户 token 或第三方凭据放进 job/options。

**最小验证**

- 两个项目的 create/list/history/show/status/run/commit 权限矩阵通过；跨项目 job ID、混合项目批量和过期 session 返回稳定 403/404。
- 同一 normalized URL 在定义的幂等窗口内不会重复创建 job；重复 run/status 请求不会推进错误状态或泄露另一项目日志。
- CLI/API 成功、拒绝、找不到 job、已完成和结果不确定均有稳定退出/响应合同；日志不含敏感 URL 查询参数、token 或正文。

### 5D：URL Import Worker、commit 与资料产物继承

**目标**

- 将 URL Import 的抓取、解析、知识/关键词/标题预览和 commit 接入持久化 job owner。
- 使 worker、重试、恢复和手工 commit 始终从 job 重新读取项目，并让生成的 KnowledgeBase、关键词库、标题库和必要图片/资料继承该项目。
- 建立 commit 的幂等、部分失败、重启恢复和结果可读回语义。

**边界**

- 覆盖 `UrlImportProcessingService`、`GeoFlowProcessUrlImportCommand`、队列/调度执行、结果 JSON/logs 和资料创建事务；不改造公开站点查询。
- 不信任可篡改的 job payload、result JSON 或旧 session；job 被删除、停用、owner 不一致或状态不允许时必须安全停止。
- commit 必须复用阶段 2 的资源解析器与同项目校验；不能因为任务/资料缺省而 fallback 到其他项目或全局客户资料。
- 外部 URL 请求结果不确定时记录 observation 并遵守现有 retry 边界，不把不确定当成成功或无条件重试。

**最小验证**

- 同项目 URL job 能完成抓取→预览→commit，所有生成资料读回同一 `client_project_id`；跨项目产物绑定、项目停用和失效 job 被拒绝。
- commit 重复调用、并发调用、进程重启和部分失败不会重复创建资料或产生跨项目半成品；失败可区分未开始、失败和不确定。
- job owner、结果摘要、错误分类和进度可审计；原始页面正文和敏感配置不进入不必要日志或 queue payload。

### 5E：中央站公开资格与统一查询策略

**目标**

- 建立公开站点共享的 `public_article`/`central_site` 查询策略，统一应用 `status=published`、项目状态、`publication_gate`、文章 `review_status`、`central_site_allowed` 和必要的目标结果条件。
- 覆盖首页、搜索、archive、category、article、related、featured/hot、分页、SEO/schema，以及当前代码实际存在的 sitemap/robots 或明确记录其缺失，不让某条查询路径绕过过滤。
- 将 `central_site_allowed` 固定为显式、可读回的事实条件；不能用 `publish_scope`、菜单隐藏或前端过滤替代。

**边界**

- 公开站点 controller/query/presenter 是读取投影，不拥有文章发布、批次批准或项目状态 writer；所有过滤必须在查询层或统一 resolver 中完成。
- 新项目默认不进入中央站，只有明确的目标批准/项目策略和 `central_site_allowed` 才可公开；legacy 语义按回填证据保留。
- 现有全局站点设置、主题和 lead form 仍是平台级资源；不在此把全局设置复制成客户配置，不建设客户后台。
- 不通过新增未发现的公开路由假设能力已存在；若 sitemap/robots 当前无路由，先记录缺口和公开合同，再由实现包决定是否补齐。

**最小验证**

- 两个项目各有 published/private、不同 gate、不同 `central_site_allowed`、不同渠道结果的文章时，首页、搜索、archive、category、article、related 和 SEO 输出只显示满足资格的内容。
- 文章状态、项目停用、批次未批准、中央站许可撤回和软删除后，列表、详情、canonical、JSON-LD、分页计数和相关内容都立即不再公开。
- 查询使用数据库 owner/资格条件和必要索引，不先加载全表再由 PHP 过滤；SQLite/PostgreSQL 关键结果一致。

### 5F：项目站点/渠道 slug、canonical identity 与公开边界

**目标**

- 冻结项目站点或渠道站点的 slug、域名、path、canonical identity 和冲突处理合同，为阶段 7/渠道目标复用。
- 明确 slug 是按 project、project+channel 还是其他已批准作用域唯一，并把生成、修改、禁用、冲突和历史 URL 行为纳入服务层。
- 保证项目站点/渠道目标只呈现其项目已批准内容，不把项目站点的公开能力混入中央站或后台客户入口。

**边界**

- 本工作包只实现身份合同、解析器、唯一约束和公开过滤所需的最小路由适配；不建设完整项目官网、客户管理入口或新渠道 renderer。
- 在 slug 唯一模型冻结前不得修改现有全局唯一合同；旧 slug 冲突必须显式报告、保留或通过受控迁移解决。
- canonical URL、sitemap 条目、related links 和主题渲染必须使用解析后的目标 identity，不接受请求体随意指定项目。

**最小验证**

- 选定的 slug/identity 规则有迁移前冲突报告、数据库约束和错误响应；同 slug 跨项目/跨渠道的行为明确且不可静默覆盖。
- 项目站点/渠道公开查询不会显示其他项目内容、未批准 item、未允许中央站内容或 legacy 之外的全局默认内容。
- canonical、alternate、sitemap/静态包（若目标合同包含）和 404/停用行为回读一致；未实现的项目官网能力不会被计划外宣称完成。

### 5G：Enterprise、URL Import 与公开前台的全链路回归

**目标**

- 汇总 5A–5F 的跨表 owner、后台授权、worker 恢复、公开资格和 slug 证据，形成进入阶段 6 规模化运营加固的门槛。
- 证明导入/Enterprise 产生的资料只能进入其项目，只有满足批次、gate 和中央站许可的文章才能进入公开查询。
- 完成 legacy 回放、跨项目拒绝、并发/重试/重启、缓存失效和敏感信息扫描。

**边界**

- 只做跨工作包的行为矩阵、静态旁路扫描、性能/索引检查和迁移证据收敛；不新增客户资料接收层、客户账号、项目官网或规模化 quota。
- 验收必须覆盖 admin、API/CLI、Enterprise worker、URL Import worker/commit、中央站所有已存在公开路线和目标 identity；不能用单个 controller 测试代替全链路证明。
- 发现无法安全按项目过滤的遗留入口时，先限制 operator 或公开访问，再记录后续 owner，不以 UI 隐藏或应用层全表过滤伪装完成。

**最小验证**

- 全部入口的同项目/跨项目、active/inactive、legacy/new gate、公开/私有、重复/并发/重启矩阵通过。
- fresh migration、legacy 回填、URL commit 幂等、Enterprise 发布关系、公开前台过滤和 slug 冲突均有可读证据。
- 静态检查确认没有客户登录、客户 token、客户上传或客户直连 API；日志、快照、queue payload 和错误页不泄露密钥或不必要客户正文。

## 3. 工作包依赖与阶段闸门

```text
5A Enterprise owner、Schema 与回填
    ↓
5B Enterprise 草稿/Revision/Worker/发布知识库

5C URL Import Job owner、权限与持久化工作流
    ↓
5D URL Import Worker、commit 与资料继承

5E 中央站公开资格与统一查询策略
    ↓
5F 项目站点/渠道 slug、canonical identity 与公开边界

5B + 5D + 5E + 5F
    └── 5G 全链路回归与阶段闭环
```

5A→5B 与 5C→5D 是两条可分别推进的后台/worker 线路；5E 必须使用阶段 3/4 已冻结的 gate、批次和目标结果，5F 必须在 slug 作用域决策完成后实施；5G 等所有入口具备项目 owner 后再收敛。

### 允许进入阶段 6

- 5A 的 Enterprise 项目 owner、legacy 回填、source/revision/KnowledgeBase 关系和迁移约束通过；
- 5B 的 Enterprise 后台、draft worker、revision、发布/删除和队列恢复按项目隔离；
- 5C 的 URL Import job 创建、列表、详情、run/status/history/commit 授权和幂等合同通过；
- 5D 的抓取、预览、commit、资料产物继承、重试/恢复和失败分类通过；
- 5E 的中央站所有现有公开查询、SEO/schema、分页和缓存/读回条件统一通过资格 resolver；
- 5F 的 slug/canonical identity 冲突决策、约束和公开边界通过；
- 5G 的跨项目、legacy/new、并发/重启、敏感日志和性能证据通过。

### 必须停留在阶段 5

- Enterprise source/revision/KnowledgeBase 或 URL Import 产物没有稳定项目 owner，或可以通过请求体、job payload、旧 session 越权；
- worker/commit 重启、重试或并发会产生重复资料、跨项目资料或无法解释的半成品；
- 中央站任一首页、搜索、archive、category、article、related、SEO 或 sitemap/静态输出绕过 `published`、gate 或 `central_site_allowed`；
- slug 冲突、停用项目、历史 URL 或 canonical identity 没有明确结果；
- 通过前端隐藏或内存过滤掩盖数据库/查询层缺口，或引入客户登录、客户上传、客户 token 和外部资料接收层；
- 只验证 Enterprise 或 URL Import 单条路径，没有全链路和公开前台回归证据。

## 4. 阶段 5 交付物

阶段 5 完成时只允许产生以下结果：

1. Enterprise Knowledge 项目 owner、source/revision/KnowledgeBase 隔离和 legacy 回填证据；
2. Enterprise 草稿/worker/发布知识库的项目授权与幂等恢复合同；
3. URL Import job、日志、worker、commit 和资料产物的项目继承合同；
4. 中央站统一公开资格 resolver、查询过滤、SEO/分页/读回证据；
5. 项目站点/渠道 slug、canonical identity 和冲突处理合同；
6. 全链路跨项目、legacy、并发/重启、性能和敏感信息验证报告。

不得在阶段 5 产生客户登录/上传、客户 token、客户直连 API、外部资料接收层、客户回执入口、完整项目官网或阶段 6 的项目 quota/队列公平性系统。

## 5. 执行方式

本助手按 `5A → 5B`、`5C → 5D` 两条后台线路推进，再执行 `5E → 5F → 5G`。每个工作包开始前先核对阶段 1–4 的实际 owner、迁移和 gate/batch 合同，重新发现真实 admin/API/CLI/worker/公开路线；完成后先运行该包最小验证，再更新本文件和总计划。若运行环境缺少 PHP CLI 或 discovery/preflight 依赖，只记录静态证据和未验证层，不把文档检查描述为运行时成功。

