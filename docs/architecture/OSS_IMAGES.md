Approved consumer KYC details now return current OSS display URLs directly in OSS
mode, including for images with local replicas. URL generation does not read OSS.
User/company scoping and masked identity data are retained; pending/unmapped images
have no display URL. Server mode continues to use the signed application gateway.

## 2026-09-30 KYC direct URL flow restored

New KYC tickets in OSS mode use `kyc_url`. The consumer uploads the original with
its scoped POST policy and submits the ticket plus exact imageUrl only after OSS
returns success. It skips backup and complete; failed client uploads stop submission.
The backend checks company/user/side, expiry, exact URL and single-use binding, then
calls OCR with the original OSS URL. No HEAD/GET/COPY/ACL request or local backup is
required in this KYC path. Server mode, card/support dual-copy flows remain unchanged.
These URL-only objects have no server-verified checksum/size or guaranteed replica;
no checksum is invented and no original is rewritten. OCR failure never approves KYC.

## 2026-09-30 OCR follows the storage selector

In OSS mode, OCR uses the current configuration's public original object URL,
without display processing or the application image gateway, even when a local
replica exists. URL generation makes no OSS request. Missing mappings, non-ready
objects and pending OSS uploads fail closed; they never silently select a local
OCR URL. Server mode retains its signed original gateway. Switching domains does
not copy objects; originals must exist at the configured destination. This supersedes
the OCR gateway fallback described below. No OCR submission is replayed.

On 2026-09-30 the user required image reads and public asset URL prefixes to follow
the current storage selection/configuration, including existing images. Store object
keys, not replaceable domain prefixes in business rows. Signed image gateways resolve
the current OSS config or local server at read time; current OSS failures retain the
verified server-original fallback. Previous configuration mappings remain provenance
for migration/cleanup only, not the read endpoint. A different bucket must contain
the same original objects/asset keys; changing a prefix does not copy files. Reads
never upload or rewrite business records. Changed-config processed reads verify the
original checksum before displaying a processed image.

On 2026-09-30 the user requested a Platform storage selector: server or OSS.
The storage.manage-protected selector persists media_storage_settings.storage_driver,
with locked revision checks and actor audit. A null value retains the deployment's
IMAGE_STORAGE_DRIVER until the first explicit selection; subsequent selections
override it immediately. OSS requires a saved valid configuration, not a connection
test. Mode switching does not migrate/delete originals or rewrite image mappings.
Deploy the storage-driver migration and rebuilt admin assets before using the selector.

## 2026-09-30 single editable configuration

The administration page exposes one current OSS form, direct Test and Save, and no version list or activation step. Test validates an unsaved draft using a temporary object without changing persisted settings. Save atomically selects its immutable configuration; identical saves reuse the existing record. Old internal snapshots stay available for historical image mappings. Blank secrets reuse current encrypted credentials; no secret is returned to the browser. expected_id rejects stale form saves. Explicit draft tests also work in server mode without changing the runtime storage driver.

# Dual image copies — approved 2026-09-29

New runtime business images retain an encrypted original under private image-replicas
and an OSS final object. Consumers upload the same selected bytes to the authenticated
backup endpoint and privately to OSS staging. Completion requires a validated local
copy, verifies final OSS bytes against its SHA-256 and only then publishes that object.
If OSS fails or differs, the local original is authoritative, oss_pending stays true,
and no unverified remote object is served. Existing short-lived tickets may finish
under their already-issued mode; new authorizations always require dual copies.

Signed, host-bound /media/images/{id} delivery URLs expire in 12 hours and accept
only bounded named display profiles. URLs reveal no storage paths. Ready business
images are public by the prior explicit approval; signatures prevent enumeration or
arbitrary file selection. Original reads prefer OSS with 3-second connect / 8-second
request timeouts and SHA-256 checking; on failure they decrypt/check the server copy.
Pending OSS writes read locally immediately. Displays may request OSS resize/WebP;
local fallback returns the unchanged original. OCR gets the original-profile signed
URL, so image fetching falls back within the gateway without another OCR submission.
No credential, OCR body or provider operation is replayed by image repair.

