-- Silent refund for overlay-only compounds (no RaceCoin.mint, no on-chain stake).
-- Preferred write path on the server:
--   php artisan income:refund-failed-virtual-compounds --dry-run
--   php artisan income:refund-failed-virtual-compounds
-- This SQL is the same refund if artisan cannot run. Idempotent via idempotency_key.

START TRANSACTION;

-- 1) Who gets refunded (preview)
SELECT iwt.id, iwt.user_id, u.member_number, iwt.amount, iwt.created_at,
       JSON_UNQUOTE(JSON_EXTRACT(iwt.metadata, '$.phase')) AS phase
FROM income_wallet_transactions iwt
JOIN users u ON u.id = iwt.user_id
WHERE iwt.type = 'compound'
  AND iwt.direction = 'debit'
  AND iwt.status = 'posted'
  AND JSON_UNQUOTE(JSON_EXTRACT(iwt.metadata, '$.phase')) = 'compounded_to_stake'
  AND IFNULL(JSON_UNQUOTE(JSON_EXTRACT(iwt.metadata, '$.mint_tx_hash')), '') = ''
  AND IFNULL(JSON_UNQUOTE(JSON_EXTRACT(iwt.metadata, '$.on_chain_required')), 'true') IN ('false', '0');

-- 2) Credit virtual wallet (before/after from current user_wallets.virtual_balance_usd)
INSERT INTO income_wallet_transactions (
    user_id, wallet_address, type, source_reference, amount,
    balance_before_usd, balance_after_usd, asset, direction, status,
    idempotency_key, metadata, created_at, updated_at
)
SELECT
    iwt.user_id,
    iwt.wallet_address,
    'compound_refund',
    iwt.source_reference,
    iwt.amount,
    IFNULL(uw.virtual_balance_usd, 0),
    IFNULL(uw.virtual_balance_usd, 0) + iwt.amount,
    'USDT',
    'credit',
    'posted',
    CONCAT('failed_overlay_refund:iwt:', iwt.id),
    JSON_OBJECT(
        'compound_debit_id', iwt.id,
        'refund_reason', 'overlay_without_onchain_mint',
        'silent', true
    ),
    NOW(),
    NOW()
FROM income_wallet_transactions iwt
LEFT JOIN user_wallets uw ON uw.user_id = iwt.user_id
WHERE iwt.type = 'compound'
  AND iwt.direction = 'debit'
  AND iwt.status = 'posted'
  AND JSON_UNQUOTE(JSON_EXTRACT(iwt.metadata, '$.phase')) = 'compounded_to_stake'
  AND IFNULL(JSON_UNQUOTE(JSON_EXTRACT(iwt.metadata, '$.mint_tx_hash')), '') = ''
  AND IFNULL(JSON_UNQUOTE(JSON_EXTRACT(iwt.metadata, '$.on_chain_required')), 'true') IN ('false', '0')
  AND NOT EXISTS (
      SELECT 1 FROM income_wallet_transactions r
      WHERE r.user_id = iwt.user_id
        AND r.idempotency_key = CONCAT('failed_overlay_refund:iwt:', iwt.id)
  );

-- 3) Set wallet snapshot from the refund row just inserted (do not add twice)
UPDATE user_wallets uw
INNER JOIN income_wallet_transactions r
    ON r.user_id = uw.user_id
   AND r.type = 'compound_refund'
   AND r.idempotency_key LIKE 'failed_overlay_refund:iwt:%'
INNER JOIN income_wallet_transactions iwt
    ON iwt.id = CAST(SUBSTRING(r.idempotency_key, 28) AS UNSIGNED)
   AND JSON_UNQUOTE(JSON_EXTRACT(iwt.metadata, '$.phase')) = 'compounded_to_stake'
SET uw.virtual_balance_usd = r.balance_after_usd;

-- 4) Mark original compound rows refunded
UPDATE income_wallet_transactions iwt
SET metadata = JSON_SET(
        IFNULL(metadata, JSON_OBJECT()),
        '$.phase', 'refunded',
        '$.refund_reason', 'overlay_without_onchain_mint'
    ),
    updated_at = NOW()
WHERE iwt.type = 'compound'
  AND JSON_UNQUOTE(JSON_EXTRACT(iwt.metadata, '$.phase')) = 'compounded_to_stake'
  AND IFNULL(JSON_UNQUOTE(JSON_EXTRACT(iwt.metadata, '$.mint_tx_hash')), '') = ''
  AND EXISTS (
      SELECT 1 FROM income_wallet_transactions r
      WHERE r.idempotency_key = CONCAT('failed_overlay_refund:iwt:', iwt.id)
  );

-- 5) Remove Laravel overlay so daily claim is not inflated
DELETE vsc
FROM virtual_stake_compounds vsc
INNER JOIN income_wallet_transactions iwt ON iwt.id = vsc.income_wallet_transaction_id
WHERE iwt.type = 'compound'
  AND JSON_UNQUOTE(JSON_EXTRACT(iwt.metadata, '$.phase')) = 'refunded'
  AND JSON_UNQUOTE(JSON_EXTRACT(iwt.metadata, '$.refund_reason')) = 'overlay_without_onchain_mint';

COMMIT;
