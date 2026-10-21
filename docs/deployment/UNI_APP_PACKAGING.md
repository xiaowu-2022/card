## 2026-10-20 最终全链路诊断 IPA：请使用 final 文件

交付 `mobile/webview-shell/dist/release/ipa/SpecPay-2.6.22-2622-final.ipa`，
2.6.22（2622），SHA256：
`48fdd616e3cf15e9a4856c54cf34b74d11aab69dcc42c1534a6472f4b4c59e28`。
最终源码重新编译到 dist/final-2622，并同步 dist/build/app-plus；全部资源与包内逐字节
匹配。沿用 2.6.21 已签名包中相同原生运行时，以现有证书/描述文件在临时隔离钥匙串
中重新签名；去除签名后的原生可执行文件与原包完全一致，描述文件完全一致。
临时签名环境补齐 Apple 官方 G3 中间证书和 Root CA，仅导入临时钥匙串，未修改
系统信任设置；签名结束删除临时钥匙串并恢复原有搜索列表。

56 项故障注入/诊断/Token 测试、vue-tsc、版本/Bundle ID/AppID、ZIP 完整性、
全部编译资源核验以及最终 IPA 解包后的 codesign --verify --deep --strict 通过。
包内存在最终 META-LOGOUT/PENDING/COMMIT、PATH、READBACK 等诊断且无 SAVE-INVALID。
相邻 verification.json 保存核验结果。未上传分发；真机登录、杀进程后恢复尚未实测。

注意：此前 16:59:16 提交的云任务不含最后补齐的生命周期诊断，已请求取消，但云端以
“已扣费不能取消”拒绝。该排队任务即使随后完成也不是交付版本，请勿覆盖 final IPA。
本次最终包使用本地重新签名，没有再次提交云打包或购买加速服务。

## 2026-10-20 iOS 全链路存储诊断与读回核验（2.6.22 / 2622）

用户要求补齐诊断后重新生成签名 IPA。本次保持现有保护级别、显式 Foundation JSON
序列化、公司/Token 校验和保存后显示屏障；不以跳过安全存储来放行登录。

修正：读回缺失/读回不一致不再误报写入失败；原生 BOOL 只接受 true/1，存在性
查询的空值不再当作“文件不存在”；本地登录状态标记也必须保存后读回一致。
元数据 pending/commit/read/logout 故障有独立固定码，pending 前先关闭页面身份
就绪状态。启动恢复、前后台保存和手动重试均可显示安全诊断码，不输出原生异常、
文件路径、记录、账号或 Token。未知存储异常只保留固定 SAVE-UNEXPECTED 或
SAVE-VAULT-UNEXPECTED；这不意味着任何未验证故障被认定已修好。

| 阶段 | 固定诊断码（IOS- 前缀） |
| --- | --- |
| 路径 / 文件管理器 / 存在查询 | PATH-INVALID / MANAGER-EMPTY / EXISTS-INVALID |
| 创建 / 编码 / 写入 | INPUT-TOKEN / CREATE-EMPTY / ENCODE-EMPTY / WRITE-FAILED |
| 备份标记准备 / 设置 / 清理 | URL-EMPTY / BOOL-EMPTY / BACKUP-FAILED / BACKUP-CLEANUP |
| 属性 / 保护 | ATTRIBUTES-EMPTY / PROTECTION-MISSING / PROTECTION-MISMATCH |
| 读取 / JSON | READ-EMPTY / READ-TYPE / JSON-EMPTY / JSON-PARSE |
| 记录 / 公司 / Token | RECORD-SHAPE / SCOPE-MISMATCH / TOKEN-INVALID |
| 保存后核验 / 删除 | READBACK-MISSING / READBACK-MISMATCH / DELETE-FAILED |

所有原生调用抛错使用 IOS-NATIVE- 加固定操作：MANAGER、EXISTS、DELETE、URL、BOOL、
CREATE、ENCODE、WRITE、ATTRIBUTES、COMPARE、READ、BACKUP。
状态标记使用 SAVE-META-PENDING / COMMIT / READ / LOGOUT；中断交接恢复和状态指向
不存在凭证分别为 SAVE-RECOVERY-PENDING / SAVE-CREDENTIAL-MISSING。
READ-EMPTY 仅说明读取返回空，不能证明设备锁屏；PROTECTION-MISMATCH 也不能证明
后面的内容已成功读回。真机根因及保存/杀进程重开效果须由安装包实测确认。
无迁移、供应商、财务测试或生产写入。签名包核验信息在生成后补充。

## 2026-10-20 16:12 后续 iOS 序列化修复 IPA 已生成

`mobile/webview-shell/dist/release/ipa/__UNI__30F90A0__20261020160641.ipa`，
2.6.21（2621），沿用现有证书和 Bundle ID，通过 HBuilderX 安心云打包。
SHA256：`6d0c9d3cbaf92245667630835b1992eb715def31756b5b6ca2c654453c7fd442`。
原生/资源版本、Bundle ID/AppID、ZIP 完整性、三个主要资源逐字节匹配及
codesign --verify --deep --strict 均通过。包内确认包含 Foundation JSON 序列化
及新的原生操作诊断；相邻 verification.json 记录核验结果。
未上传分发或修改发布元数据；SAVE-INVALID 真机具体原因及本包效果仍待实测。

## 2026-10-20 iOS 后续 `SAVE-INVALID`：显式 Foundation 序列化

用户安装保护掩码修复包后再次提供截图，并确认诊断码为 `SAVE-INVALID`。
该码只证明 storage 阶段存在未映射异常；不能据此认定具体原生 selector 或 JSON
解析就是设备根因。旧路径使用 `newObject('NSString', jsonText)`，没有显式指定
Objective-C 初始化方法；读回 JSON.parse 的异常也会落到 SAVE-INVALID。

写入改为显式 NSMutableDictionary/setObject:forKey: 与
NSJSONSerialization/dataWithJSONObject:options:error:，不再依赖 NSString 的
构造参数映射。保持现有原子写入、0x40000001、备份排除、公司 scope、Token 格式、
完整读回验证和保存后显示屏障。空/无效 JSON、记录创建/编码失败分别归入固定的
IOS-JSON、IOS-CREATE、IOS-ENCODE；原生异常只展示允许列表操作阶段
IOS-NATIVE-CREATE/ENCODE/WRITE/ATTRIBUTES/COMPARE/READ/BACKUP，不输出原异常、
文件内容、Token 或用户信息。无故障时这些诊断不改变认证流程。

回归补充模拟 NSString 构造参数被忽略、JSON 为空/null 和原生序列化异常；
20 项 shell iOS/Token/诊断测试与类型检查通过。当前版本保持工作区已有
2.6.21/2621，重新编译并进行签名打包；真机首次失败具体来源仍待设备验证。
无服务端、H5、迁移、供应商或财务变更。

## 2026-10-20 11:02 iOS 安全存储修复 IPA 已生成

使用现有证书/描述文件和 Bundle ID，通过 HBuilderX 5.26 安心云打包生成：
`mobile/webview-shell/dist/release/ipa/__UNI__30F90A0__20261020110129.ipa`。
版本 2.6.20（2620），Bundle ID `com.tng.pingqiu`，AppID `__UNI__30F90A0`。
SHA256：`f1e1aa6e25f967c5b63fae336782903bbb2d6d19a13d0203c81daf1d5c9705c7`。
原生/资源版本和身份检查通过；三个主要资源与本次编译结果逐字节一致；包内
确认有 `0x40000001` 保护掩码及 NSString 比较修复。ZIP 完整性和解包后
`codesign --verify --deep --strict` 均通过。相邻 `.verification.json` 记录结果。
仅生成本地签名 IPA，未上传分发或修改发布元数据；登录保存、杀进程后恢复和
锁屏/解锁仍待新包真机验收。未将签名密码写入源码、报告或文档。

## 2026-10-20 iOS `IOS-PROTECTION` 保存失败修复

用户提供真机截图，失败码为 `IOS-PROTECTION`，Android 登录已正常。
定位到独立 shell 的 `src/lib/ios-credentials.ts` 写入掩码与读回要求不同：
写入 `0x30000001` 实际是 Atomic + CompleteUnlessOpen，而读回要求
CompleteUntilFirstUserAuthentication，其正确掩码为 `0x40000001`。
已用本机 Apple SDK Foundation/NSData.h 的枚举核实。修复写入掩码以匹配现有
保护要求，保持公司 scope、Token 格式、备份排除、读回验证及安全保存后显示屏障。
另兼容 Native.js 返回原生 NSString 属性句柄，使用 isEqualToString: 校验并
严格接受 BOOL true/1，不通过对象引用或任意字符串化推断保护成功。

新增 `tests/Frontend/webview-ios-credentials.mjs` 直接测试实际 shell 存储模块，
模拟系统根据写入掩码生成文件保护属性。修复前保存后稳定复现 storage unavailable，
修复后覆盖 JS/native NSString、数值 BOOL、Token 读回、跨公司、锁定、无保护及
备份排除失败。旧 `consumer-ios-credentials.mjs` 测试的是另一客户端模块，不能
代表这个独立 shell 的存储实现。原生资源已重编译，保留当前 2.6.20/2620。

必须重新签名构建并安装 iOS 原生包；单独更新 H5 不包含该修复。本次没有生成或
发布已签名 IPA，没有覆盖旧签名包，仍需真机验证首次登录保存、杀进程重启恢复、
锁屏/解锁和退出登录。无迁移、供应商或财务测试。

## 2026-10-19 19:17 登录诊断 IPA 已生成

通过 HBuilderX 5.26 安心云打包、沿用已有 iOS 证书与 Bundle ID 生成：
`mobile/webview-shell/dist/release/ipa/__UNI__30F90A0__20261019191731.ipa`。
版本仍为 2.6.12（2612）；不得用版本号替代本次文件校验。
SHA256：`d59c51db400bc7986dc26f37a79a94f2894d7f6d7d51c4db7dcb6f90efde0a9b`。
原生/资源版本、Bundle ID、DCloud AppID 均匹配；包内 app-service.js、
app-config-service.js、app.css 与本次编译产物逐字节一致；登录诊断、保留文档
重试和 token-v1 标记存在。解包后 codesign --verify --deep --strict 通过。
相邻 `.verification.json` 保存校验结果。尚无用户设备安装/登录验收，未发布
下载地址或更新服务端版本元数据。首次失败原因仍待此诊断包的真机证据。

## 下一次原生包的登录诊断验收

