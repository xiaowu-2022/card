# 迁移文件基线（2026-09-15）

当前共 59 个文件：原 58 项保留，新增 1 项部署兼容迁移。文件名和内容均保留；SHA-256 见 `migrations.sha256.json`。后续新增迁移时更新清单，不重写已执行迁移。

当前 `card_mock` 已执行全部 58 项；以目标数据库的 `migrations` 表为准，禁止将本地执行状态直接写入其他库。

| 顺序 | 迁移文件 |
| --- | --- |
| 1 | `2026_09_08_000100_create_tenant_foundation_tables.php` |
| 2 | `2026_09_08_000200_create_admin_rbac_tables.php` |
| 3 | `2026_09_08_000300_create_audit_logs_table.php` |
| 4 | `2026_09_09_000100_extend_admin_authentication_for_phase_one.php` |
| 5 | `2026_09_09_000200_create_end_user_authentication_tables.php` |
| 6 | `2026_09_09_000300_create_kyc_tables.php` |
| 7 | `2026_09_09_000400_reprotect_kyc_sensitive_data.php` |
| 8 | `2026_09_09_000410_bound_tenant_kyc_identity_limit.php` |
| 9 | `2026_09_09_000500_create_wallet_ledger_core.php` |
| 10 | `2026_09_09_000510_harden_wallet_ledger_integrity.php` |
| 11 | `2026_09_10_000600_create_wallet_topup_payment_tables.php` |
| 12 | `2026_09_10_000610_harden_payment_settlement_integrity.php` |
| 13 | `2026_09_10_000700_create_usdt_trc20_withdrawal_tables.php` |
| 14 | `2026_09_10_000800_add_trc20_shared_address_fields_to_wallet_topup_orders.php` |
| 15 | `2026_09_10_000810_preserve_unresolved_trc20_topup_reservations.php` |
| 16 | `2026_09_10_000900_create_card_product_tables.php` |
| 17 | `2026_09_10_001000_create_cardholder_and_card_issue_tables.php` |
| 18 | `2026_09_10_001100_scope_cardholder_materials_to_each_card.php` |
| 19 | `2026_09_11_000100_create_tenant_articles_table.php` |
| 20 | `2026_09_11_000200_add_public_account_ids_to_users.php` |
| 21 | `2026_09_11_000300_create_tenant_sms_settings.php` |
| 22 | `2026_09_11_000400_create_tenant_email_settings.php` |
| 23 | `2026_09_11_000500_create_card_management_tables.php` |
| 24 | `2026_09_11_000600_create_promotion_foundation.php` |
| 25 | `2026_09_11_000700_create_commission_accounting.php` |
| 26 | `2026_09_11_000800_create_security_deposit_lifecycle.php` |
| 27 | `2026_09_11_000900_harden_deposit_refund_evidence.php` |
| 28 | `2026_09_11_001000_add_automatic_kyc_policy.php` |
| 29 | `2026_09_11_001100_use_sequential_promotion_invitation_codes.php` |
| 30 | `2026_09_11_001200_create_wallet_transfers.php` |
| 31 | `2026_09_11_001300_create_support_chat.php` |
| 32 | `2026_09_11_001400_create_user_contact_changes.php` |
| 33 | `2026_09_11_001500_add_user_session_version.php` |
| 34 | `2026_09_11_001600_create_user_password_resets.php` |
| 35 | `2026_09_13_000100_create_local_card_simulator_states.php` |
| 36 | `2026_09_13_000200_add_platform_topup_verification.php` |
| 37 | `2026_09_13_000300_seal_platform_topup_verification_requests.php` |
| 38 | `2026_09_13_000400_normalize_unused_usd_wallets_to_usdt.php` |
| 39 | `2026_09_13_000500_add_fixed_withdrawal_fees.php` |
| 40 | `2026_09_13_000600_add_platform_topup_confirmation.php` |
| 41 | `2026_09_13_000700_create_card_provider_references.php` |
| 42 | `2026_09_13_000800_bind_card_products_to_card_providers.php` |
| 43 | `2026_09_13_000900_add_local_mock_card_merchant.php` |
| 44 | `2026_09_13_001000_allow_unissued_cardholder_material_revisions.php` |
| 45 | `2026_09_14_000100_add_company_deposit_refund_wait_days.php` |
| 46 | `2026_09_14_000200_add_timed_deposit_refunds.php` |
| 47 | `2026_09_14_000300_add_card_provider_reporting_connection.php` |
| 48 | `2026_09_14_000400_platform_card_opening_fees.php` |
| 49 | `2026_09_14_000500_photonpay_sandbox_issuing.php` |
| 50 | `2026_09_14_000600_sandbox_holder_retry_applications.php` |
| 51 | `2026_09_14_000700_archive_card_product_catalog.php` |
| 52 | `2026_09_14_000800_release_archived_card_product_bins.php` |
| 53 | `2026_09_14_000900_archive_closed_user_cards.php` |
| 54 | `2026_09_14_001000_create_platform_kyc_settings.php` |
| 55 | `2026_09_14_001100_enable_company_wallet_operations.php` |
| 56 | `2026_09_14_001200_create_platform_notification_profiles.php` |
| 57 | `2026_09_14_001300_allow_unassigned_platform_domains.php` |
| 58 | `2026_09_15_120000_preserve_card_transaction_observation_precision.php` |
| 59 | `2026_09_15_140000_preserve_merchant_routing_on_unified_deployment.php` |

