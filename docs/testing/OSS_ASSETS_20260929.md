# OSS 资源验收（2026-09-29）

当前项目及其已配置 OSS 已完成发布；不代表其他远程应用服务器已部署。

- 完整发布 927 条公开资源映射，包括页面图片、图标、地区 JSON、React/H5 编译脚本及样式。SHA-256 校验后原子切换，未删除原资源或旧对象。
- 当前业务图片库存 1814 条，全部已有 OSS 映射；迁移预览待迁移和失败均为 0。
- H5 `index.oss.html` 已替换当前工作区 `public/h5/index.html`；原入口备份在 `output/oss-verification/h5-index-before.html`。HTTP 读取已确认新入口。
- Playwright 使用本机 Chrome 验收 H5 首页、登录页及 React 首页：65 个远程响应，35 个脚本响应、18 个图片响应；零失败响应、零本地静态资源请求、零页面脚本错误。测试仅 GET，未登录或发送业务请求。
- OSS 图片实测：2400×1600 PNG 经读取参数返回 1600×1067 WebP，15434 字节降至 8674 字节；原始 SHA-256 不变。仅使用临时合成图，验证后已删除。
- OSS 公开 GET 的 CORS 与 immutable 缓存已验证；未修改 Bucket CORS 或开通独立 CDN。
- PHP OSS 测试 27 项通过；KYC 图片访问、客服及海报相关测试通过。前端类型检查、17 项前端测试、React/H5/原生资源编译通过。

复验工具：`tests/Browser/prepare-oss-tags.php` 生成独立生产标签，`tests/Browser/oss-assets.mjs` 在拒绝本地静态资源的只读代理下验收。保留现有 `public/hot`，开发热更新不受影响。

原生 App 的运行时图片使用新清单，启动资源和执行代码仍需随安装包保留；已编译调试资源，尚未制作或安装新的签名 APK/IPA。没有执行真实 OCR、客服消息、发卡或资金操作。