登录失败页直接展示允许列表诊断码，无需开启调试：CLAIM-HTTP-410 表示邮箱
凭证已不存在或版本不匹配；CLAIM-HTTP-422 表示领取请求校验失败；HTTP-429
表示限流；CLAIM/VERIFY-TIMEOUT、NETWORK 区分超时和请求失败；VERIFY-IDENTITY
表示身份不满足屏障；IOS-WRITE、IOS-PROTECTION、IOS-BACKUP、IOS-LOCKED
分别定位安全写入、保护级别检查、备份排除和受保护读取失败。
诊断码不包含原始异常、响应内容、Token、邮箱密钥、账号或用户 ID，也不上传。
新增模拟故障测试核对代码和脱敏。保存自动尝试由 60 次降为 3 次，避免连续失败
请求放大并最终只看见限流。此版本必须在签名包资源校验中包含诊断模块；不能
仅凭相同版本号认定设备已安装诊断。诊断定位失败操作，不等于自动查明其底层
网络或系统原因；还需结合具体代码分析，不能保证下一包直接解决首次失败。

## 2026-10-19 登录凭证失败后的原生重试修复

用户反馈最新版登录后出现“登录凭证尚未就绪”，重试持续失败。该提示来自
原生领取、验证或安全保存 Token 的共同失败分支，截图不能确认最初失败环节。
确认并修复的恢复缺陷：失败时关闭远端登录文档，丢失当前内存身份；随后
recovering 元数据又阻止恢复旧凭证，重连无法完成。现在保留隐藏的当前文档和
同一邮箱，重试该文档身份确认，安全保存成功后仍须通过当前内容/布局显示屏障。
不放行未保存凭证，不恢复上一账号，不清除网络失败中的凭证。

提示区分领取、身份验证和本机安全保存三个阶段，不展示原始异常、Token、邮箱
密钥或身份信息。离线测试覆盖安全保存失败后同一邮箱重试、阶段诊断以及原生
错误页保留文档/不重新选线。50 项相关回归和壳类型检查通过。
需重新编译、签名打包并安装原生壳；H5 更新不能修补已安装壳。此修改修复重试
死路，但首次失败的设备原因仍待新提示和真机验证确认。无迁移、供应商或财务测试。

## 2026-10-19 最新 WebView 登录后跳回登录页：会话确认保护

用户确认 Android 最新 2.6.12 在应用内部跳回登录页，非进程退出。
本次定位到现有 H5 的可复现错误分支：已登录并持有 Token 时，`confirmSession()`
收到同公司 `200 + user:null` 就清除 Token 并跳登录。`bootstrap()` 同样会将带
Token 的异常游客响应发布给原生壳；登录提交也缺少 bootstrap 后的已登录检查。
这只能证明该分支存在，尚无用户设备请求跟踪证明其实际触发来源。

现在带 Token 的游客成功响应视为暂时无法确认身份，保留凭证、既有页面并重试。
登录提交只有完整 bootstrap 返回已登录用户才允许后续跳转。明确的 401 加同公司
游客确认、显式退出、公司/身份/迟到结果屏障保持不变。未放宽服务端授权，也不自动
重放业务请求。不修改 PHP、数据库或原生壳；已安装 2.6.12 只需更新匹配 H5。

新增完整 WebView 登录到普通首页的 Chromium/WebKit 测试，再注入一次异常游客
会话响应。修复前实际跳回 `#/pages/login/index`，修复后停留在首页且登录身份
保留。28 项 Token/会话/生命周期单测、类型检查和 H5 构建通过；另回归两种浏览器
Token 登录、撤销和新旧壳游客/恢复启动。无迁移、供应商或生产财务测试。
部署包 `output/webview-login-session-fix-20261019.tar.gz` 包含新增哈希资源与入口，
先上传资源再替换入口，保留旧哈希。需用户部署与真机复核，不声称已验证手机修复。

## 2026-10-19 WebView 身份握手跨版本兼容修复

用户截图在 `https://113f.my` 报“网页身份确认尚未完成”。本次只读检查确认：
线上根入口加载 `index-xhpijFp8.js`（Token H5），与本地产物 SHA256 一致；
之前的 Cookie H5 `index-CjVTTRnC.js` 也仍在线且哈希匹配。旧 Cookie 壳不发送
`consumer-token-bridge`，因此直接加载 Token H5 会等不到 bootstrap 身份回报。
这是一条已确认存在的不兼容路径，不代表已核实用户手机安装包的版本。

共享入口现在先加载哈希命名的协议分发模块。在运行应用前，未携带新协议标记的
`app_webview=1` 加载保留的 Cookie H5；普通浏览器、新壳携带
`app_webview=1&native_auth=token-v1` 加载 Token H5。标记不含凭证，也不授予身份。
每个应用仍使用自己的原有服务端认证、公司检查和原生显示屏障，禁止在运行中混用
Cookie/Token、导入旧 Cookie 或跳过身份检查。

`scripts/client/webview-entry.mjs` 已接入 H5 构建，保留并校验旧应用全部静态模块
依赖。部署必须先上传新增哈希模块，再原子替换 `public/h5/index.html`，保留旧哈希
资源和两套服务端路由。不能只发布原始 uni-app 入口。新壳已重新编译原生资源，
需重新签名打包并核对 `native_auth=token-v1` 后才能发布；修复前生成的 2.6.12
签名包不能仅凭版本号当成含有本次修复。旧壳兼容修复本身只需更新 H5 入口。

验证：Chromium/WebKit 各覆盖旧壳、新 Token 壳的游客/已登录启动与身份回报；
Token 浏览器登录、重载、网络失败重试、撤销与内存恢复检查通过；47 项 shell、
readiness、共享会话和 Token 回归通过；壳类型检查、H5 构建与原生资源编译通过。
线上两套游客接口只读检查均返回合法 200。未进行迁移、供应商或生产财务测试。
线上入口发布与用户 iPhone 实测尚未完成，不能声称手机已修复。

## 2026-10-19 WebView 随机抽样选线（当前规则）

内置域名和缓存域名清理、去重后构成候选池。每次随机、不重复地抽取最多六条，
按每组三条分成两组；首组并发检测，全部失败或超时后才启动第二组，不逐个补位。
首条通过 HTTPS、公司 slug、已缓存公司 ID 及活动目录成员校验的响应立即胜出，
取消其他请求。不再等速度阈值、不比较全目录，也不把上次线路固定放在首组。
每组最多 2 秒、整轮最多 4 秒，最多六个请求、最多三个并发。两组均失败显示现有
重试入口；用户重试重新抽样。4 秒仅包括选线，不包括登录确认和网页加载。

完整的新目录仍会替换缓存，但不会追加检测其中的新域名。冷启动、手动重连和
前台目录刷新共用同一实现；前台仍保留当前线路、已挂载页面和滚动位置，手动
重连复用正在进行的目录发现。检测请求不带 Token；凭证只发往已验证的公司线路。
现有 Token、当前窗口身份和页面内容显示屏障保持不变。

此规则取代下文历史的缓存首选、持续补位、速度阈值和 16 秒全目录预算。
本次保留当前 `src/manifest.json` 的 2.6.11（2611），已重新编译壳资源；原生资源编译不等于
签名包或真机安装。发布仍须遵守下述 Token 配套切换要求，无服务端接口或迁移变化。
离线测试覆盖随机注入、两组数量/并发/时限、慢响应立即胜出、身份错误、迟到结果、
取消失败、目录缓存、重试重新抽样和前台页面保留。

# 2026-10-19 Token authentication cutover

WebView and ordinary browser H5 now use the existing `/api/mobile/v1` Bearer
transport, including registration, uploads, private images and support HTTP auth.
See [CONSUMER_TOKEN_AUTH.md](../architecture/CONSUMER_TOKEN_AUTH.md) for storage,
native mailbox, expiry/revocation and coordinated release requirements. This
supersedes cookie restoration for the new shell/H5 build. The shell version is
2.6.12 (2612). PHP, H5 and the signed rebuilt shell must be released together;
replacing a cookie-only installed shell's H5 entry alone will break its handshake.
Existing cookie sessions require one sign-in at cutover. Compilation is not cloud
packaging, deployment or device acceptance. No migration/provider/financial test.

## 2026-10-19 WebView 2.6.11 startup regression repair

The user reports widespread native startup timeouts after 2.6.03, while the same
phone browser can display the exact App login URL. Public directory, guest session
and H5 entry reads succeed. This does not establish that every company domain is down.

Comparison of the signed 2.6.03 and 2.6.10 embedded resources identifies a timing
change: 2.6.03 installs the H5 identity listener after loaded, while the later shell
can install before document readiness and permanently mark that load as prepared.
Retry the existing listener until the current document acknowledges its scoped H5
bootstrap; stop retrying once authenticated cookie capture or confirmed guest state
has begun. Keep company checks, credential capture, sequence and stale-window guards.

Content readiness also has a 200ms timer alternative to two animation frames for
transparent native windows whose animation frames pause. Both paths recheck the same
root, route, unchanged dimensions, visible nonempty content and pending-restoration
marker. Native identity confirmation remains mandatory. Timeout text identifies only
the structural stage; no credentials, user data or exception bodies are exposed.

24 startup/readiness tests, 18 shared-session tests and shell type checking pass, including dropped early
listener retries, paused frames, stale route/root/layout, pending restoration and
unchanged discovery bounds. Native-device startup remains unverified. No H5/server
changes, migrations or financial/provider operations are included.

DCloud rejected cloud packaging due to today's quota. The test APK was instead built
locally using apktool, zipalign and apksigner with the existing release certificate,
reusing 2.6.10 native dex/libraries unchanged. Compiled resources match the source
build. Native/resource versions are 2.6.11 (2611), certificate SHA256
`e972513b35b8087efce434e66fbee8df3beb0fc4db1cb7b1fd5b3f313192332f`.
APK SHA256: `0ea158798bfd6a0cc21931356df5694c5c5f2b3bba04596dfa9bbe53aa725c9f`.
See `output/U-card-2.6.11-startup-20261019.verification.json`; publication to the
user-requested root key `1.apk` is confirmed separately in `output/1-apk-publication.json`.

## 2026-10-19 Published APK versus local startup repair

