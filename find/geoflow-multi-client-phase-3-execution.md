# GEOFlow 多客户改造：阶段 3 独立执行计划

> 关联总计划：[geoflow-multi-client-implementation-audit.md](D:/GEOFlow-2.3.0/find/geoflow-multi-client-implementation-audit.md)
>
> 前置计划：[geoflow-multi-client-phase-2-execution.md](D:/GEOFlow-2.3.0/find/geoflow-multi-client-phase-2-execution.md)
>
> 执行模式：GEOFlow `development` / 公开状态转换、队列调度、后台/API/CLI 入口和外部发布副作用
>
> 当前状态：阶段 3（3A-3F）已完成；完整套件仍保留阶段 2 的既有项目作用域 403 失败，已分类并登记，不属于阶段 3 回归。

## 1. 阶段目标

让 `publication_gate = legacy_auto | platform_approval` 成为 GEOFlow 所有公开状态转换和外部发布副作用的唯一项目规则。legacy 项目继续保留现有自动发布语义；新项目在运营人员审核通过后仍只能停留在草稿或待平台审批状态，不能因为某个 Worker、API、后台、CLI、调度器或分发路径未接入门闸而提前公开。

阶段 3 的事实 owner 是统一的文章工作流状态转换与发布门闸服务。内容审核状态（`pending/approved/rejected`）和平台公开许可必须分离；`approved` 本身不代表已经获得公开发布许可。

客户仍不是 GEOFlow 用户。客户不登录、不持有 token，也不直接上传或调用本系统；运营人员继续在项目上下文中沿用现有知识库、关键词库、标题库和图片库后台流程。阶段 3 不建设客户资料接收层、客户门户或项目官网。

## 2. 拆分后的工作包

### 3A：发布门闸合同、默认值与状态矩阵

**执行状态：已完成（2026-08-23）**

- 新增 `PublicationGate`（`legacy_auto` / `platform_approval`）及 `publication_gate` 项目字段迁移；新项目默认 `platform_approval`，legacy 项目显式回填/校正为 `legacy_auto`。
- 新增 `PublicationGateContract`，覆盖 local/channel/manual 目标、项目状态、审核状态、中央站许可、渠道 membership 和平台审批；`approved` 不自动授予平台公开许可。
- 验证：`docker compose run --rm app php artisan test tests/Unit/PublicationGateContractTest.php tests/Unit/ClientProjectDomainSchemaTest.php`；11 tests、51 assertions 通过。
- 发现脚本在当前工作区缺失；验证使用 Docker 容器内 PHP，未执行宿主机 PHP。

**目标**

- 冻结 `publication_gate` 的允许值、项目默认值、legacy 回填规则和非法值处理。
- 明确文章本地状态、内容审核状态、平台公开许可、目标发布状态之间的转换矩阵，为所有入口提供同一合同。
- 明确“公开发布”和“仅分发准备/待审批”边界，保证阶段 4 的 publication batch 可以复用而不需要重新解释门闸。

**边界**

- 只处理项目级 gate 字段/合同、迁移或回填所需的持久化规则和状态矩阵；不实现 publication batch、目标快照或批次 UI。
- legacy 项目必须显式保持 `legacy_auto`；新项目必须显式使用 `platform_approval`，不能依赖控制器、Blade 或配置文件的隐式默认。
- 内容审核通过不自动升级为公开许可；项目停用、渠道 membership 无效和风险门闸失败优先阻止公开转换。
- 不新增客户账号、客户 token、客户上传 API 或外部资料导入身份。

**最小验证**

- 两种 gate、草稿/审核/批准/公开及目标类型的完整矩阵已落成可执行测试，非法值和缺失项目上下文被拒绝。
- legacy 数据读回仍按 `legacy_auto` 解释；新项目读回为 `platform_approval`，不会把两者混成全局默认。
- 矩阵明确列出本地公开、渠道分发、人工发布和失败/不确定结果，不包含尚未实现的批次状态。

### 3B：统一状态转换与发布门闸 owner

**执行状态：已完成（2026-08-23）**

