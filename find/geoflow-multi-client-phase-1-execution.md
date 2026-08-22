# GEOFlow 多客户改造：阶段 1 独立执行计划

> 关联总计划：[geoflow-multi-client-implementation-audit.md](D:/GEOFlow-2.3.0/find/geoflow-multi-client-implementation-audit.md)
>
> 前置计划：[geoflow-multi-client-phase-0-execution.md](D:/GEOFlow-2.3.0/find/geoflow-multi-client-phase-0-execution.md)
>
> 执行模式：GEOFlow `development` / 迁移、领域基础、授权合同和只读回归
>
> 当前状态：计划已拆出，尚未执行阶段 1 的代码、迁移、回填或权限切换

## 1. 阶段目标

建立客户项目的持久化基础、legacy 承载、内部员工项目访问合同、内部 API token 绑定和项目级 AI 用量事件入口，为阶段 2 的资源隔离提供唯一 owner 和授权上下文。

阶段 1 的完成结果应是：系统能够安全地表达“某个内部员工在某个项目中具有什么角色”，旧数据能够可重复地归入 `legacy` 项目，后续 Service/Job 可以读取项目上下文；但尚未完成所有业务资源页面和任务→文章链路的项目过滤，也不改变公开发布审批语义。

客户不是本系统用户。阶段 1 不建设客户登录、客户 session、客户 token、客户上传 API、客户 webhook 或外部资料接收层；运营人员继续使用现有后台流程，项目上下文只用于 GEOFlow 内部归属和授权。

## 2. 拆分后的工作包

### 1A：项目与成员领域模型、基础 Schema

**目标**

- 建立 `clients`、`client_projects`、`client_project_members` 的最小领域模型、关系、状态和角色枚举。
- 固化项目状态、成员状态、成员唯一性、客户与项目的归属关系和审计字段。
- 为后续 owner 资源、项目上下文和 token 绑定提供稳定的数据库/模型合同。

**边界**

- 只建立项目/成员基础结构、模型和必要索引，不给现有业务表回填项目，不开放项目写入口。
- `Client` 仍是内部客户档案和归属标签；`ClientProject` 是员工使用的工作空间；两者都不是登录主体。
- 不把 `EnterpriseKnowledgeProject` 重命名为客户项目，不把 `admins.role` 当作项目成员关系。
- PostgreSQL 和 SQLite 的迁移差异必须在本工作包内记录；不能用只在 PostgreSQL 有效的约束假装 SQLite 已覆盖。

**最小验证**

- fresh migration 在 PostgreSQL、SQLite 两种结构合同上均可执行；down 只删除本工作包新增结构。
- 同一管理员在同一项目不能重复拥有同一成员关系；停用项目/成员状态可被模型正确读取。
- 模型关系和枚举不产生任何现有业务数据写入；敏感字段不进入日志或导出。

### 1B：资源 owner 字段、渠道 membership 和复合关系合同

**目标**

- 给直接 owner 资源增加可回填的 `client_project_id`，并建立必要索引。
- 按阶段 0C 已冻结的“平台渠道 + 项目成员关系”模型，建立项目与 `distribution_channels` 的授权关系表。
- 明确知识库、库、任务、文章、作者、分类、Enterprise Knowledge、URL import、manual publication 等 owner 表的关系路径。

**边界**

- 只做 nullable 字段、关系表、索引、可表达的复合约束和模型关系；不执行 legacy 回填、不把字段改成非空、不修改现有渠道密钥。
- 子表不机械复制 `client_project_id`；授权查询通过直接 owner join 解析项目。需要快照时必须标记为派生数据，不能成为第二个授权事实。
- 渠道本体和密钥仍归平台；项目 membership 只决定授权使用，不复制 secret，不把渠道改成单项目 owner。
- 不在本工作包改造所有控制器、Blade 列表或 Job；阶段 2 负责业务链路过滤。

**最小验证**

