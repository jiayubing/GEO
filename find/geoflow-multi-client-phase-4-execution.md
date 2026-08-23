# GEOFlow 多客户改造：阶段 4 独立执行计划

> 关联总计划：[geoflow-multi-client-implementation-audit.md](D:/GEOFlow-2.3.0/find/geoflow-multi-client-implementation-audit.md)
>
> 前置计划：[geoflow-multi-client-phase-3-execution.md](D:/GEOFlow-2.3.0/find/geoflow-multi-client-phase-3-execution.md)
>
> 执行模式：GEOFlow `development` / 目标级发布批次、审批、本站发布、远程渠道和人工工单
>
> 当前状态：4A–4H 代码实施与容器验证完成；尚未执行真实远程渠道写入、人工工单创建或本站内部试点。

## 1. 阶段目标

建立按“文章 × 目标”编排的 publication batch，使运营人员可以在项目上下文中提交已审核文章，平台负责人可以审批或退回，系统再按冻结的文章版本和目标快照执行本站发布、远程渠道分发或人工发布工单。

阶段 4 的事实 owner 是 publication batch/item 编排与状态服务；文章内容审核仍由文章 `review_status` 拥有，`ManualPublicationService` 仍拥有人工工单状态，`DistributionOrchestrator` 仍拥有已有分发执行事实。batch item 只编排、关联和投影这些结果，不建立第二套事实状态机。

客户仍不是 GEOFlow 用户。客户不登录、不持有 GEOFlow token，也不直接上传或调用系统；运营人员继续使用现有后台维护知识库、关键词库、标题库和图片库。阶段 4 不建设客户资料接收层、客户门户、客户回执入口或项目官网。

## 2. 拆分后的工作包

### 4A：publication batch/item 数据模型与状态不变量

**目标**

- 建立 `publication_batches` 与 `publication_batch_items` 的最小持久化结构、模型关系、枚举/状态和审计字段。
- 固定 batch 只能属于一个 `client_project_id`，item 按“文章 × 目标”独立建模，禁止把多个渠道压缩为一个 item。
- 定义 batch 状态与 item 状态的唯一 writer、允许转换、终态和部分成功/不确定语义。

**边界**

- item 至少支持 `local`、`channel`、`manual` 三类目标；目标 canonical identity、action、版本/hash、快照、幂等键和结果分类必须可持久化。
- 只建立 schema、关系、约束、索引、cast 和回滚安全；不实现提交/审批界面、执行器或远程连接器。
- `item.approved` 只表示平台负责人批准该 item；文章 `review_status` 仍是内容审核事实，不能互相覆盖。
- 不把 `publication_batches` 绑定为 Enterprise Knowledge 项目，也不新增客户账号或客户导入身份。

**最小验证**

- PostgreSQL 与 SQLite 的 fresh migration、关系、枚举/cast、索引和 down-safety 均有证据；batch、item 不能跨项目关联。
- 状态矩阵能够拒绝非法跳转，并区分 `completed`、`partial`、`failed`、`uncertain`；重复创建同一事实不会绕过唯一约束。
- 敏感渠道配置、API Key、token、完整文章正文不进入 snapshot、日志或 batch 导出。

### 4B：目标解析、文章版本冻结与幂等身份

**目标**

- 建立从文章、任务、项目渠道 membership、本站目标和人工账号解析目标的唯一服务边界。
- 在提交时冻结文章版本/content hash、目标 canonical identity、渠道能力/配置版本和必要的目标快照。
- 形成覆盖 project、article revision/hash、target type、target identity 和 action 的幂等身份。

**边界**

- 目标解析必须在 Service/事务层完成；不能信任请求体中的目标项目、channel ID、远端 URL 或客户端生成的幂等键。
- 只保存发布所需的非敏感快照；渠道 secret、私有 token 和不必要正文不复制到 batch item。
- 现有 `article_distributions` 的 `(article_id, distribution_channel_id, action)` 唯一键不能直接充当新版本合同；必须明确 batch item 与既有分发事实的引用/投影关系。
- 不执行远端写操作，不创建人工工单，不实现 batch 状态推进。

**最小验证**