- `ArticleWorkflowTransitionService` 在同一行锁事务内、风险扫描和状态写入前调用 `PublicationGateContract`；`platform_approval` 未获许可抛出 typed `PublicationGateException`，拒绝不写状态、不产生风险扫描。
- legacy 项目和无项目旧文章保持既有自动发布兼容；重复公开 transition 受行锁与现有风险门闸保护。
- 修复 `ArticleGeoFlowService::updateArticle` 风险事务闭包遗漏项目上下文的问题。
- 验证：`docker compose run --rm app php artisan test tests/Feature/ArticleWorkflowTransitionServiceTest.php`（5 tests、19 assertions）；API 风险相邻回归（2 tests、15 assertions）通过；Worker 风险回归通过。
- 当前边界：API/Admin 对 `PublicationGateException` 的稳定 HTTP 409 映射留在 3D；Worker/分发外部结果分类留在 3C/3E。

**目标**

- 将 `ArticleWorkflowTransitionService`（或当前代码中等价的唯一状态 owner）接入 gate 检查、状态转换、审计和幂等控制。
- 让所有调用方通过统一入口表达“审核、批准、公开、撤回、失败、取消”等转换，不在控制器、Job 或模板中复制业务判断。
- 定义外部副作用发生前后的明确结果：未开始、确认失败、确认成功和结果不确定。

**边界**

- 统一 owner 负责本地文章状态和允许的转换；不在此工作包实现批次/条目状态机或远程连接器。
- gate 检查必须发生在公开状态写入和外部 dispatch 之前；远端请求已发出但无法确认时不得伪装成普通失败并盲目重试。
- 保留 legacy 自动发布行为，但不能为兼容而保留第二套状态机或旁路 writer。

**最小验证**

- 直接调用统一 owner 在两种 gate 下均能得到允许/拒绝结果，拒绝不会修改公开状态或产生外部副作用。
- 重复请求、并发 transition、重启后的重试不会重复推进状态；锁或唯一约束能证明同一事实只有一个 writer。
- 外部调用异常能被转换为稳定的结果类型/错误码，调用方可区分未开始、确认失败和不确定。

### 3C：Worker、队列、调度与 CLI 接入

**执行状态：已完成（2026-08-23）**

- `WorkerExecutionService` 在执行时重新读取项目 gate；`platform_approval` 只返回 `await_platform_approval` 草稿结果，不触发分发入队，legacy 项目保持自动发布。
- 队列/恢复路径复用同一 Worker gate 检查；Job payload 与日志合同未增加 gate 可覆盖上下文或敏感正文。
- 验证：`docker compose run --rm app php artisan test tests/Feature/WorkerArticleRiskWorkflowTest.php tests/Feature/WorkerProjectIsolationTest.php tests/Feature/TaskTransactionSafetyTest.php`；21 tests、102 assertions 通过。

**目标**

- 封闭 Worker 生成、定时任务、队列 Job、stale recovery 和 CLI 手工触发的所有自动公开路径。
- `platform_approval` 项目中，Worker 只生成草稿或待审批结果；`legacy_auto` 项目继续按既有合同自动公开。
- 让重试、恢复和人工 CLI 触发都重新读取项目 gate，而不是信任旧 payload 或旧 session。

**边界**

- 覆盖 `WorkerExecutionService`、`ProcessGeoFlowTaskJob`、发布相关 Job、`GeoFlowWorkerCommand`、调度任务和现有 CLI 入口；不改造 API/admin 入口本身。
- Job payload 只携带稳定业务 ID；执行时重新解析任务、文章和项目，不序列化可覆盖 gate 的上下文。
- 不实现 publication batch、平台审批 UI、客户入口或新的自动调度策略。

**最小验证**

- 两种 gate 下的定时生成、到期草稿、队列重试、stale recovery 和 CLI 触发均有正向/拒绝测试；`platform_approval` 不会公开文章。
- Job 重复执行、重启和并发 claim 不产生重复公开状态或重复分发入队；gate 失败留下可审计的明确结果。
- 现有 legacy 自动发布回归通过，日志和 payload 不泄露密钥、完整提示词或文章正文。

### 3D：API、后台审核/发布与批量状态入口

**执行状态：已完成（2026-08-23）**

- API 统一将 `PublicationGateException` 映射为稳定 HTTP 409 `publication_gate_blocked`，返回 gate code、target 和 gate，不写入公开状态。
- 后台单条/批量入口复用统一 transition owner；无项目 legacy 后台在系统尚无项目时保持兼容，项目存在后 operator 必须具备明确项目上下文。
- 验证：API 风险工作流通过；后台文章风险及页面回归 20 tests、145 assertions 通过。

**目标**

- 将 API 和后台文章创建、更新、审核、发布、撤回及单条/批量状态操作全部接入统一 gate 与状态 owner。
- 明确内部 operator、项目负责人和超级管理员的操作边界；角色权限不能绕过项目 gate。
- 保持内容审核与平台公开审批分离，避免“审核通过”按钮直接触发新项目公开。