- owner 表、子表、渠道 membership 的 schema 关系图和迁移顺序可读；每个新增字段都有回填来源和回滚边界。
- 同一项目的渠道 membership 可唯一；重复授权、撤销中的授权和跨项目 task-channel 关系都有明确拒绝规则。
- PostgreSQL 能使用的复合外键/唯一约束与 SQLite 无法表达的部分分别有等价事务不变量说明；不允许只依赖控制器校验。

### 1C：legacy 客户/项目承载与幂等回填

**目标**

- 创建一个明确标记的 legacy 客户和 legacy 项目，承载当前单站点/全局数据。
- 按 0B invariant report 的 owner 清单回填项目归属、渠道 membership 和必要的 manual publication owner。
- 让回填可以重复执行、可审计、可读回，不删除原业务事实。

**边界**

- 执行顺序固定为：迁移结构 → 只读 preflight 复核 → 创建/锁定 legacy carrier → 分批回填 → count/NULL/孤儿/mismatch 校验 → 记录结果。
- 回填只写新增项目归属和授权关系；不重写文章正文、渠道 secret、外部对象 ID、历史状态或客户资料正文。
- 不使用 `CASCADE` 删除业务表；回滚只能撤销本次新增结构或未提交的回填批次，已确认的 legacy 基线必须通过 repair/forward migration 处理。
- 若 0B 报告仍有未分类异常，本工作包立即停在 preflight，不得用默认 legacy 归属掩盖异常。

**最小验证**

- 回填前后所有 owner 表的业务 count、关键外键和外部 ID 保持一致；新增归属字段无 NULL，或有逐项书面例外。
- 回填重复执行不重复创建 legacy carrier、membership 或审计记录；中断后可从稳定批次位置恢复。
- PostgreSQL/SQLite 的 fresh、已有数据回填、约束检查和 down-safety 均有证据；失败时能区分未开始、部分完成和已确认完成。

### 1D：项目上下文、成员授权和后台切换合同

**目标**

- 建立统一的 `ProjectAccessService`、项目上下文解析、成员能力判断和停用/撤销失效规则。
- 支持内部员工在自己被分配的项目之间切换；每次请求重新确认成员关系和项目状态。
- 固化超级管理员跨项目只读与显式目标写入的边界。

**边界**

- 本工作包只建立上下文/授权基础设施和最小切换入口，不一次性改造所有资源页面；未完成项目过滤的页面不得开放给普通 `operator`。
- URL `project_id`、隐藏下拉框、客户编号或 session 中的旧值都不是授权凭证；授权必须来自数据库成员关系和项目状态。
- 混合项目批量写入一律拒绝；跨项目只读报表若未来需要，必须有单独明确合同。
- 不创建客户登录或客户门户，不向客户发 token。

**最小验证**

- 同一管理员可在已分配的两个项目之间切换；撤销成员、停用项目、过期上下文后旧 session 立即失效。
- 未分配项目的详情、写入和批量操作在统一入口得到一致 403/404 结果，不泄露资源存在性。
- 超级管理员写操作没有明确目标项目时被拒绝；混合项目请求在进入业务 Service 前被拒绝。
- 项目切换和授权失败会留下不含敏感正文/密钥的审计记录。

### 1E：内部 API token 项目绑定与 legacy 兼容

**目标**

- 为内部员工和内部 CLI 建立绑定单一项目的 token 合同。
- 将旧 token 标记为 `legacy_global`，仅允许超级管理员在兼容期使用，并提供可审计的撤销期限。
- 让 token 绑定与路由项目 ID 比对，防止请求体中的任意 `project_id` 变成授权凭证。

**边界**

- 只处理 GEOFlow 内部 API/CLI；任何 token 都不发给客户或客户使用的外部工具。
- 不新增客户 API，不把 token secret、原始 Bearer 值或渠道密钥写入队列、日志、文章或导出。
- 不把 `articles:publish` 的 scope 当作绕过项目 `publication_gate` 的权限；公开发布门闸属于阶段 3。
- 兼容期必须有退出/撤销条件；不能无限期保留 `legacy_global`。

**最小验证**