- 同项目文章和目标可解析，跨项目 task/channel membership、停用项目、无效目标和未配置目标均被拒绝。
- 修改文章版本、目标 canonical identity 或渠道配置版本后，旧 item 被标记失效或要求重新提交，不会静默复用旧快照。
- 相同业务事实重复提交得到同一幂等结果；不同文章版本或目标生成不同 generation，数据库唯一约束可证明。

### 4C：运营提交与批次草稿编排

**目标**

- 允许 operator 在单一项目范围内从已通过内容审核的文章创建 batch 草稿并提交审批。
- 支持单条和明确项目内的批量选择，生成一篇文章对应多个目标 item；提交时执行完整的文章、项目、gate、目标和版本校验。
- 定义 `draft → submitted`、撤回/修订后重新提交以及空批次/重复提交的结果。

**边界**

- operator 只能提交自己有成员权限的项目；不能批准、强制公开或通过请求体覆盖项目上下文。
- 文章必须满足阶段 3 gate、内容审核、中央站许可、目标可用性和版本一致性；不在此工作包执行本站或远程发布。
- 不建设客户审批入口，不把 batch 提交等同于平台批准；不允许跨项目混合批次。

**最小验证**

- 同项目 operator 能创建、查看、编辑草稿并提交；跨项目文章、混合项目选择、未审核文章、停用目标和 gate 阻断均被拒绝。
- 重复点击、重放请求和并发提交不会生成重复 batch/item 或重复审计记录；提交结果可稳定读回。
- 文章在提交后变更版本、项目停用或目标 membership 失效时，发布前能发现 stale 并要求重新提交。

### 4D：平台审批、退回与逐 item 决策

**目标**

- 建立 platform approver 对 batch 和 item 的批准、退回、拒绝、异常项目逐 item 处理及审计流程。
- 明确 batch 总状态与 item 状态的聚合规则，避免一个 item 失败覆盖其他 item 的真实结果。
- 让批准动作只产生“可执行许可”，不直接绕过执行器触发本站、渠道或人工外部副作用。

**边界**

- platform approver 只能审批有权限项目的 submitted batch；operator 不能批准自己的 batch，super_admin 写操作仍需显式目标项目。
- 批准必须再次校验文章版本、项目状态、gate、目标 membership 和快照完整性；不满足条件则返回稳定冲突结果。
- `ManualPublicationService` 和 `DistributionOrchestrator` 的事实状态仍由各自 owner 管理；本包不读写远端对象。

**最小验证**

- operator/platform approver/super_admin 的提交、批准、退回、拒绝矩阵通过；无权限、跨项目、过期 session 和重复审批均被拒绝或幂等收敛。
- 批次全批准、部分批准、退回和逐 item 异常的状态聚合可读回，`item.approved` 不会直接变成 published。
- 批准前后文章 hash、目标快照和审批人可审计；版本不一致不会创建可执行 item。

### 4E：本站目标执行器与受控内部试点

**目标**

- 为 `local` item 建立从已批准 item 到现有文章状态转换 owner 的执行器，完成本站发布并回写 item 结果。
- 复用阶段 3 的 `ArticleWorkflowTransitionService`、项目 gate 和幂等边界，不在 batch 执行器中复制公开状态逻辑。
- 通过单项目、少量文章的内部试点验证版本冻结、重复执行、失败分类和回读。

**边界**

- 只执行已经批准且快照仍有效的 local item；不实现中央站多客户过滤、项目官网或新的前台路由，前台边界留给阶段 5。
- 本地发布成功只更新对应 item 和既有文章观察，不把 batch 总状态提前写成 completed；其他 item 继续独立执行。
- 不触发远程渠道和人工工单；不把 local 发布结果伪装成所有目标都已完成。

**最小验证**

- 已批准 local item 成功发布并可读回文章/item/audit；未批准、过期 hash、无目标、项目停用和 gate 失败不会改变公开状态。
- 重复执行、并发 claim、进程重启和失败重试不会重复本站公开状态；本站执行失败可区分未开始、失败和不确定。
- 单项目少量文章试点通过后，才能允许受控本站发布；试点范围、目标和回滚边界有记录。

### 4F：远程渠道执行器与冻结快照分发

**目标**

- 为 `channel` item 复用 `DistributionOrchestrator` 和现有渠道 publisher，使用提交时冻结的目标/配置快照执行远程分发。
- 记录每个 item 的远端请求 observation、响应/回执、失败、部分成功和不确定结果。
- 保证渠道能力、canonical identity、项目 membership 和签名请求在执行前重新校验。