**边界**

- 覆盖 `ArticleGeoFlowService`、API 文章控制器、管理员文章控制器、批量操作和相关 Form Request/Policy；不建设客户管理入口。
- 请求体中的 `project_id` 只能作为目标范围校验，不能成为越权依据；token 绑定项目和后台项目上下文优先。
- 批量操作只允许在单一明确项目范围内执行；不实现阶段 4 的 publication batch 提交、审批和目标快照。

**最小验证**

- 两种 gate、每个内部角色、单条/批量和每个目标类型都有允许/拒绝测试；跨项目 ID、混合项目批量请求返回稳定的 403/404/409 结果。
- `platform_approval` 下任何 API/admin 公开按钮或批量动作都不能写入公开状态或触发分发；legacy 路径保持兼容。
- 重复提交、并发操作和失败回滚不会产生半完成状态、重复审核记录或重复外部 dispatch。

### 3E：分发与人工发布的外部副作用边界

**执行状态：已完成（2026-08-23）**

- `DistributionOrchestrator` 在非删除分发入队、队列发送、站点内容刷新前重新读取项目 gate；后台 retry 也在 dispatch 前拒绝 `platform_approval`，删除动作仍可执行，legacy 保持旧语义。
- `ManualPublicationService` 创建关联项目文章的人工发布记录前阻断 `platform_approval`，控制器返回稳定 `publication_gate_blocked` 错误；不会创建本地工单或触发远端副作用。
- `ProcessArticleDistributionJob` 将门闸拒绝记录为 `failed` / `publication_gate_blocked` 终态，不进入自动 retry；日志仅包含稳定错误字段，不写入正文或凭据。
- 验证：`ManualPublicationServiceTest` 与 `DistributionArticleRiskWorkflowTest` 共 21 tests、70 assertions 通过；Worker/分发回归共 24 tests、91 assertions 通过。

**目标**

- 把 `DistributionOrchestrator`、分发 Job、`ManualPublication` 及人工发布控制器的外部副作用纳入同一 gate。
- 在远端调用、人工工单创建或分发入队前检查项目 gate，并记录真实的本地 observation 和远端结果。
- 消除“只记录日志并当作成功”的吞错路径，建立后续阶段可读取的失败/不确定事实。

**边界**

- 只处理阶段 3 所需的 gate 检查、调用前置条件和结果分类；不实现阶段 4 的 publication batch/item、版本 hash、目标快照或 reconciliation UI。
- 不因某个目标失败而抹掉其他目标的真实结果；不对不确定的远端请求自动盲重试。
- 人工发布仍是内部运营流程，客户不获得工单、回执或登录入口。

**最小验证**

- `platform_approval` 下未获许可时不会 enqueue、创建远端对象或创建人工发布副作用；legacy 项目可按旧语义分发。
- 分发/人工操作能读回未开始、确认失败、确认成功和不确定四类结果，原始业务错误不会被 cleanup 或日志异常覆盖。
- 重复 dispatch、重试和并发调用不会重复创建可观察的外部副作用；敏感配置和正文不写入日志。

### 3F：全入口状态矩阵、legacy 回归与幂等闭环

**执行状态：已完成（阶段 3 范围，2026-08-23）**

- 汇总 3A-3E 的 gate、API/admin、Worker、分发和人工发布证据；未新增 publication batch、客户入口或项目官网。
- 聚焦回归、`git diff --check` 和改动 PHP Docker lint 通过；分发入队、发送前、刷新和 retry 旁路均已重新检查 gate。
- 人工发布工单在创建和 `completed` 外部成功确认两个边界重新读取项目 gate，项目在工单生命周期中切换为 `platform_approval` 时也不会形成成功事实。
- 阶段 3 范围综合回归为 112 passed、629 assertions；其中 3 个文章页面断言失败均为阶段 2 已存在的项目上下文/全局后台权限契约，归类为 `EXPOSED_PREEXISTING`，不是阶段 3 引入的回归。
- 完整 `docker compose run --rm app php artisan test` 结果为 1261 passed、1 skipped、145 failed；失败同样集中在阶段 2 已明确保留为超级管理员专属的全局后台页面及旧测试未提供项目上下文的文章资产上传路径，不通过放宽阶段 2 权限来伪造 3F 全绿证据。
- 未执行真实第三方远端写操作；生产凭据和外部平台人工确认仍属于上线前人工操作。

