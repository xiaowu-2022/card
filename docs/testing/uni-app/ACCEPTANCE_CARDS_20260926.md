# 第三批：卡片工程验收（2026-09-26）

本批检查编译后的 uni-app H5 卡片页、开卡确认、管理弹窗、实体卡激活与流水。
原 React H5 保留；本记录不代表全站迁移或原生发布验收完成。

## 修正

- 开卡初始金额改用精确字符串格式化。原实现对整数字符串 `20` 去掉末尾零后显示为 `2`；
  现在整数及带小数的金额均保留正确数值。浏览器用例先复现失败，再验证修复。
- 自定义页头禁用 uni-app 内置的外部阴影图片，避免偶发访问 DCloud CDN；离线截图仍拦截全部外部请求。
- 开卡按钮的加号显式使用白色，流水空状态的标题/图标颜色与原版对齐。
- 校准旧后台测试：持卡人材料补齐当前必填手机/区号；路由约束保留已存在且单独测试的
  Platform 密码验证查看卡号入口。未降低业务校验或修改财务规则。

## 验证范围

后台最终结果：**195 项通过，2,969 条断言**；新增浏览器 **9 项**、既有流程 **8 项**通过。

- 后台回归涵盖开卡、持卡人、充值、退回、冻结/解冻、注销、敏感卡资料、平台流水、
  实体卡收件人及激活、确定失败/UNKNOWN、重复请求、作用域、余额/溢出资金和 Ledger 约束。
- 充值确认新增 H5 API 与原生 Bearer + Consumer Flow 两种接入测试，均覆盖成功和 UNKNOWN；
  重复确认不重复调用卡商、不重复扣款，敏感操作仍要求密码/确认。
- 9 项新增浏览器用例：卡号 30 秒清除、隐藏页面后丢弃迟到卡号响应、充值最低额及 UNKNOWN
  只查询、退回密码与确认、激活 UNKNOWN 清除 PIN 且只查询、流水 GET 重试分页去重、
  退款锁定、开卡金额/确认/失败重试保持请求、开卡 UNKNOWN 禁止再提交。
- 既有 8 项浏览器流程回归通过。
- 卡片 7 个页面状态 × 4 种语言 × 375/768/1440px，共 84 组新旧对照。
  最终检查无运行错误、无横向溢出；该结论不等于视觉一致。状态包括未认证、已认证无卡、持卡、冻结、保证金退款锁定、激活 UNKNOWN、开卡 UNKNOWN。
- H5、App 资源构建及 Vue/TypeScript 检查；App 资源并非已签名 APK/IPA。

后台测试只使用隔离 `card_ui_test` 与 mock/fake。浏览器拦截 API 返回合成 DTO；
没有真实开卡、充值、激活、身份提交或历史财务修改。
浏览器与真实 Laravel API 分开验证，不能视为真实后端浏览器全链路测试。

## 证据与复跑

```sh
docker compose exec -T app php artisan test --compact \
  tests/Feature/CardIssueTest.php tests/Feature/CardholderGeographyTest.php \
  tests/Feature/ConsumerClientTest.php tests/Unit/PhotonPayPhysicalCardsTest.php \
  tests/Unit/PhotonPayManagementTest.php tests/Unit/PhotonPayTransactionsTest.php
node tests/Browser/consumer-uni-cards.mjs
node tests/Browser/consumer-uni-flows.mjs
npm run client:typecheck
npm run client -- build --company local --platform h5
npm run client -- build --company local --platform app
UNI_PARITY_PATHS='/cards,/cards?fixture=verified,/cards?fixture=cards,/cards?fixture=unknown,/cards?fixture=frozen,/cards?fixture=refund-locked,/cards?fixture=activation-unknown' node scripts/client/parity.mjs
```

定向截图运行会覆盖 `artifacts/uni-parity/results.json`；先备份，再按页面/语言/宽度合并其他批次，
用 `node scripts/client/parity-gallery.mjs` 重建图库。
交互截图：`artifacts/uni-parity/cards-acceptance/`。

## 尚未覆盖 / 待确认