- 授权矩阵覆盖：项目绑定 token、路由项目 ID 一致/不一致、旧 global token、非超级管理员、停用项目、撤销 token 和过期 token。
- token 绑定不能由请求体覆盖；混合项目批量请求被拒绝；token 被撤销后立即失效。
- 403/404 规则、审计字段和日志脱敏有 feature test 或等价证据；测试不输出原始 token。

### 1F：项目级 AI usage observation 入口

**目标**

- 建立不可变的 `ai_usage_events`/observation 表、模型和追加式 writer。
- 记录项目、模型、operation、attempt、units、outcome、fallback 和 reservation 生命周期，为阶段 6 的项目 quota 提供事实基础。
- 保留现有平台级 `ai_models`/provider daily quota，不在本工作包改成项目 quota。

**边界**

- 只建立事件事实和 reservation 生命周期记录，不实现项目账单、存储限制、文章数限制或队列公平性。
- 事件只记录必要审计字段，不保存 API Key、提示词正文、完整文章正文、渠道 secret 或不必要的个人信息。
- 项目级调用必须有已验证项目上下文；平台级诊断必须使用明确的 platform/system scope，不能把任意请求体项目 ID写成归属。
- 事件 writer 是唯一追加入口，项目成员不能修改、删除或重放为新的成功调用。

**最小验证**

- 成功、失败、fallback、重试和不确定调用分别产生可读事件；reservation 重复 finalize 不重复记账。
- 事务回滚不留下“已成功”的假事件；事件追加失败不会覆盖原始 AI 业务错误。
- 事件查询可按项目/模型/operation/时间读取，日志和事件 payload 均无密钥与敏感正文。

## 3. 工作包依赖与阶段闸门

```text
1A 项目/成员基础 Schema
    ↓
1B owner 字段 + 渠道 membership 合同
    ↓
1C legacy carrier 与幂等回填
    ↓
1D 项目上下文与后台授权
    ↓
1E 内部 token 绑定与兼容期

1A + 1B ─────────────→ 1F AI usage observation
```

1F 可以在 1D 之后接入真实项目上下文；在此之前只能完成 schema/writer 的静态合同，不能宣称项目级事件已经覆盖所有调用。

### 允许进入阶段 2

- 1A/1B 的 PostgreSQL、SQLite 迁移和模型关系验证通过；
- 1C legacy 回填前后 count、NULL、孤儿和 mismatch 有结果，且重复执行幂等；
- 1D 的成员、项目状态、session context 和显式目标规则通过；
- 1E 的 token binding、legacy 兼容和撤销期限有授权矩阵；
- 1F 的事件追加、失败/回滚/重复 finalize 语义通过；
- 没有把客户入口、客户 token 或未完成过滤的普通运营页面混入阶段 1。

### 必须停留在阶段 1

- owner 清单仍有未分类数据风险；
- legacy 回填无法证明可重复、可读回或不删除现有业务事实；
- 普通运营人员能通过未完成过滤的页面读取全局数据；
- 请求体可覆盖 token/project 绑定；
- usage event 不能区分未开始、失败、成功和不确定；
- 任一迁移只在 PostgreSQL 或只在 SQLite 可用而没有等价不变量证据。

## 4. 阶段 1 交付物

阶段 1 完成时只允许产生以下结果：

1. 项目/成员领域模型和迁移；
2. owner 字段、渠道 membership 和跨资源关系合同；
3. legacy carrier、回填脚本/命令和回填报告；
4. 项目上下文、成员授权和内部 API token 合同；
5. 不可变 AI usage observation 入口及回归测试；
6. 阶段 2 所需的 owner 映射、授权入口和未解决风险清单。

不得在阶段 1 产生完整的知识库→任务→文章隔离、统一 publication gate、publication batch、客户门户、客户资料接收层或项目官网。

## 5. 后续执行方式

本助手按 `1A → 1B → 1C → 1D → 1E` 执行，`1F` 在项目上下文可用后接入。每个工作包开始前再次核对当前源码和迁移合同；完成后先执行该工作包的最小验证，再更新本文件状态和交付物链接。

若迁移、数据库权限、容器环境或旧 token 事实不足以安全继续，停在对应工作包并记录阻塞证据，不用默认值或静态猜测推进。
