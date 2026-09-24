# 阿里云证件 OCR

2026-09-24 用户批准大陆身份证和护照识别，替代原来上传合法图片就自动认证的规则。

实名认证表单选择证件类型；大陆身份证仅 CN 且正反面必传，护照仅资料页。
提交先校验权限、已有认证/待审核状态，后用私有图片二进制调用固定杭州端点，
不提供公开图片 URL。RecognizeIdcard 校验正面号码及校验位并识别背面签发机关/
有效期字段；中国内地及港澳台护照走 RecognizeChinesePassport，其他护照走
RecognizePassport。仅识别非空号码且与填写号码规范化后匹配才能存入新申请，
自动模式随即认证，手动模式进入审核。审批入口也校验加密 OCR 匹配证据。
接口异常、缺失字段、识别不匹配均不创建认证身份，允许用户修正后重提。

身份证背面字段识别不等于验证有效期，当前未做证件真伪、公安二要素或人脸核身。
不宣称 OCR 成功能证明本人或证件真实。姓名识别不作姓名核验，因为当前表单
没有姓名对照输入；护照签发国按用户选择调用接口，OCR 不提供全覆盖证件国籍核验。

服务独立读取 ALIYUN_OCR_ACCESS_KEY_ID / ALIYUN_OCR_ACCESS_KEY_SECRET；
KYC_OCR_DRIVER=aliyun。缺凭据或权限失败时关闭通过路径，不回退 Mock。
Mock 仅用于 local/testing 的显式配置。图片、证件号码和完整 OCR 响应不进日志，
证件沿用私有存储，加密保存 MATCH 证据，阿里云 RequestId 用于查请求。
自动审批仍保留身份账户数量限制、公司隔离、锁和审计，无 Ledger 操作。

部署先执行 2026_09_24_180000_allow_kyc_passports 迁移，再部署应用和前端。
迁移仅扩展证件类型及允许护照无背面，不修改既有身份、图片或审批结果。
阿里云开通个人证照识别，并授权 ocr:RecognizeIdcard、ocr:RecognizePassport、
ocr:RecognizeChinesePassport。配置独立 RAM 凭据后执行 config:cache 并重载 PHP。
未使用用户真实证件做线上测试；接入验收先使用授权测试图片核验实际返回。

官方接口：
- https://help.aliyun.com/zh/ocr/developer-reference/api-ocr-api-2021-07-07-recognizeidcard
- https://help.aliyun.com/zh/ocr/developer-reference/api-ocr-api-2021-07-07-recognizepassport
- https://help.aliyun.com/zh/ocr/developer-reference/api-ocr-api-2021-07-07-recognizechinesepassport
