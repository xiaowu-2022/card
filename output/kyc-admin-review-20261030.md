# 后台身份认证审核修复资源包（2026-10-30）

`kyc-admin-review-20261030.tar.gz` 包含本次匹配的后台 `public/build` 编译资源。
PHP 源码随同一次 Git 提交交付，需要一起更新；本次没有新增迁移，H5 和原生壳不变。
当前失败申请仍可人工批准或拒绝；旧记录有后续申请时提供明确的当前申请入口。
管理端审核错误使用独立的管理员文案，不提示管理员联系客服。

SHA-256：`b9a658144073388ac1171496604ad9338f135488c2a233ef4c3e8b77643cb008`

在更新了对应 PHP 源码的项目根目录执行以下资源安装命令。先加入新资源，
最后原子替换构建清单；保留服务器原有旧哈希资源：

```sh
mkdir -p public/build/assets
tar -xzf output/kyc-admin-review-20261030.tar.gz public/build/assets
tar -xOf output/kyc-admin-review-20261030.tar.gz public/build/manifest.json > public/build/manifest.json.next
mv public/build/manifest.json.next public/build/manifest.json
```

这些命令仅安装静态资源，不执行迁移、OCR、财务或业务测试。更新后刷新后台页面。