**边界**

- 只允许已批准、版本/hash 未失效、目标 membership 有效的 channel item 执行；不扩展新渠道连接器或客户可见渠道页面。
- 远端请求已发出但结果无法确认时必须进入 `uncertain`，由 readback/reconcile 决定后续动作；不得按普通失败盲目重试。
- 快照不包含渠道 secret；签名、鉴权和远端写操作继续由现有 publisher/HTTP client owner 负责。

**最小验证**

- channel item 的签名请求、能力/版本校验、幂等键、远端成功和失败分类通过；跨项目 channel binding 被拒绝。
- 网络超时、连接断开、非 2xx、重复回执和结果不确定均能读回；不确定状态不会自动创建重复远端对象。
- 同一 batch 的多个渠道目标互不覆盖结果；一个渠道失败不会抹掉其他 item 的成功观察。

### 4G：人工发布工单衔接与人工确认

**目标**

- 为 `manual` item 引用或创建对应 `manual_publications`，把 batch 编排状态与 `ManualPublicationService` 的人工工单事实正确衔接。
- 支持人工工单 ready/in-progress/completed/failed/cancelled 的结果投影和人工确认，保留原有评论类无 article 工单的项目 owner。
- 让人工发布只在 batch item 已批准且 gate/快照仍有效时发生。

**边界**

- `ManualPublicationService` 继续拥有人工工单状态；batch item 只保存关联 ID、编排结果和最后 observation，不反向覆盖工单状态机。
- 不向客户提供工单、回执、登录或操作入口；人工执行仍由内部运营人员完成。
- 不将人工确认成功推断为远程渠道成功，也不将 comment 工单强行绑定不存在的 article；项目 owner 必须明确可追溯。

**最小验证**

- manual item 能幂等创建/关联工单；未批准、项目停用、gate 变化和 snapshot 过期时不创建外部工单副作用。
- 人工工单完成、失败、取消、重复回调和无法确认结果能映射到 item/batch，不覆盖其他 item 的事实。
- 文章类与评论类工单的项目归属、权限、审计人和敏感内容边界均有回归测试。

### 4H：批次恢复、readback/reconcile 与阶段闭环

**目标**

- 建立批次级执行、恢复、重试、readback/reconcile 和最终状态聚合，确保远端不确定结果不会丢失或被盲重试。
- 汇总“项目 × batch × item × target × outcome”验收矩阵，形成进入阶段 5 的批次与渠道证据。
- 明确批次完成、部分完成、失败、不确定和人工介入所需的后续动作。

**边界**

- 只负责跨 4A–4G 的编排恢复、状态聚合、回读和证据收敛；不新增中央站前台、Enterprise Knowledge、URL Import 或客户接收能力。
- 修复必须围绕已有事实 owner、事务、锁和幂等合同进行；不新增旁路状态表或把缓存当事实来源。
- 真实第三方写操作需要明确目标、凭据、回读和回滚边界；没有这些条件时只做 mock/contract/read-only 验证。

**最小验证**

- batch/item 全状态矩阵、提交/审批/执行/回读/恢复/重启/并发和重复请求回归通过；总状态不会掩盖 item 级真实结果。
- `uncertain` 只能经 readback/reconcile 或明确人工决策后转为已确认结果；远端副作用不确定时没有自动盲重试。
- 阶段报告证明批次不跨项目、旧版本 item 不会发布、敏感信息未进入快照/日志，且 local/channel/manual 三类目标均有证据。

## 3. 工作包依赖与阶段闸门

```text
4A batch/item Schema、状态机和不变量
    ↓
4B 目标解析、版本冻结和幂等身份
    ↓
4C 运营提交与草稿编排
    ↓
4D 平台审批、退回与逐 item 决策
    ├── 4E local 目标执行与受控本站试点
    ├── 4F channel 目标执行与冻结快照分发
    └── 4G manual 目标工单与人工确认

4E + 4F + 4G
    └── 4H 恢复、readback/reconcile 与阶段闭环
```

4E、4F 和 4G 共享 4D 的批准 item 合同，但分别由本站、远程渠道和人工工单事实 owner 执行。4E 通过后才允许小规模本站内部试点；4F/4G/4H 未通过前，不得把真实远程渠道或人工工单作为批量生产路径。

