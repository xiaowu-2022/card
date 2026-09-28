# 2026-09-27 uni-app H5 综合验收

后续结果见 [二轮验收与 PhotonPay 沙箱](./ACCEPTANCE_H5_20260927_R2.md)。本文保留首轮结果，旧测试失败已在二轮处理。

## 结论

本轮前端构建、类型检查及离线交互回归通过；**不能标记整体发布验收通过**。
扩大后端测试集仍有 47 个旧用例失败，工具链依赖审计仍有 high 级报告。
现行 OCR 集成测试单独通过。原 H5 未切换，未部署 OSS/CDN，App 打包和真机按用户要求暂缓。

## 环境和边界

- Node 22.23.1；生成 H5 在 `127.0.0.1:5202`，原 React 对照在本机 Laravel。
- Laravel 测试仅 `card_ui_test`，不使用生产数据库；图片、OCR、充值、提现、转账、开卡、推广及理财操作均为合成数据或假上游。
- 浏览器测试拦截 API，不向真实服务发送业务请求。后端测试独立验证领域规则；这不等于浏览器与真实 Laravel/卡商的完整端到端验收。
- 四语言为 zh-CN/en/ms/es，宽度 375/768/1440。桌面 Chromium 视口模拟不等于手机 Safari/Android 真机。

## 本轮结果

| 检查 | 结果 |
| --- | --- |
| React 构建、uni-app H5 构建 | 通过；React 仍有大 chunk 提示 |
| React TypeScript、uni-app vue-tsc | 通过 |
| 公司构建配置测试 | 3/3 通过 |
| i18n/前端规则测试 | 77/77 通过 |
| 登录注册/找回密码浏览器用例 | 9/9 通过 |
| 资产浏览器用例 | 10/10 通过 |
| 卡片交互与弹窗尺寸用例 | 15 + 36，通过 |
| 综合流程浏览器用例 | 8/8 通过 |
| 消息/客服四语言三尺寸 | 12/12 通过 |
| 本轮新增综合验收 | 31/31 通过 |
| 扩大后端集 | 557 通过、47 失败；8593 次断言 |
| 现行 AliyunKycIntegrationTest + 隔离截图 fixture | 45 通过，260 次断言 |
| 生成版/原版页面对照 | 74 场景 × 4 语言 × 3 尺寸 = 888 组，运行错误 0、横向溢出 0 |
| uni-app `npm audit --omit=dev` | 24 条依赖报告：9 high、4 moderate、11 low、0 critical |

浏览器共 121 次场景执行（含语言/宽度变体），不称作 121 个独立业务功能。
后端 45 项中含 1 项截图数据导出，不与先前轮次的测试数累计宣传。
依赖报告包含 DCloud 依赖链中的构建工具和运行库；没有将所有报告直接认定为可利用的线上漏洞，也没有用强制降级/换 Vue 2 清除审计。

## 新增覆盖

- 保证金缴纳失败重试保留 request_id；退款申请/撤销经过确认，失败后清除密码。
- 理财提前赎回、到期赎回提交不同动作，提前赎回绑定 expected_paid；重试保留同一意图。
- 代理付款展示保证金抵扣和钱包金额；仅确认既有 quote，不由客户端重新提交经济参数；过期 quote 禁止付款。
- 客服 PNG 上传/失败重试使用相同 request_id；JPEG、WebP 可预览；GIF 和超过 5MB 的文件被拒绝。
- 资产/卡片/我的品牌头部无铃铛；消息页无铃铛及“全部已读”；资产无“最近申请”。
- 邀请表头中文两行、等级展开；每日数据筛选弹窗无 Filter/Reset/Apply 英文残留。
- 奖励说明滚动后头部保持固定，目录跳转内容避开头部。
- 邀请海报四语言、三尺寸打开/关闭、PNG 下载及二维码图案检测；375px 使用测试背景，其他尺寸测试默认背景。弹窗保持在视口内，不被底部导航覆盖。
- 现有回归继续覆盖消息已读水位、99+、合计未读、纯文本注入、UNKNOWN、敏感信息清除、金额精度和失败重试。