The user confirmed that the failing device installed the latest OSS-uploaded 2.6.10.
The publication record and the full OSS object backup taken before the 12:00 rollback
both identify SHA256 `1abada619b7f89afd9bbd26eb272700d0dcb1fd8f6a97e2f9061e17d9f1e5138`.
That package still directly calls `.match()` on native `getURL()` without a string guard.
The local signed startup repair is a different APK, SHA256
`994fbdc3978e1bb5cfaa8035d80b615520d22f26505f04b40d96cb6bc9f19a4e`, available as
`output/U-card-2.6.10-startup-fix-20261019.apk`; its embedded URL and address helpers
contain the string guards. Both packages display 2.6.10 (2610), so the version label
cannot establish that the startup repair is installed. Do not claim the local repair
was published based on the old publication report. Device startup remains unverified.

## 2026-10-19 Website APK rolled back to 2.6.03

At the user's request, OSS key `U 卡.apk` was restored from the retained
`specpay-2.6.03-2603.apk` object. The original local APK's ZIP integrity and release
signature were verified. The replaced 2.6.10 object was privately backed up before
replacement. Full authenticated OSS readback matches original 2.6.03 SHA256
`b3b89ffd6706ee295e74f11bd1a945627a0df197cc46529dced2e6cd8c821f87`.
Public-read, AES256 and no-cache/no-store response headers were retained.
Only `U 卡.apk` was replaced; backend release metadata was not changed.
Full public HTTPS download from `https://zb33333.com/U%20%E5%8D%A1.apk` also
matches that original hash. The HTTP check timed out before completing; HTTPS
readback passed. Physical-device downgrade/installation remains unverified.

## 2026-10-19 Signed Android 2.6.10 release published

With explicit user authorization, HBuilderX 5.26 safe cloud packaging produced
`mobile/webview-shell/dist/release/apk/__UNI__30F90A0__20261019101724.apk`.
Native package `cc.specpay.cards`, native/resource version 2.6.10 (2610), APK ZIP
integrity and the existing release certificate were verified. All three embedded
app-service.js, app-config-service.js and app.css files exactly match the compiled
resources. The 23 built-in seeds and 1000ms immediate / 2000ms per-probe / 16000ms
whole-discovery parameters were checked inside the signed APK.

SHA256: `1abada619b7f89afd9bbd26eb272700d0dcb1fd8f6a97e2f9061e17d9f1e5138`
Size: 27,642,043 bytes. Release signer SHA256 remains
`e972513b35b8087efce434e66fbee8df3beb0fc4db1cb7b1fd5b3f313192332f`.

The actual previously published objects specpay.apk and `U 卡.apk` both contained
signed 2.6.03, despite their same size as later builds. They were fully downloaded,
signature/hash checked and backed up privately at
`~/Private/SpecPay/release-backups/before-2610-20261019.apk`, with the companion JSON
recording both prior object ETags and the original SHA256.

The new version-labelled OSS object specpay-2.6.10-2610.apk was uploaded in 106 verified
parts and fully read back before copying to both existing download keys. Both OSS
objects and both plain public URLs were fully read back and SHA256 matched the signed
local artifact. Public-read, AES256 at rest, APK content type and
`Cache-Control: no-cache, no-store, must-revalidate` were retained. Local verification
and publication JSON records accompany the APK. Temporary OSS credential export was
removed after publication. No backend version metadata, PHP/H5, migration or financial
provider operation was changed. Physical-device installation/startup remain unverified.

## 2026-10-19 WebView sixteen-second discovery budget

Shell 2.6.10 (2610) extends only the default total discovery budget to 16000ms, so all
23 built-in candidates can be reached when preceding hosts consume their timeouts.
Keep at most three concurrent probes, 2000ms native/JavaScript individual bounds and
immediate selection of the first freshly company-verified response within 1000ms.
Fast responses still abort all remaining requests immediately. Slower verified results
remain fallback candidates. Preserve prior-host priority, fresh membership checks,
identity barriers, visible retry and foreground no-switch behavior.

Eight two-second groups cover 23 candidates nominally; real scheduling overhead can
reduce the last group's time because the global 16-second deadline remains authoritative.
Recompile, cloud-package and reinstall signed native shells; no PHP/H5 deployment,
migration or financial/provider test is required.

## 2026-10-19 WebView built-in fallback directory

Shell 2.6.09 (2609) includes the original 27m.my, 113b.my and 18k.my plus the twenty
user-specified 113c–113z company domains (excluding the unspecified letters). All 23
origins are discovery candidates only: each still requires fresh company and active
membership verification. After the first group fails or times out, free slots try the
next built-in candidates even without a previously successful directory/cache.

Retain at most three concurrent probes, prior-host priority, 1000ms immediate selection,
2000ms individual timeout and the 4000ms overall budget. The total budget does not
promise an exhaustive scan of all 23 unreachable hosts. Recompile, cloud-package and
reinstall the signed shell; no PHP/H5 deployment or migration is needed.

## 2026-10-19 WebView two-second slow-line fallback

Shell 2.6.08 (2608) preserves first verified response within 1000ms immediate selection,
three concurrent probes, prior-host priority and the four-second total budget. Both the
native directory request and the independent JavaScript probe deadline now allow 2000ms.
Responses above 1000ms remain verified fallback candidates, rather than immediately
winning; discovery chooses the fastest verified fallback when candidates finish or the
overall budget expires. Unknown callbacks are aborted before slots are reused. Fresh
company/membership checks and foreground no-switch behavior remain unchanged.

Recompile, cloud-package and reinstall the signed shell to apply this client change;
resource compilation alone does not update an installed APK/IPA. No server deployment,
migration or financial/provider test is required.

## 2026-10-19 Production PHP startup optimization

With explicit user approval, deployed only the lightweight directory changes in
`ResolveTenantFromHost.php` and `routes/consumer-api.php`. Enabled the already installed
PHP 8.4 OPcache extension in the FPM php.ini, retaining timestamp revalidation defaults,
generated Laravel configuration/route caches as the www user, and gracefully reloaded
PHP-FPM after its configuration test passed. No migration, financial/provider operation,
H5/native rebuild or frontend proxy change was performed.

Rollback copies of both PHP files, php.ini and the previous bootstrap/cache directory
are stored privately on the main server in `/root/card-perf-20261019-094737` (0700).
The generated configuration cache is www-owned and mode 0640. Future environment or route
changes require regeneration of their respective Laravel caches; never expose cached
configuration or private backups through a web route.

The same main-server loopback directory endpoint returned HTTP 200 in 212–243ms before
(median 226ms, five samples), then 28–34ms in seven warm samples after a 453ms first cold
request. Front-server direct-origin and local-proxy checks subsequently took 22–26ms.
External checks of 27m.my, 113b.my, 113c.my and 18k.my (three requests each) all returned
200 in 582–965ms with the unchanged 544-byte body. These are bounded samples, not a
promise of phone startup latency. Verified private/no-store, current tenant/directory,
unknown-host 404, guest session 200, H5 entry 200 and OPcache mapped in running FPM.

## 2026-10-18 Lightweight domain directory reads

Both domain-directory API paths omit presentation eager loading and locale resolution,
reusing the existing minimal tenant lookup already used for session confirmation.
Host ownership, company availability, directory membership, throttling, cleanup fences
and private/no-store responses remain fresh and unchanged. No cached ownership or static
ping file substitutes for the verified directory. Deploy PHP for this optimization;
no H5 rebuild, native rebuild or migration is required for this server-only change.

Local loopback HTTP samples (10 sequential calls each) had median 95ms before and 71ms
after, with the same 225-byte directory body. These samples include local development
server/bridge overhead, exclude public DNS/TLS/CDN and do not establish phone or production
latency. Public-directory response latency still needs deployment-side investigation.

## 2026-10-18 WebView one-second qualifying response

Supersedes the 300ms qualifying threshold below: select the first freshly company-verified
response taking at most 1000ms, abort remaining requests immediately and do not probe
queued domains after selection. Keep at most three concurrent probes, previous-host
priority, 1s individual timeout, 4s overall budget, full fresh directory and identity
barriers. Foreground refresh still never switches the mounted page.

Build shell 2.6.07 (2607) resources, then cloud-package and reinstall the signed App;
resource compilation alone does not update installed Apps. No PHP/H5 deployment or
migration is required for this shell-only threshold change.

## 2026-10-17 Correct website APK object: `U 卡.apk`

The user clarified that the website downloads OSS key `U 卡.apk`, not `specpay.apk`.
The previous rollbacks updated `specpay.apk` only and therefore did not update this
website download. Direct OSS inspection confirmed `U 卡.apk` was still version
2.6.05 (2605), SHA256 `28a977a9f308282775d04eaeb4c319aec2028140ce7959dbb2ad8e1cb624ed2e`.
This confirms the user's installed-version screenshot and is an object-path mismatch,
not evidence of stale CDN caching on the previously verified `specpay.apk` path.

The old website object was backed up privately as
`~/Private/SpecPay/release-backups/U-card-before-rollback-2603.apk`.
At the user's existing rollback authorization, `U 卡.apk` was then replaced by the
original signed October 9 2.6.03 (2603) artifact. Full direct OSS and public URL
`http://zb33333.com/U%20%E5%8D%A1.apk` downloads both matched SHA256
`b3b89ffd6706ee295e74f11bd1a945627a0df197cc46529dced2e6cd8c821f87`.
The public response was a CDN MISS and its embedded version was 2.6.03 (2603).
AES256/public-read and no-cache/no-store headers were retained.
A separate version-labelled `specpay-2.6.03-2603.apk` copy was also created and publicly
hash-verified. Future publication must check the actual per-company website download
URL/key, including the space and Unicode characters, before selecting objects to update.
No backend release metadata or source changed. Phone installation/startup remain unverified.

## 2026-10-17 Public rollback download and native version verified

Follow-up to the user's concern about identical APK sizes: fresh full downloads from
both `http://zb33333.com/specpay.apk` and
`http://zb33333.com/specpay.apk?v=2603-20261017` succeeded. Both matched the original
2.6.03 SHA256 `b3b89ffd6706ee295e74f11bd1a945627a0df197cc46529dced2e6cd8c821f87`
and embedded resource version 2603/2.6.03. CDN responses were `MISS TCP_MISS` and
retained `no-cache, no-store, must-revalidate`. This resolves the earlier transient
public-download verification limitation. Apktool decoding of the matching original
APK additionally confirmed native Android versionCode 2603 and versionName 2.6.03,
not just the web-resource manifest. Several retained releases have the same exact
27,642,043-byte length but distinct hashes; file size alone cannot identify the release.
No additional OSS or application changes were made by this verification.

## 2026-10-17 Android rollback to last pre-today package: 2.6.03