- 页面截图无错误、无横向溢出不等于逐像素一致。原版持卡卡面靠右更宽；新版保留两侧边距，
  激活按钮间距、徽标位置及字体仍有差异。本轮已修正 768px 介绍区比例、空状态和 UNKNOWN
  查询按钮布局，仍不把抽查等同于全站逐像素一致。
- 四语言三尺寸为页面状态对照；本批交互弹窗主要在英文 375px 检查，完整弹窗视觉矩阵尚待补齐。
- 持卡人上传、实体卡地址填写的浏览器流程见下方收尾复验；其后端校验沿用上一批回归。
- 冻结/注销有后端测试，但原、新消费端当前快捷菜单都未提供入口；不能宣称已完成此两项 UI 验收。
- Android/iOS 真机、系统上传/下载、键盘/安全区、后台切换、签名安装和完整发布检查仍未完成。

## H5 收尾复验（同日，App 打包按用户要求暂缓）

本轮仅运行 H5 构建、类型检查与离线浏览器检查，未运行 App 资源构建、云打包或真机测试。
本文件前述 App 构建结果是上一批的记录，不能当作本轮原生验证。

修正卡片介绍区的标题、卡面上下留白及按钮响应式尺寸；去掉多余的 flex 间距，
恢复无卡空状态和 UNKNOWN 查询按钮的桌面/手机布局。开卡弹窗恢复原版 672px 最大宽度，
持卡人字段恢复 44px 高度，避免继承整页缩放后挤压手机号；去掉手机号内重复标签，并补齐文件选择/更换图片的三种非英语翻译。公共页头按原版恢复品牌、消息、
语言和客服的分布及响应式图标大小，连同资产/我的页面一并对照。

上传专项复现并修复了 H5 阻塞：当前 uni-app 的 H5 `getImageInfo` 只返回尺寸，不提供 `type`，
原校验因此将正常图片认作不支持。现在仅读取文件选择器产生的本地 blob，确认图片可解码，
根据 PNG/JPEG 文件头校验格式与大小；不根据文件名或客户端 MIME 盲信格式。
服务器上传和身份验证规则保持不变，原生分支保留原接口。

收尾结果：卡片交互 **15 项通过**、弹窗布局 **36 项通过**、消息/客服回归 **12 项通过**；
页面 **108 组**对照无运行错误或横向溢出。H5 构建、类型检查通过。
本轮没有后台领域修改，沿用上一批 195 项后台回归，不把旧结果重复计为新测试。

新增六项浏览器场景（本文件的卡片交互总数由 9 增至 15）：

1. 从空白持卡人表单填写开始，实际操作生日选择器、国籍/国家/省市搜索，上传 PNG/JPEG，
   保存后进入开卡确认；确认之前没有开卡请求。
2. 实际上传 WEBP 内容、PNG 文件名，前端拒绝且不提交材料。
3. 已存材料修改后重新选择两张图片；网络失败锁定材料，同一请求重试成功，清除图片引用。
4. 实体收件人 UNKNOWN 后仅查询，READY 后携带该收件人引用确认开卡。
5. 收件人确定失败后解锁修改，明确修改后的新提交使用新请求标识。
6. 收件人网络失败保留字段和请求标识，重试不创建另一个请求。

追加材料、收件人、充值三种弹窗 × 四语言 × 三尺寸共 36 张布局截图。
这些是新版弹窗布局与溢出检查，不是旧、新弹窗逐像素对照。
页面对照包含卡片七状态及资产/我的，共 108 组；独立复跑消息/客服四语言三尺寸浏览器回归。
所有请求均使用本地拦截响应，没有真实上传、开卡、寄卡或卡商操作。

```sh
npm run client:typecheck
npm run client -- build --company local --platform h5
CARD_LAYOUTS=1 node tests/Browser/consumer-uni-cards.mjs
node tests/Browser/consumer-uni.mjs
UNI_PARITY_PATHS='/cards,/cards?fixture=verified,/cards?fixture=cards,/cards?fixture=unknown,/cards?fixture=frozen,/cards?fixture=refund-locked,/cards?fixture=activation-unknown,/dashboard,/account' node scripts/client/parity.mjs
```

表单及上传/收件人浏览器范围已补齐上述场景，但服务端真实联调、全部失败类型、
完整原版弹窗视觉对照和人工确认仍不能由这些测试替代。
