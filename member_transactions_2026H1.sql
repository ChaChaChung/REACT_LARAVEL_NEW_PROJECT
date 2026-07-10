-- ============================================================
-- 會員交易紀錄報表 — 2026 年 1~7 月
-- transactions 對應 users.line_display_name
-- ============================================================
-- 用法（遠端伺服器，MySQL 在 Docker，容器名 l12v3_mysql）：
--   docker exec -i l12v3_mysql mysql --default-character-set=utf8mb4 \
--       -udocker2 -p'HLzjO86mLUxBBW0' l12v3 < member_transactions_2026H1.sql
--
-- 注意事項：
--   1) before / after / value 是 MySQL 保留字，需加反引號 ``。
--   2) 欄位別名一律用「英文」——中文別名經手機終端機/SSH 傳輸時常被
--      編碼破壞成 ??，導致 ERROR 1064 語法錯誤。資料值（中文會員名）不受影響。
--   3) 讀取時加 --default-character-set=utf8mb4，確保中文會員名正確顯示。
-- ============================================================

-- ------------------------------------------------------------
-- 1) 明細：每一筆交易，對應會員 LINE 顯示名稱
--    時間範圍 2026-01-01 00:00:00 ~ 2026-07-31 23:59:59
-- ------------------------------------------------------------
SELECT
    u.line_display_name        AS member,
    t.id                       AS tx_id,
    t.user_id                  AS user_id,
    t.coin                     AS coin,
    t.tran_type                AS tran_type,
    t.balance_type             AS balance_type,
    t.`value`                  AS amount,
    t.`before`                 AS bal_before,
    t.`after`                  AS bal_after,
    t.trade_id                 AS trade_id,
    t.created_at               AS created_at
FROM transactions t
JOIN users u ON u.id = t.user_id
WHERE t.created_at >= '2026-01-01 00:00:00'
  AND t.created_at <  '2026-08-01 00:00:00'
ORDER BY u.line_display_name, t.created_at;

-- ------------------------------------------------------------
-- 2) 彙總：每位會員 × 幣別，交易筆數與變動金額合計
-- ------------------------------------------------------------
SELECT
    u.line_display_name        AS member,
    t.coin                     AS coin,
    COUNT(*)                   AS tx_count,
    SUM(t.`value`)             AS amount_sum
FROM transactions t
JOIN users u ON u.id = t.user_id
WHERE t.created_at >= '2026-01-01 00:00:00'
  AND t.created_at <  '2026-08-01 00:00:00'
GROUP BY u.line_display_name, t.coin
ORDER BY member, coin;

-- ------------------------------------------------------------
-- 3) 彙總：每位會員 × 月份 × 幣別，觀察 1~7 月各月走勢
-- ------------------------------------------------------------
SELECT
    u.line_display_name               AS member,
    DATE_FORMAT(t.created_at,'%Y-%m') AS ym,
    t.coin                            AS coin,
    COUNT(*)                          AS tx_count,
    SUM(t.`value`)                    AS amount_sum
FROM transactions t
JOIN users u ON u.id = t.user_id
WHERE t.created_at >= '2026-01-01 00:00:00'
  AND t.created_at <  '2026-08-01 00:00:00'
GROUP BY u.line_display_name, ym, t.coin
ORDER BY member, ym, coin;