**目标**

- 汇总所有公开入口形成“入口 × gate × 目标 × 结果”验收矩阵，证明没有遗漏的旁路 writer。
- 完成 legacy 项目回放、新项目拒绝、并发/重试/重启和不确定远端结果的端到端回归。
- 输出进入阶段 4 的 gate 合同和未解决风险清单。

**边界**

- 只做跨工作包的证据收敛、回归和静态旁路扫描；不新增阶段 4 批次能力、阶段 5 公开前台能力或阶段 6 资料运营能力。
- 验收必须覆盖 Worker、API、后台、CLI、调度、队列恢复、分发和人工发布；不能用单一 Worker 测试代替全入口证明。
- 发现无法安全接入的历史入口时，必须限制其可用范围并记录 owner，不以菜单隐藏或内存过滤宣称完成。

**最小验证**

- 全矩阵正向/拒绝测试、legacy 回归、跨项目拒绝、重复/并发 transition、远端不确定结果和敏感日志扫描全部通过。
- 静态检查确认不存在直接写公开状态或绕过统一 owner 的生产入口；路由、调度和 CLI 清单与矩阵一致。
- 阶段报告能够证明 `platform_approval` 新项目在任何现有入口都不会未经许可公开，且未引入 publication batch 或客户接收层。

## 3. 工作包依赖与阶段闸门

```text
3A 发布门闸合同、默认值与状态矩阵
    ↓
3B 统一状态转换与发布门闸 owner
    ├── 3C Worker/队列/调度/CLI
    ├── 3D API/admin 审核、发布与批量状态
    └── 3E 分发与人工发布外部副作用

3C + 3D + 3E
    └── 3F 全入口矩阵、legacy 回归与幂等闭环
```

3C、3D 和 3E 可以在 3B 验证通过后分别实施，但每个包都必须使用同一个 gate 和状态 owner；3F 必须等三条入口线都完成后才能开始。工作包之间的并行不表示跳过各自最小验证。

### 允许进入阶段 4

- 3A 的 gate 值、默认/回填规则和状态矩阵已冻结并有持久化证据；
- 3B 的统一 transition owner 已覆盖公开状态转换，拒绝和不确定结果不是隐式成功；
- 3C 的 Worker、队列、调度和 CLI 无法让 `platform_approval` 项目自动公开；
- 3D 的 API、后台单条/批量操作无法绕过 gate，角色和项目授权矩阵通过；
- 3E 的分发与人工发布在外部副作用前检查 gate，并可读回四类结果；
- 3F 的全入口矩阵、legacy 回归、并发幂等和敏感日志检查通过；
- 阶段 3 未混入 publication batch、客户接收层、客户账号或项目官网。

### 必须停留在阶段 3

- 任一控制器、Job、调度器、CLI、分发器或人工发布路径可以直接写公开状态或在 gate 前产生外部副作用；
- `approved` 仍被当作公开许可，或新项目存在隐式 `legacy_auto` 默认；
- 远端请求不确定被当作失败并盲目重试，或分发器吞掉真实错误；
- 重复/并发 transition 产生重复公开状态、重复 enqueue 或重复远端对象；
- 只有界面隐藏而没有 Service/事务层拒绝；
- 阶段 3 的任一入口缺少正向、拒绝、回滚和失败分类证据。

## 4. 阶段 3 交付物

阶段 3 完成时只允许产生以下结果：

1. `publication_gate` 合同、默认/回填规则和可执行状态矩阵；
2. 唯一的文章状态转换与发布门闸 owner；
3. Worker、队列、调度、CLI、API、后台、分发和人工发布入口的 gate 接入；
4. 未开始、确认失败、确认成功和不确定的副作用结果记录；
5. legacy 自动发布回归、跨项目拒绝、并发/重试/重启和敏感日志证据；
6. 进入阶段 4 publication batch 的调用合同和风险清单。

不得在阶段 3 产生 publication batch/item、版本快照、平台审批批次 UI、客户登录/上传、外部资料接收层、中央站多客户公开承载或项目官网。

## 5. 执行方式

本助手按 `3A → 3B → (3C/3D/3E) → 3F` 执行。每个工作包开始前先核对阶段 1/2 的实际代码和迁移状态，确认公开入口清单；完成后先运行该包最小验证，再更新本文件和总计划中的状态。发现某个入口无法安全迁移时，先限制该入口的真实副作用并记录阻塞证据，不以新增旁路兼容层或前端隐藏替代修复。
