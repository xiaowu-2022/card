# 第一批验收：认证与语言

日期：2026-09-26。对象为当前 uni-app 编译生成的 H5，原 React H5 是对照基准。
本批完成工程验证与差异修正，可进入人工对照验收；不代表整站迁移或原生 App 已验收。

## 范围与证据

| 检查 | 结果 | 边界 |
| --- | --- | --- |
| 登录、注册、待验证、已验证、找回密码、重置密码截图 | 6 个场景 × 4 语言 × 375/768/1440px，共 72 组双端对照 | 检查运行错误及横向溢出；截图抽查，不是自动证明像素一致 |
| 浏览器认证交互 | 9 项通过 | 使用拦截的 API 响应，验证编译后页面行为 |
| Laravel 认证回归 | 86 项通过，593 个断言 | 隔离 card_ui_test、假邮件、禁止未声明的上游 HTTP |
| Vue/TypeScript、H5、App 资源编译 | 通过 | App 资源不是签名 APK/IPA，也没有真机验收 |

浏览器交互覆盖四语言登录错误、密码显示/离开清理、邀请码锁定、重复提交保护、
验证码错误后重试及前导零、验证码过期、重置密码明确确认、网络错误重试请求标识、
公司主题色、切换语言保留表单以及语言选择器自身的完成/取消翻译。

新后端用例通过真实 Laravel 请求覆盖网页注册及 Cookie 登录恢复、原生找回密码流程隔离、
同请求不重复发验证码、确认校验、重置后旧 Token 失效、新密码登录、语言与公司参数校验，
以及认证接口之间的限流隔离。原有认证、注册、找回密码、Consumer API 测试同时回归。

## 本批发现并修复

1. 普通初始化/语言请求与密码恢复使用了相同数字限流桶，可能提前触发 429。
   为相关 API 添加独立前缀，保持原有次数和时间窗口；恢复仍为每分钟 5 次。
2. 原生恢复密码的成功提示写入了默认会话，未返回当前独立流程。
   重定向器使用当前流程会话，并在请求结束恢复原实例；测试确认默认会话不泄漏提示。
3. 认证页绑定公司主题色；找回密码输入框、按钮尺寸和禁用颜色按旧版修正。
4. uni-app 语言选择器的取消/完成原先未跟随页面语言，现提供中、英、马来、西班牙语。
5. 验证码/邀请码按数字字符串处理，保留前导零；离开重置页时清空密码、验证码和确认状态。

## 查看与复跑

启动 `npm run client:preview` 后，在 `http://127.0.0.1:5202/__parity/index.html` 查看左右对照。
原版在左，生成版在右；选择本批路径、语言和宽度。
第一批结束时完整图库保留 792 组记录，其中本批 72 组已刷新。其余页面不因此自动通过验收。

```sh
docker compose exec -T app php artisan test --compact \
  tests/Feature/ConsumerAuthAcceptanceTest.php tests/Feature/ConsumerApiTest.php \
  tests/Feature/ConsumerClientTest.php tests/Feature/UserAuthenticationTest.php \
  tests/Feature/UserRegistrationTest.php tests/Feature/UserPasswordRecoveryTest.php
node tests/Browser/consumer-uni-auth.mjs
npm run client:typecheck
npm run client -- build --company local --platform h5
npm run client -- build --company local --platform app
```

浏览器交互证据位于 `artifacts/uni-parity/auth-acceptance/`。
截图脚本的 `UNI_PARITY_PATHS` 可限制到本批路径；局部运行会覆盖 results.json，
需先备份，再按 path/language/width 合并，以免丢失其他批次索引。

## 尚未宣称通过的部分

- 浏览器交互与真实 Laravel API 的完整串联、真实邮件投递不在本轮离线验证范围。
- 最终视觉由用户在对照图库确认；字体渲染和少量间距仍可能存在差异。
- Android/iOS 签名安装、软键盘、安全区、系统返回和前后台行为需后续真机验收。
- 未切换旧 H5、未部署、未发送真实邮件或通知，也未进行金融操作。

下一批按资产及充值、转账、提现、兑换逐项验收，继续使用隔离数据和假上游。