## 本轮修复

1. `UserPageHeader.tsx` 路径分割空值导致的 TS2532，补充空串保护。
2. 客服 H5 图片校验：uni.getImageInfo 在 Web 不返回 type，原实现误拒绝合法图片。现在解码后检查本地 blob 的 PNG/JPEG/WebP 文件头及大小；保留像素限制和服务端校验。用户取消不报错，损坏文件显示可操作提示。
3. 后台补齐连接/BIN 检查与 PhotonPay 账户保存的中文提示。
4. 前端旧断言按已批准规则校正：卡片菜单、必填联系手机、品牌常量、CardProductPreview 组件抽取及动态推广等级数据；没有放宽权限或金融规则。
5. 对照脚本增加显式恢复模式。重建原 React 后旧哈希资源造成一批截图 404，已作为测试运行问题重跑；失败样本不计为通过。

## 未通过项

扩大测试的 47 个失败均需保留可见，不能只挑绿灯用例作为发布结论：

| 旧文件 | 失败数 | 已观察的阻塞 |
| --- | ---: | --- |
| PromotionTest | 6 | 准备账户时仍提交 MY 的 NATIONAL_ID |
| SecurityDepositFundingTest | 11 | 同上，尚未进入资金断言 |
| DepositActivationEntryTest | 15 | 同上，尚未进入流程断言 |
| KycSubmissionTest | 9 | 旧证件/异步 OCR 契约及上传错误预期 |
| KycOcrTest | 6 | 旧异步 OCR job 契约，准备申请即失败 |

当前允许大陆身份证 CN 双面或护照，并在提交时验证 OCR 号码匹配。
本轮没有为了让旧测试通过而恢复 MY 身份证、跳过 OCR 或改动资金业务。
需另行把这些旧测试迁到现行输入/识别证据，并完整重跑，才可关闭这一回归门禁；当前 44 项 Aliyun 集成测试通过不能替代这些资金流程的后续断言。

工具链审计也尚未关闭，需要受支持版本升级及兼容性回归后再判断发布。

## 尚未测试

- 手机 Safari/Android 浏览器真实键盘、安全区、相册/相机、下载保存、前后台行为。
- APK/IPA 云打包、签名安装、原生权限和安全存储，按用户要求暂缓。
- OSS/CDN 的正式公司配置、同域 API 反向代理、Cookie/CSRF、缓存及回滚演练。
- 真实充值、提现、转账、身份提交、卡商操作；没有历史资金重写或真实群发。
- 没有宣称所有 888 组截图逐像素一致；布局自动检查加重点人工抽查，后续用户确认的设计变化不要求退回旧版。

## 证据与复跑

日志与依赖审计：`artifacts/acceptance-20260927/`。
页面对照：`artifacts/uni-parity/index.html`、`results.json`。
新增交互截图：`artifacts/uni-parity/acceptance/`，`*-open.png` 为海报打开状态。

```sh
npm run typecheck
npm run client:typecheck
npm run test:client
npm run test:i18n
npm run build
npm run client -- build --company local --platform h5
node tests/Browser/consumer-uni-auth.mjs
node tests/Browser/consumer-uni-assets.mjs
CARD_LAYOUTS=1 node tests/Browser/consumer-uni-cards.mjs
node tests/Browser/consumer-uni-flows.mjs
node tests/Browser/consumer-uni.mjs
node tests/Browser/consumer-uni-acceptance.mjs
node scripts/client/parity.mjs
node scripts/client/parity-gallery.mjs
```

先构建完成再跑截图，中途不要重建原 React。只有同一代码、同一批验收运行中断时可用 `UNI_PARITY_RESUME=1` 继续；代码变化后应从头或显式选择受影响页面复测。