The user explicitly requested the last package built before October 17 after the
2.6.05 and 2.6.04 attempts. The last retained package matching the current app is
`mobile/webview-shell/dist/release/apk/__UNI__30F90A0__20261009232635.apk`,
October 9 at 23:26, version 2.6.03 (2603). ZIP integrity and release-signature
verification passed. Its resource manifest id, native dcloud_control appid and
assets/apps directory all match `__UNI__30F90A0`; these identities also match in
2.6.06. This inspection found no package-internal AppID mismatch and does not
establish the cause of the reported startup failure.

OSS `specpay.apk` was atomically replaced with that original signed 2.6.03 APK.
Direct authenticated OSS readback returned all 27,642,043 bytes and matched SHA256
`b3b89ffd6706ee295e74f11bd1a945627a0df197cc46529dced2e6cd8c821f87`.
AES256 and `no-cache, no-store, must-revalidate` response headers were confirmed.
The replaced 2.6.04 was publicly downloaded and hash-verified before replacement,
then saved privately as `~/Private/SpecPay/release-backups/specpay-before-rollback-2603.apk`.
Public download-domain verification after this replacement encountered connection
timeouts; a separate HTTP/HTTPS retry was stopped after it failed to finish promptly.
Do not claim public CDN readback passed for this rollback. The unchanged public URL
is `http://zb33333.com/specpay.apk`. No source or backend release metadata changed.
Device startup and installation downgrade remain unverified.

## 2026-10-17 Android OSS rollback to signed 2.6.04

After 2.6.05 still showed the startup error, the user explicitly requested 2.6.04.
OSS `specpay.apk` was atomically replaced with the original signed
`mobile/webview-shell/dist/release/apk/__UNI__30F90A0__20261017084716.apk`,
version 2.6.04 (2604). ZIP integrity and apksigner verification passed with release
certificate SHA256 `e972513b35b8087efce434e66fbee8df3beb0fc4db1cb7b1fd5b3f313192332f`.
The public download was read back in full and matched SHA256
`3887f4d05982ab2593ddb05b98f3174ca02d0b501f0f1f2c7583fa6f6f489425`
(27,642,043 bytes). The replaced 2.6.05 was downloaded, hash-verified and saved privately
as `~/Private/SpecPay/release-backups/specpay-before-rollback-2604.apk`.
The same public URL and OSS AES256/cache protections are retained. No source, PHP/H5
or backend release metadata changes were made for this rollback. Physical-device
startup remains unverified; Android may block a lower-version overwrite install.

## 2026-10-17 Android OSS rollback to signed 2.6.05

After reports of the local `__uniappview.html` startup error across multiple Android
models, the user requested a rebuild and OSS update. Clean 2.6.07 resource compilation
succeeded, but DCloud rejected cloud packaging because the account daily quota was
exhausted; no signed 2.6.07 APK was produced or published. Source version was restored
to the pre-attempt 2.6.06 value; any 2.6.07 compiled resources remain unpublished.

At the user's subsequent request, OSS `specpay.apk` was atomically rolled back to the
existing signed `__UNI__30F90A0__20261017134335.apk`, version 2.6.05 (2605).
APK ZIP integrity and apksigner verification passed with the existing release
certificate. The public download `http://zb33333.com/specpay.apk` was fully downloaded
and SHA256 matched the rollback artifact:
`046c0706cbe2cc6609c64a1b510538a14f4b365e998932551bb07d2030bf3db4`
(27,642,043 bytes). The replaced 2.6.06 object was downloaded and saved privately as
`~/Private/SpecPay/release-backups/specpay-before-rollback-2605.apk`, matching its
previous published SHA256. OSS retains AES256 encryption and revalidation/no-store
response headers. No backend release metadata or PHP/H5 was changed.

This rollback does not establish that Android startup is fixed. Physical device
startup remains unverified. Android can reject installing a lower version over 2606;
do not represent the OSS rollback as an automatic downgrade of installed clients.

## 2026-10-17 Signed 2.6.06 packages and Android OSS publication

HBuilderX 5.26 safe packaging completed Android and iOS 2.6.06 (2606).
Both native/resource versions and all three embedded app-service.js,
app-config-service.js and app.css files match the current resource build. Android
apksigner verification matches the existing Spec Pay release certificate; iOS passes
codesign --verify --deep --strict with the existing registered-device profile.

Artifacts are under mobile/webview-shell/dist/release:
- apk/__UNI__30F90A0__20261017142924.apk
- ipa/__UNI__30F90A0__20261017142859.ipa

At the user's explicit request, the signed Android package replaced OSS object
specpay.apk behind http://zb33333.com/specpay.apk. Single-stream uploads stalled;
106 verified parts completed and were atomically assembled at the existing key.
The original public URL was fully read back and SHA256 matched the local signed APK:
d99201dfe9fdefc4cc4b36ef547af0119fcb61b44a79f72569b0d33b1c6ffab9
(27,642,043 bytes). APK response remains no-cache and AES256 encrypted at rest.
A verified previous package remains in the private local release backup directory.
Temporary password/signing authorization files were removed; original signing
materials remain private and unchanged. No company release metadata, PHP/H5,
financial evidence or provider operations changed. Device installation and actual
startup latency have not been verified by this packaging/publication step.

## 2026-10-17 Welcome wait investigation (2.6.06)

A phone running 2.6.05 reported about three minutes on the black/gold welcome.
This has not been reproduced on a physical device. The shell previously began session
signal installation and readiness checks only at native `loaded`, which can wait for
slow subresources even after usable H5 content has painted. Start the existing guarded
checks at `rendering` as well. Install document listeners once per loading generation;
retain loaded as fallback, scoped identity confirmation, pending-session rejection,
paint/layout checks and stale-window guards. In addition, start the readiness loop
when loading begins; it waits for the current trusted origin without relying on either
native rendering or loaded callbacks. A ready page can reveal even if neither arrives.
Repeated loading events/redirects reuse one original 30-second display deadline instead
of restarting it; a new window or navigation after ready starts its own display budget.
This is a bounded failure path, not a claim that slow networks become fast.

Session verification also has an independent 15-second JavaScript deadline which aborts
the native request and rejects late callbacks. Transport timeout alone must not strand
restoration. Failure keeps credentials and offers the existing retry screen. Discovery
and foreground no-switch behavior are unchanged. Diagnostic version text now follows
the manifest instead of a stale hardcoded value.

Offline event-order, missing-callback, identity and browser-readiness tests pass. Compile
2.6.06 (2606) App resources and cloud-package/reinstall the signed shell to apply this
change. Compilation is not installation or a verified phone startup improvement. No
PHP/H5, migration, provider or financial change is needed for this shell-only patch.

## 2026-10-17 WebView line/cache/reveal review

Confirmed existing behavior: the previous selected host is included first in the three
concurrent fresh probes; first verified response within 300ms wins. Foreground directory
refresh never reloads or changes the active WebView origin. Keep these semantics rather
than introducing a serial delay or trusting stale domain ownership.

The reveal probe now rejects the login page's explicit pending-session marker and rechecks
the marker, root and route after its two animation frames. Successful guest forms and
retry errors can render; mere restoration text cannot reveal the child. Existing native
identity gate, opaque welcome, stale-window guards and layout pulse remain intact.

`public/h5/.htaccess` adds immutable one-year caching only for content-hashed static
filenames, with HTML/JSON revalidation. Nginx deployments must merge the supplied
`docs/deployment/snippets/webview-h5-cache.nginx.conf` into the consumer H5 virtual host,
respecting its current root/alias routing and security headers; root-mounted examples
are explicitly opt-in. Never apply these H5 entry locations to Platform/API servers.
Keep HTML revalidated, API private/no-store, unversioned static files outside immutable
rules, and retain old hashed assets during releases. The local artisan server bypasses
Apache/Nginx static headers, so it does not validate deployed caching. No server config
reload or production change was performed. Rebuild/reinstall shells for probe changes;
deploy matching H5 for the pending marker. Existing APKs remain unchanged.

## 2026-10-17 Independent homepage sections, warm resume and startup diagnostics

The authenticated operational `GET /home/summary` and `/home/activity` routes share
existing consumer tenant/user authorization. Summary retains the complete valuation
snapshot but skips movement/order history; recent activity loads separately with its
original scoped evidence. Neither read provisions wallets or calls providers. Unknown
estimates remain unknown. Summary and activity failures/retries are independent, scoped
late results are discarded, and confirmed authorization failure clears visible sections.
The new frontend opts into `bootstrap?defer_unread=1` (unread=null); it loads unread
counters in the background. Old bootstrap clients still receive counters by default.

Warm resumes keep the mounted route and existing scroll restoration. Homepage sections
reuse same-session memory for 15 seconds; stale sections refresh without clearing the
visible snapshot. App show/focus/visibility signals share one resume check, using the
minimal session endpoint before restoring foreground sockets. Hidden completions cannot
restart them. Network errors retain the page and retry quietly; a valid guest response
or native bearer 401 confirms expiry. Browser authorization errors alone do not erase
cookies. Identity changes require fresh bootstrap; no cached state authorizes server reads.

Allowlisted startup phases (line discovery, restore, bootstrap, document/identity/content,
home sections and resume checks) retain at most 40 numeric duration/outcome records in
memory. `globalThis.__consumerStartupTimings` exposes copies for opt-in diagnostics; the
shell diagnostic panel shows those safe fields. No account, URL, token, persistent log
or telemetry upload is added. Durations measure actual stages and do not promise a fixed
end-to-end startup time. Deploy matching PHP/H5; compile and repackage/reinstall native
shell resources to apply shell timing. No migration or financial/provider test required.

## 2026-10-17 Lightweight session verification and direct authenticated entry

WebView restoration now calls `GET /api/v1/session` instead of full `bootstrap`.
The shared consumer routes also expose `/api/mobile/v1/session` with existing bearer
scope. The minimal response contains only Host-resolved tenant ID/slug, user ID or
null, and the restricted flag; it remains private/no-store. Host resolution skips
branding/locales only for these exact session paths, retaining fresh active-domain
ownership checks, cleanup fences, remember-cookie/session-version checks and existing
company/user status gates. No locale/flow setup, assets/download metadata, unread
counters, wallet provisioning or provider operations run in this endpoint.

After native cookie commitment and server identity confirmation, signed-in users open
`/#/pages/assets/index` directly; restricted accounts open their existing restricted
screen. Confirmed guests open the normal login entry. An unexpected authenticated
result with no intended credential is rejected, as are invalid company/status payloads.
A valid same-company guest result can represent expiry without erasing the vault;
network/malformed/authorization errors retain credentials and the retry screen.
The destination H5 still performs full bootstrap and emits its own verified session
signal before the shell reveals rendered content. No credentials are passed to page
JavaScript or URLs, and no cached identity authorizes business reads.

