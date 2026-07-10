-- ============================================================
-- 會員交易紀錄報表 — 2026 年 1~7 月
-- transactions 對應 users.line_display_name
-- ============================================================
-- 用法（遠端伺服器，MySQL 跑在 Docker 內）：
--   docker exec -i <mysql容器名> mysql -udocker2 -p'HLzjO86mLUxBBW0' l12v3 < member_transactions_2026H1.sql
-- 注意：before / after / value 是 MySQL 保留字，必須加反引號 ``。
-- ============================================================

-- ------------------------------------------------------------
-- 1) 明細：每一筆交易，對應會員 LINE 顯示名稱
--    時間範圍 2026-01-01 00:00:00 ~ 2026-07-31 23:59:59
-- ------------------------------------------------------------
SELECT
    u.line_display_name        AS 會員,
    t.id                       AS 交易ID,
    t.user_id                  AS 會員ID,
    t.coin                     AS 幣別,
    t.tran_type                AS 交易類型,
    t.balance_type             AS 帳戶類型,
    t.`value`                  AS 變動金額,
    t.`before`                 AS 變動前餘額,
    t.`after`                  AS 變動後餘額,
    t.trade_id                 AS 交易單號,
    t.created_at               AS 交易時間
FROM transactions t
JOIN users u ON u.id = t.user_id
WHERE t.created_at >= '2026-01-01 00:00:00'
  AND t.created_at <  '2026-08-01 00:00:00'
ORDER BY u.line_display_name, t.created_at;

-- ------------------------------------------------------------
-- 2) 彙總：每位會員 × 幣別，交易筆數與變動金額合計
-- ------------------------------------------------------------
SELECT
    u.line_display_name        AS 會員,
    t.coin                     AS 幣別,
    COUNT(*)                   AS 交易筆數,
    SUM(t.`value`)             AS 變動金額合計
FROM transactions t
JOIN users u ON u.id = t.user_id
WHERE t.created_at >= '2026-01-01 00:00:00'
  AND t.created_at <  '2026-08-01 00:00:00'
GROUP BY u.line_display_name, t.coin
ORDER BY 會員, 幣別;

-- ------------------------------------------------------------
-- 3) 彙總：每位會員 × 月份 × 幣別，觀察 1~7 月各月走勢
-- ------------------------------------------------------------
SELECT
    u.line_display_name              AS 會員,
    DATE_FORMAT(t.created_at,'%Y-%m') AS 月份,
    t.coin                           AS 幣別,
    COUNT(*)                         AS 交易筆數,
    SUM(t.`value`)                   AS 變動金額合計
FROM transactions t
JOIN users u ON u.id = t.user_id
WHERE t.created_at >= '2026-01-01 00:00:00'
  AND t.created_at <  '2026-08-01 00:00:00'
GROUP BY u.line_display_name, 月份, t.coin
ORDER BY 會員, 月份, 幣別;