### 允许进入阶段 5

- 4A 的 batch/item schema、状态不变量、项目归属、索引、回滚和两套数据库证据通过；
- 4B 的文章版本/hash、目标 canonical identity、目标快照和幂等合同冻结；
- 4C 的项目内提交、重复请求、过期版本和混合项目拒绝通过；
- 4D 的 platform approver 权限、批次/item 审批、退回、逐 item 异常和审计通过；
- 4E 的 local 执行、受控本站试点、重复/并发/失败分类通过；
- 4F 的 channel 快照分发、签名/能力校验、远端回执和 uncertain/readback 证据通过；
- 4G 的 manual 工单衔接、人工确认、评论类 owner 和幂等回调通过；
- 4H 的恢复、聚合状态、敏感信息扫描和全目标矩阵通过。

### 必须停留在阶段 4

- batch 或 item 可以跨项目，或请求体/缓存/前端选择框可以覆盖项目 owner；
- 旧版本文章、过期目标快照、未审核文章或未批准 item 仍能进入执行器；
- batch item 建立了第二套 manual/distribution 状态机，或总状态覆盖了 item 级真实结果；
- 重复提交、批准、claim、回调或恢复会产生重复本站状态、重复远端对象或重复人工工单；
- 远端不确定结果被当作普通失败盲目重试，或 secret/token 被写入快照和日志；
- 只完成本站 demo，却没有 channel/manual/readback/reconcile 的最小验证证据。

## 4. 阶段 4 交付物

阶段 4 完成时只允许产生以下结果：

1. 项目级 publication batch/item schema、状态机、不变量和审计合同；
2. 按文章 × 目标生成的版本/hash、目标快照和数据库幂等合同；
3. operator 提交、platform approver 审批/退回和逐 item 决策流程；
4. local、channel、manual 三类目标的独立执行与结果投影；
5. 失败、部分成功、不确定、readback/reconcile、恢复和人工介入证据；
6. 受控本站试点边界及进入阶段 5 的风险清单。

不得在阶段 4 产生客户登录/上传、外部资料接收层、客户回执入口、中央站多客户前台、Enterprise Knowledge/URL Import 隔离改造或无明确目标的全量远程发布。

## 5. 执行方式

本助手按 `4A → 4B → 4C → 4D → (4E/4F/4G) → 4H` 执行。每个工作包开始前先核对阶段 3 的 gate、现有 `article_distributions`/`manual_publications` 状态 owner、渠道能力和当前迁移状态；完成后先运行该包最小验证，再更新本文件和总计划。真实远程写操作必须另行确认具体目标、凭据、回读和回滚边界；在条件不齐时只完成本地、mock、contract 或 read-only 验证。

## 6. 实际执行记录

- 4A–4H 已按顺序完成，代码位于 `app/Models/PublicationBatch*.php`、`app/Services/GeoFlow/PublicationBatch*.php`、`app/Http/Controllers/Admin/PublicationBatchController.php` 及对应迁移/枚举。
- GeoFlow 应用容器 PHP 8.4 可用；publication batch migration 已执行，publication batch 后台路由共 9 条可发现。
- 关键容器验证通过：batch schema/status、文章工作流、分发 publisher、人工发布核心测试；Pint、PHP lint、`git diff --check` 通过。
- 真实外部副作用仍未执行：没有远程渠道写入、人工工单创建或本站试点。
- 当前运行数据为 2 个 active 项目，但文章数为 0、approved 文章数为 0、active channel membership 为 0、active persona/account 为 0；因此本站试点需要先准备一篇项目内已审核文章。
- `AdminManualPublicationsTest` 的 4 个 project-context 403/session failures 属于既有验证缺口，未由阶段 4 代码引入。
- 后续补齐了后台批次创建/详情操作页及本站执行入口；使用 `Live Project` 的文章 #3 完成一次单项目、单文章 local 试点：batch #1 从 `draft → submitted → approved → completed`，item 从 `pending → approved → local_published`，文章从 `draft → published`。
- 公开回读已确认：`/article/evnsdoya` 返回文章标题“GEOFlow 内部试点文章”，批次结果快照只含文章 ID、状态和发布时间；未执行 channel/manual 外部副作用。
- 内容管理页已增加“发布批次”按钮，指向当前项目上下文下的批次创建页；入口仍受后台认证和项目权限保护。