## 执行与审查规则

- 全量恢复包含 `migrations` 表、序列、函数、索引和触发器；恢复后只运行未执行的迁移。旧备份恢复后先为 58 项；本次 `migrate --force` 执行第 59 项部署兼容迁移。
- 不使用 `migrate:fresh`、`migrate:refresh`、`migrate:reset`、`schema:dump --prune` 或手工补写迁移状态。不能将 58 个文件合成一个文件再部署旧库。
- PostgreSQL 当前版本为 18；先在相同大版本恢复演练。不可用 SQLite/MySQL 替换 PostgreSQL 约束与延迟触发器。
- 有旧版本数据库时，在隔离副本验证真实迁移；不要把 `migrate --pretend` 当无副作用审查器，数据迁移内部可能包含非 SQL 副作用。
- 多个迁移的 `down()` 不可逆或明确拒绝回退。恢复采用向前修复，或在完全停止写入、核对外部结算后恢复整套备份，不盲目执行 `migrate:rollback`。

## 需特别检查的数据迁移

| 文件片段 | 数据影响与部署要求 |
| --- | --- |
| `000400_reprotect_kyc_sensitive_data` | 重加密旧 KYC 数据并重新计算身份索引；旧 APP_KEY 与持久 KYC 密钥必须准确配套。已执行后不重复。 |
| `001100_use_sequential_promotion_invitation_codes` | 一次性替换邀请码，保留别名和关系；必须同时恢复序列、计数器及别名表，不能重置计数器。 |
| `000400_normalize_unused_usd_wallets_to_usdt` | 独占锁下仅处理无历史且余额为零的 USD 账户。有资金/历史时按设计失败，不可绕过检查或改写账本。 |
| `000900_add_local_mock_card_merchant` | 增加模拟商户的运行环境约束。模拟数据不能通过改数据库名转成生产数据。 |
| `000500_photonpay_sandbox_issuing`、`000600_sandbox_holder_retry_applications` | 包含沙箱来源与使用约束；保留历史，不能去除标记以启用正式接口。 |
| `000700_archive_card_product_catalog`、`000900_archive_closed_user_cards` | 增加归档字段，并非删除原产品、卡片或订单。 |
| `001100_enable_company_wallet_operations` | 将历史充值/提现开关设为启用。对尚未执行的旧库，演练后由 SaaS 检查最终公司开关；不默认具备真实资金运营条件。 |
| `001200_create_platform_notification_profiles` | 将公司通知设置复制为平台配置并建立关联；需要原 APP_KEY 解密历史凭据，部署后核对公司范围与启用状态。 |
| `120000_preserve_card_transaction_observation_precision` | 提升流水首次收录时间精度，保留原值，不从外部时间重新填充历史。 |

其他资金相关迁移包含不可变账本、归属和幂等约束；须完整恢复，不能使用禁用触发器、删外键或余额覆盖来“修复”导入失败。

## 统一部署新增项

第 59 项仅替换三个触发器函数，解除数据库名字限制，保留商户绑定、连接身份和持卡人归属保护。无数据行、余额或历史时间修改。当前旧备份仍为 58 项，恢复程序随后执行新增项。