images:replicate repairs pending OSS copies and verifies their hashes before marking
success. --backfill copies existing ready originals without mutating business history;
it retains missing/unreachable originals as pending rather than inventing files.
New backups use atomic encrypted-file replacement and retained per-image metadata.
Public built-in artwork remains in H5 static / native packaged resources alongside
OSS; requested remote images switch reactively to those local copies on load failure,
including CSS image backgrounds. React administration uses the same failure principle.
Old OSS objects, configurations, packaged resources and published releases are retained.

POST policies cannot establish content identity by ETag alone; staging remains
untrusted until compared to the validated server copy. See the official
[PostObject contract](https://www.alibabacloud.com/help/en/oss/developer-reference/postobject).

# 全局 OSS 图片存储（2026-09-27）

用户批准 SaaS 全局 OSS、上传图片公开读取（包括实名认证/开卡证件、客服图片），以及 OCR URL 输入。本规则仅改变图片存储和访问策略，替代这些图片过去的私有存储要求；业务字段、证件号码、客服文字、测试档案的加密规则不变。

## 配置与读取

Platform `/platform/settings/oss` 需要 `storage.manage`。增量迁移和种子均授予 PLATFORM_OWNER / PLATFORM_ADMIN；公司管理员不能配置。更新保留现有会话、CSRF、频率限制及操作审计，不重复验证密码。AccessKey ID/Secret 只写入加密配置，不回显，不写审计、日志或验证错误闪存。

每次保存生成新配置版本；测试执行合成 PNG 上传、鉴权下载校验、公开域名下载校验和删除。测试为可选诊断，保存后可直接启用；启用仍保留 Platform 权限、格式与地域检查。启用只切换后续上传，既有图片绑定原配置、Bucket、域名；旧配置不能删除或修改目标。SDK 使用兼容当前 Guzzle 8 依赖的官方 `aliyuncs/oss-sdk-php`，不降级现有 HTTP 栈。

2026-09-29 起，所有运行环境的新业务图片上传必须启用 OSS；未配置时失败关闭。只有隔离自动测试可创建本地迁移夹具。真实 Aliyun OCR 必须启用 OSS，不能用本地私有文件地址冒充可识别图片。启用后的 OSS 失败直接报错，没有自动本地降级。SDK 支持 HTTPS 阿里云区域端点；需要 CNAME 时 Endpoint 必须与明确配置的图片域名一致，采用 OSS V4 签名。新对象为 `images/{tenant_uuid}/{random_uuid}`，无姓名、邮箱、证件号。对象 ACL 为 public-read，写入要求服务端 RAM 凭证；设置 SSE-OSS AES256 与 `Cache-Control: no-store`。

`stored_images` 保存业务引用、公司、原磁盘/对象键、实际存储位置、配置版本、MIME、字节数和 SHA256。原业务字段作为稳定逻辑引用，不改写不可变 KYC 或开卡记录。无映射的历史图片按原磁盘/原加密算法读取。品牌 URL 统一解析；邀请海报、客服及后台 KYC 查看入口保留原接口和权限，但公开 OSS 对象 URL 本身不需要登录。

## 上传与 OCR

接入 Logo/favicon、邀请海报、客服图片、KYC 正反面/护照、开卡图片。2026-09-29 起项目内置静态资源也迁入 OSS（见下文）；日志、密钥、`card-test-materials` 加密测试档案不发布。

KYC 先校验现有资格、文件类型/尺寸，再写入存储；通过存储记录生成 URL，使用阿里云 `Url` 查询参数及空请求体调用 OCR。ACS3 签名包含 URL 参数。不会接受客户端任意图片 URL，不发送图片二进制或 Base64。大陆身份证分别识别正反面，护照一页；原号码匹配、校验位、失败分类及审批规则保留。队列 OCR 读取对象 URL，不读取图片字节。PhotonPay 的文件上传协议不变。

写入前创建上传记录，成功后 ready。OCR 或业务提交失败仅清理已证明未被业务引用的对象；删除失败记录 cleanup_pending、错误枚举及下次重试时间。`images:recover` 每分钟恢复删除，也检查超过一天未绑定业务的上传对象；先查询实际业务引用，不能删除已被业务持有的图片。恢复不执行 OCR、卡商、支付或 Ledger 操作。

## 历史迁移

`images:migrate-oss` 默认预览、只读。显式 `--execute` 后按业务表引用枚举，默认每批 100，`--tenant=<uuid>` 限定公司，`--limit=1..1000` 控制批量。包含历史 KYC、卡片资料、客服以及当前品牌和邀请海报，不递归搬运私有目录中的未知文件。

原客服/开卡图片使用原算法解密后上传可读取图片；证件号、文字、档案密文不改变。为每个图片持久化目标配置和随机目标键，失败/中断续跑使用同一目标；上传后下载校验 SHA256，再检查源文件未变，最后事务切换映射。原文件（含原密文）保留。本地缺文件或校验失败保留原读取位置并报告失败引用，失败项默认跳过，使用 `--retry-failed` 重试。重复执行跳过已迁移项，业务历史与财务数据不变化。

## 验证边界

离线测试替换 OSS 传输、阻止真实网络，验证权限/凭证脱敏、配置测试与启用、原版本读取、URL-only OCR、故障清理恢复、迁移内容校验及幂等。真实 OSS 凭证不写入测试文件；运行环境尚需用户配置并用后台合成图片连接测试完成联调。未提交真实证件或重放卡商/财务动作。


## 全量公开资源与展示缩图（2026-09-29）

业务原始图片仍通过原 `stored_images` 映射，`read`/`url`/`ocrUrl` 永远读取原图，
卡商上传、OCR、迁移 SHA256 不使用缩图。展示 URL 使用 `x-oss-process`：
品牌 512×512、普通预览 1600×1600、证件/海报预览 2048×2048；`m_lfit,limit_1`
保持比例、只缩小，不裁切放大；输出 WebP，普通质量 80、证件/海报 85。
原文件不覆盖、不重编码。大小是最大像素框与质量约束，不保证固定 KB 上限。
ICO/SVG/字体/JS/CSS/JSON 不添加 IMG 参数。上传既有业务文件大小限制保持不变。
参考 [OSS IMG 官方参数](https://www.alibabacloud.com/help/en/oss/user-guide/overview-17)。

品牌图直接使用后端生成的 OSS 展示 URL；uni-app 允许这些 HTTPS 公开品牌地址，
不向图片主机发送 API Token。客服、证件查看与海报画布入口保留权限/审计检查及
同源响应，由 OSS 处理后转回展示内容，避免跨域凭据跳转及画布污染；不回退下载
大原图。因保留同源媒体入口，这些受控读取会经过应用服务器，静态资源直接走 OSS。

`media_storage_settings.public_assets` 保存全局公开静态资源的配置版本、对象键、
MIME、SHA256，与公司上传图片分离。白名单源是 `public/images`、`public/favicon.ico`、
`public/data/card-geography`、生成的 App SVG 图标，以及显式启用的 `public/build/assets`
和 `dist/clients/<company>/<mode>/h5` 的 assets/static。不遍历项目根目录、storage、
环境文件、源代码、source maps 或档案。当前项目无独立字体文件；字体扩展受白名单支持。

`assets:publish-oss` 默认预览，`--execute` 逐个上传并下载验证 SHA256，保留本地
原文件。CLI 最多六个独立上传进程，不传递凭据到命令行；失败可重跑。校验通过的
编译资源持久保存为 `@staging/` 映射，读取端完全忽略；整个依赖图完成才原子发布。
旧公开版本继续可读。Vite 使用相对依赖 URL；带内容哈希的脚本位于稳定同级目录，
防止动态 import/CSS 相对引用丢失，拒绝同名编译资源内容被覆盖。
普通文件使用 SHA256 对象键。只对 `assets/` 使用一年 immutable 缓存；业务证件
仍 no-store。配置切换不会改变未重新发布文件的 Bucket/域名。

Laravel Vite 通过清单解析 JS/CSS；React 页面使用共享清单，uni-app bootstrap 同步
清单。H5 `index.oss.html` 指向远程脚本/样式并预置公开图片清单；发布时替换入口。
原生可执行代码及首次启动资源按平台要求打包，页面获取配置后从 OSS 读取插画/图标。
内联 SVG 绘制（包括动态图标颜色）不是远程文件，不引入请求。HTML/API 留在业务域名。

必须先应用存储清单迁移、构建，再上传资源、验证跨域读取、最后发布 H5 入口。
上传期间不得重建正在上传的目录；源校验会拒绝变化。原始文件和旧 OSS 版本不清理。
OSS public-read 不是 CDN 配置的证明；可在既有公开域名前配置 CDN，现有域名需透传
IMG 查询参数和正确 Content-Type/CORS。模块脚本、字体与 JSON 需要匿名跨域 GET。

## 2026-09-29 手机直传

uni-app 的 KYC、开卡材料和客服图片使用鉴权后的直传授权 API；H5 同源 API 和原生 Host 所属公司均由服务器解析。永久 Secret 只在服务器，返回的五分钟 OSS V4 POST policy 绑定唯一 staging key、Bucket、MIME、最大字节数、private ACL、AES256 和禁止覆盖字段；不附带业务 API Token、业务字段或身份号码。授权限流且每用户最多十个未领取有效票据。

新增 direct_image_uploads 保存 tenant/user、用途/字段、暂存及最终 stored_images 映射、大小上限、15 分钟期限、验证及领取时间。客户端只能上传到私有 staging 对象，不能写最终对象。完成接口按票据串行，以 ETag 条件复制到服务器专属随机最终 key，限量读取原始字节校验图片类型/尺寸/大小及 SHA256 后开放公开读；重复完成不再复制。原图不压缩、不落本地磁盘。业务提交仅携带票据 ID，校验归属、用途/字段和校验和后一次领取映射，原 KYC OCR、开卡和客服鉴权/幂等规则继续生效。客服已提交请求可复用相同票据返回原结果，新请求不能重复领取。

服务器签名不访问 OSS，因此配置无需联网测试即可启用，但完成、原图校验、OCR/卡商流程及清理仍要求服务器连通 OSS。此变更不能绕过服务器 TLS 故障。未完成或失败对象由既有 images:recover 回收，staging 至少保留到签名过期；旧图始终绑定原配置。原 React H5/管理端上传入口保持既有上传流程。

## 2026-09-29 KYC 地址直传修订（覆盖上文 KYC 完成校验要求）

用户明确要求前端上传 OSS，取得地址交给后台，再由 OCR 读取该地址。uni-app H5/App
的 KYC（身份证正反面及护照）授权改为 public-read 的随机最终对象，五分钟 V4 policy
继续限制精确 key、Bucket、类型声明及文件大小，Secret 永不下发。前端收到 OSS 成功响应
后直接提交 front/back_upload_id 及 front/back_url，不再调用 /complete。

后台以 Host 公司和当前用户查询票据，检查 kyc_url 模式、正反面字段、期限、单次领取，
并将提交地址与该票据原配置生成的 URL 完全比较。只接受精确地址，拒绝任意外链、
其他用户地址、变更路径或查询参数；随后直接把该 URL 送给现有 Aliyun OCR。
提交链路不再对 OSS 执行 HEAD/GET/COPY/ACL/DELETE，不会探测 OSS 连接。
号码识别和匹配、身份证背面识别、账户/身份唯一性及审核规则不变。识别失败不批准。

direct_image_uploads 新增 upload_mode，默认 verified_copy；新 KYC 为 kyc_url，
image_id 与 staging_image_id 指向同一对象。stored_images.size/sha256 允许空值，表示
没有服务端测量；MIME 为签名时的类型声明，verified_at 保持空。ready 仅表示已绑定
可供 OCR 使用的地址，不代表服务端校验过原图或校验和。单次 claim 保留原配置映射。
禁止把此模式交给旧完成/读取字节接口；卡材料与客服仍用原 verified_copy 流程。

OSS 的 forbid-overwrite 仍随签名固定，但启用/暂停版本控制的 Bucket 可能接受后续版本，
因此本模式不宣称服务器专属不可覆盖副本。原图不重写、不压缩；失败或放弃的图片延后
由 images:recover 清理，等待授权过期，不阻塞提交响应。后台仍需能访问 OCR API，
OCR 服务需能读取公开图片域名；后续管理员图片代理和异步清理仍可能需要服务器访问 OSS。