The H5/native login page mounts only a neutral restoration/retry screen until successful
guest confirmation. Signed-in users navigate directly home while its form stays unmounted;
network failure cannot expose a misleading login form or erase credentials. Hidden
login pages cannot navigate on late completion.

Deploy PHP/routes first, then matching H5 resources, and rebuild/cloud-package/reinstall
the WebView shell. The server endpoint must be live before shipping the new shell;
older shells remain compatible with full bootstrap. No database migration required.

## 2026-10-17 WebView first qualifying response with three concurrent probes

The WebView shell runs at most three domain probes concurrently. The first response
taking at most 300ms that verifies the packaged company, pinned tenant ID and current
domain membership wins immediately. All other native requests are aborted and queued
hosts are not started. Late responses cannot change the winner or its directory.
This supersedes the earlier sequential and exhaustive absolute-fastest policies.

The last selected cached domain is prioritized in the initial group, but cache alone
never authenticates a destination. A valid response supplies the full fresh directory;
retired/unassigned candidates are excluded, including cancellation of pending excluded
hosts. Vacant slots may try remaining verified-directory candidates until a qualifying
response succeeds. No full-directory scan runs after a winner is chosen.

Retain a 1s per-request limit and 4s total discovery budget. Abort expired native
requests before reusing their slots. If no response meets 300ms, use the fastest
verified response obtained within the budget; no verified response means a retry screen.
Foreground refresh and manual reconnect share pending discovery. Discovery and login
restoration have separate UI states. Company/session bootstrap barriers, native secure
cookies and explicit logout behavior remain mandatory and unchanged.

Rebuild `mobile/webview-shell` resources, cloud-package and reinstall the signed App.
Source/resource compilation does not update an already uploaded APK. No database
migration, provider calls, financial operations or H5/server deployment are required.

## 2026-10-08 Advisory updates for common app versions

Native release checks now compare only valid installed/published version codes;
release AppID and tenantSlug do not gate update eligibility. Company API domain
verification remains separate. Android and iOS keep their own safe download URLs.
Checks run in the background on startup/foreground. Business requests never await
release checks or return 426 due to them. Checking/failure does not cover the app;
a newer downloadable release shows a dismissible notice. Dismissal or opening the
download suppresses that version for the process lifetime, including foreground
return; a higher release may be offered. Download opening failures leave the app usable.
This supersedes the mandatory-update behavior documented below. Recompile and
cloud-package/install APK and IPA to update existing native clients; an H5 deployment
cannot change their bundled code. Publication metadata remains company-scoped;
companies using the common app should publish the same version code.

# uni-app / HBuilderX 打包

## 当前状态

2026-09-29 用户确认官网及消费者页面使用新版 uni-app H5，旧 React H5 不再维护。
SaaS 后台继续使用 React/Inertia；线上入口是否完成切换以部署验证为准。范围和验证边界见
[架构说明](../architecture/CONSUMER_UNI_APP.md)与[验收清单](../testing/uni-app/PARITY.md)。
App 资源可编译，APK/IPA 云打包和真机验证仍需公司的 DCloud AppID 与签名材料。

Android 的生成配置固定包含 `arm64-v8a`（64 位）和 `armeabi-v7a`（32 位），
在 `app-plus.distribute.android.abiFilters` 中声明。修改持久来源
`scripts/client/index.mjs`，不要只改会被 prepare/build 覆盖的生成 manifest。
重新生成后通过 HBuilderX 云打包获取新的 APK；仅构建前端资源不会改变已有 APK。
验收实际 APK 的 `lib/arm64-v8a/` 和 `lib/armeabi-v7a/`，并在目标设备验证安装。
系统报告架构不匹配时，应比对实际下载文件、设备 ABI 和完整安装错误，
不能仅凭提示认定所有安装失败都是缺少 64 位支持。

新版 H5 官网首屏、手机菜单和页脚提供安卓版下载，链接固定为
`http://zb33333.com/specpay.apk`（不随当前域名或 `/h5/` 等部署目录变化）。部署时需提供实际 APK，并确保该路径
不被 H5 的 index 回退规则捕获。原生 App 不显示此下载入口。

## 本地开发

使用 Node.js 22+。在仓库根目录执行：

```sh
npm ci --prefix mobile/uni-app
npm run client -- prepare --company local
npm run client -- dev --company local
```

打开 `http://127.0.0.1:5200`。开发代理将 `/api/v1` 转发至 local 配置里的
公司域名；H5 使用 Cookie/CSRF，App 使用独立 Bearer API。不要将开发服务器
公开到互联网。H5 部署必须与对应公司 API 同域；不要用跨域 Cookie 绕过。

H5 的 API 使用当前访问域名，同一公司的多个域名可共用一份 H5。
2026-10-16 起，复制／分享的邀请链接和海报二维码统一使用当前访问域名的
`/register?invite=<推广码>`，覆盖原固定启动页域名规则。
H5 和旧 React 消费者入口使用浏览器当前 origin；原生 App 使用当前已验证的公司 API origin。
注册仍按访问域名所属公司校验邀请码，不改变推荐关系或接受跨公司邀请码。
需部署重新编译的 public/h5（旧 React 消费者入口同步更新 public/build）；
原生 App 需重新编译安装后生效。
每个域名都必须由后端绑定同一公司并路由 `/api/v1` 到 Laravel。
`tenantSlug` 仍与 bootstrap 的公司标识严格比对，不因切换域名放宽。
App 使用内置 `apiOrigins` 和上次缓存发现当前公司域名，并在每次启动／回到前台时同步测速选优。`specpay.json` 已按用户确认保留 `tenant-a`，仅用于
该公司；其中原生包名尚未完成 App 发布验证。

```sh
npm run client -- build --company specpay --mode release --platform h5
```

产物为 `dist/clients/specpay/release/h5`；无需 DCloud AppID。

### 项目内 `public/h5` 部署

按用户要求，部署产物存放在 `public/h5`，迁移项目时一并带上。此目录只是产物位置，
不限定浏览器访问路径。同一份默认相对路径构建可部署在域名根目录、`/h5/`、`/client/`
或其他目录，部署时指定静态站点 root/alias 即可，不需要重新编译。目录 URL 应带末尾
斜杠（如 `/client/`），也支持 `/client/index.html`。公司标识仍为 `tenant-a`。
`/api/v1` 必须继续交由同域 Laravel 处理，回调和后台保持原后端路由。本次只放置文件，
未修改服务器入口或切换原 H5。

```sh
npm run client -- build --company specpay --mode release --platform h5
```

构建后按 `OSS_IMAGES.md` 执行 OSS 发布并验证 `index.oss.html`。
将 `index.oss.html` 部署为 `public/h5/index.html`；不要用未注入清单的原始
`index.html` 覆盖。保留前一版入口和哈希资源直到旧会话过期。默认 `--base ./`，资源和
邀请链接自动跟随当前部署目录，邀请链接使用目录下的 hash 注册路由，无需单独配置
注册页伪静态。API 始终使用根路径 `/api/v1/`。只有明确需要固定 URL 前缀时才传
`--base /指定目录/`。

```sh
npm run client:typecheck
npm run test:client
npm run client -- build --company local --platform h5
npm run client -- build --company local --platform app
```

输出为 `dist/clients/<company>/<debug|release>/<h5|app>`。App 输出是编译资源，
**不是 APK/IPA**。原网站仍使用根目录的 `npm run build`。

## 每家公司配置一次

复制 `mobile/companies/local.json` 为该公司的配置文件。填写真实的名称、
`tenantSlug`、HTTPS `apiOrigin`、反向域名格式 `appId`（原生包名）、
`dcloudAppId`（DCloud 分配的 `__UNI__...`）、版本及构建序号。

这两个 AppID 不同：DCloud 标识用于 DCloud 项目，原生包名用于系统与商店。
debug 自动给原生包名追加 `.debug`；正式签名需对应 release 包名。
正式公司配置设置 `developmentOnly: false`。local 配置故意不能用于 release；
发布检查会拒绝测试域名、HTTP、错误包名或未知字段。仅 App release 要求 DCloud
AppID；纯 H5 使用 `--platform h5` 时可留空。`prepare` 默认目标为 App。
API 凭据、Apple 密码、签名私钥和证书密码不得写入 JSON/仓库。

## HBuilderX 云打包

1. 先运行 `npm run client -- prepare --company <公司配置名> --mode debug`。
2. HBuilderX 导入 **mobile/uni-app 整个目录**，不要只导入 src，也不要导入整个
   Laravel 仓库。CLI 项目使用自己的锁定编译器；只导入 src 会换成 HBuilderX
   自带编译器，可能产生版本差异。
3. 登录自己的 DCloud 账号，为该公司申请 AppID，回填公司 JSON 后重新 prepare。
4. 检查生成的 `src/manifest.json` 中公司名称、包名、版本及平台配置，配置公司
   正式图标、启动图、隐私清单和平台签名；目前默认图标不能用于正式发布。
5. 通过“发行 → App 云打包”选择 Android/iOS 和相应证书，完成云打包后下载
   APK/IPA，真机验证后再提交商店。iOS 仍需要合法的苹果开发者账号和对应证书。

2026-09-29 已将六个 uni-app 编译依赖及锁文件从 5.24 统一升级到
`3.0.0-5020620260917001`（5.26 正式版），匹配当前 HBuilderX
`5.26.2026091802`。Vue/Vite 保持该编译器要求的 3.4.21/5.2.8。
后续更新须同时核对 IDE、项目 CLI 和云打包环境，不能仅更新 IDE 或伪造版本号。
本地验证不代表已生成新 APK；仍需重新云打包并完成真机测试。

生成文件位于 `src/generated` 和 `src/manifest.json`，不提交版本库。prepare
会重新生成，长期品牌配置应补充到公司配置生成流程，不要只手改生成文件。
一次仅准备／构建一家公司，避免修改同一工程的公司配置时另一个构建仍在运行。

## 上架前剩余工作

- 完成签名安装包的真机验收、设备权限、文件上传下载、后台敏感信息遮挡及运营方隐私审核。
- 当前原生登录不持久化，重启需要登录；如需持久登录，应配置并验证 Keychain/Keystore 存储。
- 配置真实 AppID、公司图标、证书和签名；确定发布地区及运营主体材料。
- 锁定的 DCloud 工具链存在 npm audit 报告，不能执行 `audit fix --force` 切回
  不兼容的 Vue 2 包来“消除”报告。发布前须完成受支持的工具链升级及依赖风险
  复核。本工程尚未通过正式发布依赖门禁。
- 验证真实 iOS/Android 安装包、后台遮挡、文件处理及公司链接，不以 H5 测试
  代替原生验收。

官方参考：[CLI/HBuilderX 工程区别](https://uniapp.dcloud.net.cn/quickstart-cli)、
[云打包](https://uniapp.dcloud.net.cn/dev/app/cloud-build.html)。

## 生成 H5 与对比预览

```sh
npm run build
npm run client -- build --company local --platform h5
node scripts/client/preview.mjs
```

生成版预览默认 `http://127.0.0.1:5202`。该工具只监听本机，将 API 请求代理到
prepare 后公司的 `apiOrigin`。端口占用时指定 `UNI_PREVIEW_PORT=5203`；不要停止
不属于此次预览的服务。其他公司输出通过 `UNI_PREVIEW_DIR` 指定。对照图库生成后
位于 `http://127.0.0.1:5202/__parity/index.html`。

本地 debug H5 重建保留该公司输出目录中的旧哈希资源，先复制新资源，再原子替换
`index.html`，避免已打开的页面切换到消息／客服时请求旧脚本出现加载超时。
刷新页面可进入最新版本。release 和 App 输出仍保持干净构建；正式静态发布也应
在旧会话有效期间保留其可访问的哈希资源，而不是只保留无法通过原 URL 访问的备份。

正式 H5 部署仍是拉取代码、安装锁定依赖、按公司 prepare/build，然后原子发布
`dist/clients/<company>/release/h5` 的静态产物。原根目录 `npm run build` 继续构建
SaaS/React。切换前需审核 Nginx：`/api/v1`、回调、PHP、私有图片和现有后台域名仍
交给 Laravel；仅消费者页面与其静态资源指向新 H5，旧路径访问由 uni-app 的入口
转换到对应页面。不得将 API/回调误返回 H5 index.html。保留旧静态版本便于前端
回退；前端回退不回滚财务数据或迁移。此次未修改生产 Nginx 或替换正式入口。

App manifest 已显式包含相机／相册 Camera 和系统分享 Share 模块，以及 iOS 拍照、
读相册和保存海报的用途说明。权限仅在用户选择上传／保存时触发；未加入录音、
定位、通讯录、第三方分享 SDK 或推送。参见 DCloud 官方
[功能模块](https://uniapp.dcloud.io/tutorial/app-modules.html)、
[系统分享](https://uniapp.dcloud.net.cn/share)、
[manifest 权限描述](https://uniapp.dcloud.net.cn/tutorial/app-manifest)。

## App 内置域名与自动选线

在公司的 JSON 配置中填写 `apiOrigins` 数组，例如：

```json
"apiOrigin": "https://primary.your-company.com",
"apiOrigins": [
  "https://primary.your-company.com",
  "https://backup.your-company.com"
]
```

以上仅为格式示例，必须替换为该公司的真实域名。`apiOrigin` 也会自动加入初始列表，
同时继续用于本地 H5 代理。specpay 配置按用户 2026-10-08 指定仅内置 `27m.my`、`113b.my`、`18k.my`，全部使用 HTTPS。
`specpay.cc`、`specpay.top`、`specpay.vip` 已从内置种子移除；服务器目录和缓存的动态发现规则不变。
`zb33333.com` 是静态下载站，不加入 API 入口列表。release 对每一个域名检查 HTTPS
和非测试域名要求。修改后重新 prepare／打包。

日常新增域名在 SaaS「系统设置 → 域名」中启用并分配给该公司，无需重新打包。
App 从任意可用的已知入口获取该公司全部已启用域名，缓存完整列表，以最多六个
并发请求测速（单个超时四秒），选择本次成功响应最快的入口。被停用、解绑或归属
不符的域名不会入选。全不可用时显示现有网络错误，恢复后可重试。

先部署包含 `/api/mobile/v1/domains` 的后端，再发布新 App。所有域名必须具备正确
DNS／HTTPS 证书并指向同一套后端、数据库和会话缓存；网关应保留公司 Host，不得
把 API 重定向成 H5 页面。SaaS 显示启用不代表证书和网络已经就绪。至少保留一个
App 已知的内置或缓存入口可用；若全部失效，App 无法凭空获知新增入口，需要恢复
一个旧入口或更新安装包。H5 仍同域访问，不参与 App 选线。

## macOS HBuilderX Node 编译故障（2026-09-29）

HBuilderX 使用 Bash login shell 查找 CLI 项目的 Node；仅配置 `.zshrc`
可能提示找不到 Node。当前电脑的 `.bash_profile` 已配置 Node 路径。

在 Apple Silicon 上运行 Intel 版 HBuilderX 5.26 时，本机验证：arm64 Node
22.23.1 和 22.12.0 加载该 IDE 的 `uni_helpers` 后会 SIGSEGV；普通命令行
编译没有加载 IDE 插件，成功不能代替编辑器编译验证。当前本机使用
`~/.local/hbuilderx-node/bin/node`：检测到 `UNI_HBUILDERX_PLUGINS` 或
`HX_Version` 时运行 HBuilderX 配套的 x64 Node，其余情况使用原生 Node 22.23.1。
没有修改 HBuilderX 插件，也没有禁用插件校验。

本机 node_modules 已补充与锁定依赖版本一致的 `@rollup/rollup-darwin-x64`
4.63.5 和 `@esbuild/darwin-x64` 0.20.2，并保留 arm64 版本。
重装 node_modules 后需重新安装相应架构的可选依赖；版本以当前锁文件为准。
更换 HBuilderX 架构后应重新检查运行时架构，并更新或移除该本机适配配置。

AppID 以公司配置为持久来源；HBuilderX 重新获取 AppID 后须同步
`mobile/companies/specpay.json` 的 `dcloudAppId`，避免 prepare 覆盖。

已使用 HBuilderX 5.26 CLI 的 `publish app --type appResource` 验证，
2026-09-29 15:59 显示编译成功、导出成功；产物为
`mobile/uni-app/unpackage/resources`。该验证没有提交云打包或生成签名 APK。

## Mandatory Android updates (2026-09-30)

The App checks the installed native `appVersionCode` at startup, foreground and
before API work (successful results are shared for at most 60 seconds). A newer
published version blocks consumer operations and presents Download update. Network,
missing release and invalid metadata errors block operations with Retry. H5 is unchanged.
Requests already dispatched are not cancelled or replayed by version checking.
The download opens the system browser; the user completes Android installation.

First deploy the backend and publish the signed APK **before** distributing this
update-aware App. Earlier installed Apps without this code cannot gain detection
remotely and must be updated once manually. Native resources compiling successfully
is not a signed APK or device installation test.

Each company has its own release selected by the active Host. Publish using the
company slug and the actual signed APK metadata (DCloud appid, not Android package
name). Current Spec Pay source is 2.3.58 / 2358. On the server:

```sh
cd /www/wwwroot/card
/www/server/php/84/bin/php artisan app:publish-android tenant-a --code=2358 --release-version=2.3.58 --appid=__UNI__GBE57092
```

Replace `tenant-a` with the deployed company's actual slug if different. Keep the
same Android package name and signing certificate for upgrade installation, and
confirm APK metadata in HBuilderX before publishing; this command does not inspect
Android signing certificates or parse APK manifest metadata. Each distinct APK must
increase versionCode; update `mobile/companies/specpay.json` before prepare/packaging
and confirm the generated manifest matches. Do not advertise an unsigned/test APK.

The command publishes version metadata and audit in the database. It no longer
copies an APK to the API server. The public GET `/api/mobile/v1/app-release` exposes
company release metadata without sessions; missing/invalid metadata returns 503.
Legacy private `storage/app/app-releases/<tenant UUID>/android.json` pointers remain
readable until an explicit publication replaces them in the database. The fixed
static download host must contain the matching signed APK before publication.


### 2026-10-02 Static distribution host and API routing

`zb33333.com` hosts the static APK only. It is removed from Spec Pay API seeds and
ignored even if present in an old cached/server domain list. Startup, foreground
and explicit update retries refresh the full company directory, select a verified
API origin, then fetch `/api/mobile/v1/app-release` there. Downloads open the fixed
`http://zb33333.com/specpay.apk`; upload the same signed release to that static
host. The API requires published company release metadata (Platform form or optional
CLI), but no local release artifact. A static APK upload alone does not publish metadata. Do not query the static host for app-release.
Recompile and cloud-package the native app; an H5 deployment cannot patch an
already installed APK. This supersedes the API-host download URL above.

### Online Android branding (2026-10-03)

`client prepare/build --platform app` now reads `/api/mobile/v1/bootstrap` from
configured company API seeds, verifies `tenant.slug` and a nonempty tenant ID,
then downloads the public configured `tenant.apkLogoUrl` without credentials. The
static APK host is never queried for configuration. Failed/unconfigured branding
stops native preparation before overwriting the manifest; stale local branding is
not a fallback. H5 builds do not fetch branding.

Upload the separate **APK Logo** in Platform company branding settings first.
It never falls back to the website/company Logo. The APK Logo is
contained without distortion on a white square for Android density icons, and
centered on white portrait splash images. Generated resources live under
`src/static/native-branding/`; the manifest receives `app-plus.distribute.icons`
and `app-plus.distribute.splashscreen` on every native preparation. A SHA-256
provenance file records the source API/company, never signed image URLs or secrets.
This uses DCloud's classic uni-app manifest schema:
https://uniapp.dcloud.net.cn/tutorial/app-manifest

Run `npm ci`, then `npm run client -- build --company specpay --mode release --platform app`.
Repackage with HBuilderX cloud packaging and install the resulting APK; existing
installed icons cannot be changed by editing server configuration. Android resource
compilation is not a signed APK or a device installation verification. iOS branding
is outside this Android change. Do not cloud-package stale output after a failed build.

### CLI 项目图标路径（2026-10-04 修复）

HBuilderX 云打包按 `mobile/uni-app` 项目根目录解析图标和启动图路径，
因此生成的 manifest 使用 `src/static/native-branding/...`，不能省略 `src/`。
`prepare` 和 `build --platform app` 均由 branding 脚本生成该路径。
导入完整 `mobile/uni-app` 项目；修改后关闭并重新打开打包窗口再提交。

### H5 Safari input and dialog compatibility (2026-10-04)

H5 shared form/login fields use real HTML inputs rather than uni-input's clipped
wrapper. Decimal amounts remain strings with `inputmode=decimal`; passwords use
native `type=password`. Native App builds retain the uni input control.
H5 dialogs teleport to body, lock background scrolling, and follow visualViewport
resize/scroll so the software keyboard and Safari toolbar do not hide the dialog.
Long content scrolls inside the dialog; closing restores the previous page position.
`tests/Browser/consumer-input-modal.mjs` checks Chromium and WebKit with offline
fixtures, including a tall transformed ancestor and simulated keyboard viewport.
This does not replace acceptance on the affected physical iPhone.
Publish rebuilt H5 JS/CSS and its matching entry to apply the browser fix; clear CDN
entry caches as appropriate. Updating only PHP or an APK does not update H5 browsers.

H5 的 uni-app 选择器整体使用层级 1100，高于筛选弹窗的 1000，避免滚轮和
确认按钮被弹窗遮罩挡住。`tests/Browser/consumer-picker-layer.mjs` 使用离线
每日数据验证 Chromium/WebKit 的触摸滑动、确认、取消及页面滚动锁恢复。
此修复仅需发布 H5 入口及配套资源，无需重新打包 APK。

### 身份认证图片缩略图与刷新（2026-10-04）

认证结果页使用 OSS `thumbnail` 展示策略（最长边 480px、JPEG、质量 75），固定小图框；点击才加载原图弹窗。缩略图失败依次尝试原图和服务器副本，每个地址一次，单次加载超过 15 秒也进入回退；全部失败显示图片不可用及刷新按钮。刷新重新尝试并通过只读认证查询更新签名地址。原图、认证记录、OCR 和补传状态不变。

同时部署后端及 H5；原生 App 需要重新打包。无需数据库迁移。离线验证：`tests/Feature/KycVerifiedDetailsTest.php`、`tests/Browser/consumer-kyc-images.mjs`（预览服务 5217，API 和图片完全模拟，不访问真实证件）。

### 存量报表双版本（2026-10-04）

启用的合伙人账号自动展示团队下级外部总入金减总出金（提现申请总额），排除本人，按本次公开行情统一折算 USDT。非合伙人展示原存量公式。部署后端、后台及 H5 后生效；原生 App 需重新打包。无需迁移或历史资金修正。汇率不可用显示估值不完整，原币金额保留；仅 USDT 的报表不依赖行情。

### Backend APK name (2026-10-06)

Platform → Company configuration → Brand and support now includes **APK name**
beside APK Logo. Save up to 60 characters; markup and control characters are rejected.
The company-scoped branding save retains its existing permission and audit controls.
Deploy the `add_apk_name_to_tenant_branding` migration and rebuilt admin assets first,
then explicitly configure the name for each company. Existing rows start unconfigured.
Native prepare/build reads `tenant.apkName` together with `tenant.apkLogoUrl` from the
verified company bootstrap; missing/invalid names stop packaging. The downloaded name
is written to generated company configuration and manifest, with no local-name fallback.
The company JSON `name` continues to configure H5. Website branding, package identifiers
and distribution filenames are independent. Name changes require rebuilding and installing
the APK; they do not rename already installed applications remotely.


### Platform Android release publication (2026-10-06, metadata only)

Platform → Company configuration → Branding includes **Android release**. Enter
only the exact version name, positive increasing version code and DCloud AppID
(`__UNI__…`, not the Android package name). Existing AppIDs are filled automatically
and cannot be changed. Confirm that the download site has the matching APK, then
publish. The form does not upload APKs; API servers do not read, copy or require one.
APK signing/embedded metadata and download availability are not independently verified.

Upload the signed APK only to `http://zb33333.com/specpay.apk`, before publishing its
metadata. Publishing a higher code activates the existing mandatory-update gate.
Installed clients using the fixed download host do not need rebuilding for this change.

Deploy PHP and rebuilt administration assets. Run migrations if the earlier
`tenant_android_releases` table has not been deployed yet; this revision adds no new
migration or large-upload requirements. Existing APK files are left untouched.
Retain the database and any legacy `storage/app/app-releases` metadata still in use.

Publication requires active Platform `tenant.manage` authority on the routed
company. The company row serializes web/CLI publication; revision checks reject
stale editors, changes require a strictly increasing version code and the original
AppID, and identical version metadata is a no-op even for legacy rows containing
an old artifact path. Metadata and immutable `ANDROID_RELEASE_PUBLISHED` actor/request
audit commit together; failures preserve the prior release.

API GET remains Host-scoped, credential-free and read-only. Legacy JSON metadata is
used only when no database publication exists. Missing metadata returns 503; missing
local APKs no longer block version checks. `downloadUrl` is the fixed distribution URL.
Installed static-download clients still validate `path`, so preserve an existing
valid path or generate a deterministic legacy-shaped compatibility identifier. That
identifier is neither an APK checksum nor a promise of an API-hosted download file.
Do not remove/change its shape until those installed clients are retired.

The optional CLI shares the same metadata-only publisher. It no longer requires an
APK argument; an old positional APK argument is accepted but ignored for script
compatibility. Do not run a pre-upgrade CLI publisher after deploying this version.
Offline coverage includes metadata-only publication without directories/files,
legacy reads, company isolation, audit rollback, revision/version protection and
Chromium/WebKit form behavior at 375/768/1440 px with no uploads or external traffic.


### Installed version in App settings (2026-10-06)

Native App → My account → Settings shows the installed app version and version code
from `uni.getAppBaseInfo()`, the same runtime source used for update comparisons.
Missing metadata displays Unavailable; server release metadata and generated company
configuration are never used as a substitute. H5 omits these native-only rows.
Rebuild/cloud-package and install the new APK to add this UI to existing devices.


### Shared Android/iOS update configuration (2026-10-07)

Platform company configuration → Branding → App release now publishes one shared
DCloud AppID, version name and monotonically increasing version code, plus required
Android download URL and iOS distribution-page URL. Both destinations accept HTTP(S)
without embedded credentials. Publication is metadata-only: no APK/IPA upload,
Apple signature check, remote download verification or server-side link fetch.
The existing tenant.manage permission, company lock, revision and atomic actor/request
audit apply. Every changed publication still requires a higher version code.

The historical `tenant_android_releases` JSON storage, editor route, DTO property and
audit action remain for compatibility; no migration is needed. Legacy releases retain
the fixed Android default and a null iOS destination without GET writes. The legacy
CLI preserves existing destinations. Public release JSON adds `androidDownloadUrl`
and `iosDistributionUrl`; `downloadUrl` aliases the configured Android URL. The
legacy APK-shaped `path` is only an installed-client compatibility identifier.

New native clients use the same company/AppID/version checks on Android and iOS.
An outdated Android opens its configured download URL; an outdated iOS opens its
configured distribution page. Missing/invalid destinations block an outdated client
with retry, never send iOS to an APK. A current client does not need a download link.
No certificate or signature inspection occurs during version checking. H5 download
links are unchanged by this native-update change.

Deploy backend and rebuilt admin assets, then configure both real destinations and
matching release metadata. Rebuild and sign/install native packages to adopt this
logic: existing Android packages with a hardcoded URL continue using the former
static download host. Keep that host usable during transition. Deploying backend
alone does not change already installed client code.


### Android permission reduction (2026-10-08)

The Huawei installer screenshot lists storage read/write, phone state/device identifiers
and camera. This is a declaration list, not proof all permissions have been granted.
DCloud cloud packaging adds default/module permissions independently of our short
`permissions` list; `custompermissions` does not reliably suppress SDK additions.
The persistent generator now uses `app-plus.distribute.android.excludePermissions`
(XML permission strings) to remove phone state/numbers/privileged phone state, audio
recording, APK installation permissions and ASUS device-ID access. Updates open the
browser, so this App does not need to install APKs itself. Both
`permissionPhoneState.request` and `permissionExternalStorage.request` are `none`.

Camera and existing image/storage module permissions remain for explicit document or
support photo selection and saving invitation posters, including older Android phones.
Older installers can still list storage read/write and camera; do not promise a
permission-free package or remove these blindly. Eliminating broad storage access
requires a separately verified system-picker/MediaStore implementation for the supported
OS/toolchain versions. No microphone/video recording is used by our image UI.

The current generated manifest is synchronized, but a new cloud-packaged APK and
real-device acceptance are still required. Inspect the final APK permissions, verify
startup does not request phone/storage access, and test camera, album selection,
poster saving, permission denial and browser update links. No final signed APK was
available to inspect in this change. Reference:
https://uniapp.dcloud.net.cn/tutorial/app-permission-android
https://uniapp.dcloud.net.cn/tutorial/app-nativeresource-android.html


### H5 automatic App download (2026-10-08)

The homepage/menu/footer now show Download app. Public company bootstrap includes
`appDownloads` from the same release metadata used for native updates. H5 chooses
Android download URL for Android and the iOS distribution page for iPhone/iPad
(including iPad desktop user agents). Desktop/unknown devices choose a platform.
Missing or invalid destinations show an unavailable message; iOS never falls back
to the Android APK. Links are HTTP(S), without credentials; no `download` attribute
is applied to distribution pages. Native apps still omit these H5-only links.
This supersedes the fixed H5 Android link rule above. Legacy Android release defaults
remain readable; configure the iOS destination in Platform before offering downloads.

H5 My account also inserts Download app immediately before Customer support, including for support agents. It shares the homepage platform-selection
action and published URLs. Native account menus retain their previous entries.

H5 download actions refresh company bootstrap before opening the chosen platform URL,
so a page left open across a Platform destination edit does not reuse its previous
address. Failed refreshes show a retry message without opening the cached destination.
Deploy rebuilt H5 assets and reload already-open pages to adopt this behavior.


### H5 登录语言（2026-10-08）

首次直接进入登录页默认英文，恢复登录／注册页右上角公司已启用语言的选择器。
手动选定语言继续通过原有服务端接口和公司作用域 Cookie 保存；注册页继承所选语言，
不再强制切回中文。已有用户或 Cookie 偏好仍由 bootstrap 按公司启用语言规则解析。
部署匹配的 `public/h5/index.html` 与全部新增哈希资源，保留旧资源供已打开页面使用。
网页壳 APK 读取线上 H5，无需为此重新打包；本次不改变旧原生业务 App 的中文规则。


## 2026-10-13 H5 bottom navigation responsiveness

Only H5 declares the assets/cards/account root pages as framework tabs. The existing
translated PageShell navigation stays visible; the framework bar is hidden. Root tab
switches reuse page instances, avoiding reLaunch disposal on every click. Code is warmed
on idle after sign-in without mounting pages or reading hidden account/financial data.
Before a first switch, the target module is awaited while the current page stays painted;
this is necessary because uni.switchTab itself hides the old page immediately. Pending
navigation is cancelled by newer navigation, changed scope or changed route. Deep links
with onLoad parameters retain their previous reLaunch behavior. Native tab routing is
unchanged and APP preprocessing excludes the new tabBar definition.

Cached page displays remain scoped to the current sign-in and are revalidated by the
existing authenticated HTTP reads. H5 asset responses have scope/generation/visibility
checks and clear on identity changes or authorization failures. Existing card/OTP/draft
clearing, logout and consumer foreground socket/reminder behavior are preserved. Code
warmup makes no page API requests, provisions no wallets and calls no card providers.

Chrome/WebKit synthetic checks delay both the first card JS chunk and all page data
responses, verify that the current shell remains visible, and switch six times without
refetching page chunks on the repeat pass. Measured local warm-switch times were 27–56 ms;
these are fixture measurements, not a guarantee of production network latency.
Verification and screenshots: artifacts/h5-tab-switching. Publish matching H5 index and
new hashed resources, retaining old hashes for already-open tabs. No migration or native
App rebuild is needed for this H5-only optimization.

## 2026-10-13 H5 return, refresh and field feedback

H5 keeps list positions in mounted page instances, scoped to the current company,
identity/session and full filter/page path; no personal data or form drafts are written
to persistent storage. Detail back buttons pop the original matching list when present,
retaining its query and pagination. Changed filters reset position; logout and scope
changes discard saved positions. Stock reports still clear content before fresh partner
eligibility checks.

Same-path financial history and message lists keep previously loaded content during
refresh and temporary failures, with Updating / Retry feedback. Account/assets refresh
uses the same lightweight feedback. Authorization/session/record errors discard old
content; redirects and stale or hidden responses cannot revive a previous user's data.
First loads retain existing skeleton/error screens.

H5 login, top-up, withdrawal, transfer, card application, financial password confirmation
and recipient inputs register their server field names. Errors appear inline with
aria-invalid/aria-describedby; unmatched errors remain in the summary. Explicit submits
locate the first visible invalid field, scrolling only the dialog body for teleported
modals. Late errors do not steal focus after another edit, and disabled fields are never
focused. Financial confirmation, stable request IDs, UNKNOWN recovery and provider rules
are unchanged.

Verification uses fully intercepted Chrome/WebKit fixtures only: filtered/paginated
message and withdrawal detail returns, delayed/failed refresh, 403 invalidation, logout,
login Enter, transfer validation, withdrawal 422 and card dialog errors. Existing tab
switching and compact card-opening browser checks also run. Evidence lives in
artifacts/h5-experience. Publish matching H5 index/new hashes and retain old assets for
open tabs; no backend API/schema changes or native installation is required for this
H5 publication.

2026-10-15 H5 多公司域名启动修复：116a.my 的 `/api/v1/bootstrap` 已正确返回 tenant-b，但旧 H5 在 session.bootstrap 将服务器公司 slug 与打包常量比较，错误抛出 403。H5 按当前同源域名由服务器解析公司，不要求与构建 tenantSlug 相同；原生 App 仍要求公司 slug 匹配且保留原有可信域名发现和凭据隔离。仅更新 H5 资源即可修复网页，无数据库迁移、域名重分配或服务端权限放宽。发布新入口及其依赖的全部静态资源后刷新页面。不要通过禁用服务器公司隔离或将其他域名的凭据复制过来解决。

## 2026-10-15 WebView line-switch identity barrier

Deploy matching H5 and rebuild/install the `mobile/webview-shell` App resources.
The shell restores only the verified company's encrypted remember cookie, waits for
native cookie commitment (up to three seconds), and verifies `/api/v1/bootstrap`
with that credential before opening the destination page. The response must match
the discovered company and expected signed-in/guest state. Credentials remain native
and HttpOnly; they never pass through remote JavaScript, titles, URLs or diagnostics.
The destination H5 must additionally emit its successful company/session bootstrap
signal before rendered content can dismiss the native welcome screen.

Network/timeout/recovery failures preserve secure credentials and show a retry path.
A temporarily empty native cookie store records pending recovery; it cannot restore
an older account from the vault until the trusted source cookie is available again.
A missing secure key requires explicit reauthentication, not plaintext fallback.
Only an explicit H5 logout signal clears the shell's persisted state. Server token
expiry/revocation still denies access; preserving a credential does not authenticate it.
Old windows, session signals and pending layout callbacks cannot reveal or clear a
new destination. H5 screen/unread 401 handling rechecks bootstrap before logout;
a failed recheck does not erase the session or replay a financial request.

Use `npm --prefix mobile/webview-shell run build:app` to compile resources, then
cloud-package and install with HBuilderX using the existing signing process.
This resource build does not produce a signed IPA/APK. H5 must also be rebuilt and
copied to public/h5. Existing installed shells need reinstall to receive the barrier.
Debug output includes only recovery stages; no credentials or identity values.

2026-10-19：原生 WebView 初次加载时 `getURL()` 可能返回 null。页面来源校验
和脱敏地址显示必须先验证字符串；无地址时保持未就绪、拒绝通过身份屏障，
继续等待有效 HTTPS 页面，避免启动页出现 `Cannot read property 'match' of null`。
本修复需要重新编译壳资源、云打包并安装；仅更新服务器 H5 不会修复已安装的壳。
同次排查补充：标题事件忽略空事件与非字符串标题；返回键异步查询只允许操作
发起查询的当前 WebView，重连或卸载后到达的旧回调直接忽略。


### 2026-10-20 document upload wait bounds

Consumer image metadata reads now stop after 15 seconds, and upload preparation
stops after 30 seconds. Upload transports have an independent once-only deadline
(including H5 local-file reads); native tasks are aborted when their callback does
not arrive. Late transport success cannot complete a timed-out operation or submit
its business request. Existing authenticated upload tickets, credential-free OSS
requests and business submission rules remain unchanged.

This bounds a reported H5 wait at 0/2 documents; it does not establish the cause of
the reported storage/network failure. Publish the matching H5 build and retain old
hashed assets. WebView shells consume the refreshed H5; standalone native resources
need their normal rebuild to receive this transport change. No migration is needed.
Offline validation: consumer-direct-upload tests and uni-app typecheck.

## 2026-10-21 H5 请求合并与网络刷新保护

浏览器与 Token WebView 共用请求层：仅合并同一公司、身份代次、语言、页面、路径及
参数的在途 GET；结束即清除，不保存响应。POST/上传保持独立且不自动重放。
响应回调仍逐订阅者交付。页面刷新入口复用当前加载，后台返回保留挂载内容；未读
更新不因仅 supportAgent 属性变化重复触发。服务端 JSON 控制器/DTO 接口不变。

前台请求断网、超时、5xx 或无效 JSON 共用一次异常弹窗；后台会话、未读、心跳、
WebSocket 配置、客服静默轮询与已读回执不触发。提交/上传异常提示结果未知、先核对。
点击刷新只重载当前同源文档一次，不重发提交、不删除 Token、不重新选择线路。
`sessionStorage` 仅保存固定的 pending 标记，不含 URL、账号、Token 或响应。
刷新后持续失败只显示非模态手动重试条，不再次自动弹窗或重载；关闭提示后同一故障
不重复打断。存储不可用时禁止程序刷新，允许用户使用浏览器手动恢复。
前台页面读取成功且没有更新的失败时解除保护，后台成功不解除；页面重载继续使用
现有原生 Token 注入、加密邮箱及身份/布局显示屏障。

本次不修改原生壳源码，无须为这些 H5 行为新增原生权限或改变凭证协议。发布新增
哈希资源后再原子替换 H5 入口，保留旧哈希及现有旧壳入口选择器；OSS 发布遵循
OSS_IMAGES.md。不得以本次构建覆盖其他尚未发布的原生签名包。真机重载后的安全
存储/显示屏障仍需安装现有匹配 Token 壳进行验收。无迁移或生产金融/供应商操作。

验证：新增/更新网络与页面生命周期测试通过；相关登录恢复、主页拆分、Token 与原生
显示屏障测试通过。全量 consumer 前端串行回归有 18 项失败，均能在修改前源码
对照中复现（包括旧测试桩、KYC、表单及原生重试测试）；未将其记为全量通过。
构建和类型检查结果另存本次交付目录；没有实机、线上断网或发布验收结论。

本地交付目录：`output/h5-network-recovery-20261021/`，含 H5 资源、原入口备份、
verification.json 与验证日志；同一构建已同步 `public/h5`，保留历史哈希文件。
Chrome 真实文档重载测试通过；实际编译的 H5 在持续 503 的隔离服务下，每个文档
仅一次 bootstrap，点击后只重载一次，路由保留，随后停留在手动重试条。
测试命令：`node tests/Browser/consumer-network-recovery.mjs`（Node 22、已安装 Chrome）。
本次仅更新本地资源，没有向生产或 OSS 发布，也没有生成/覆盖 APK、IPA。

## 2026-10-21 普通用户预支与合伙人下级预支

协调发布 `2026_10_21_100000_add_user_advances` 迁移、PHP、后台与 H5 资源。
普通用户充值可分类为预支，但普通用户 App/H5 不显示预支标签或日志；合伙人报表
新增下级预支扣项及分页来源，存量和参考分成同步扣减。身份/邀请关系按当前状态
读取，不搬迁历史日志或重算 Wallet/Ledger。保留旧哈希资源，入口最后替换。
编译 uni-app App 与 WebView shell 原生资源；签名 APK/IPA 仍需现有 HBuilderX
打包/安装流程，资源编译不能代表已完成签名或发布。本次不运行生产迁移、供应商
请求或生产资金测试；新增迁移保留不可变历史，后退使用协调的向前修复。

本地交付为 `output/user-advances-20261021/`，包含后台/H5/两套原生资源、旧 H5 入口
备份、390px 截图及验证日志。最终定向隔离回归 26 项通过、316 条断言；覆盖普通用户
正负预支、冲销/幂等、充值人工及自动分类互切、权限/公司/合伙人边界，以及报表/
列表/首页/来源/参考分成一致性。完整扩大回归因重复全库重建收敛为定向验证，未宣称
全量通过。类型检查和四类资源构建通过；保留原有大 bundle 提示。生产尚未发布，
也未执行生产迁移或生成签名安装包。
