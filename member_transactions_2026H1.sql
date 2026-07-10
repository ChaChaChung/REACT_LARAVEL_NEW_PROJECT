-- ============================================================
-- 會員交易紀錄報表 — 2026 年 1~7 月
-- Transactions 對應 users.line_display_name
-- ============================================================
-- 用法（在遠端伺服器上，MySQL 跑在 Docker 內）：
--   docker exec -i <mysql容器名> mysql -udocker2 -p'HLzjO86mLUxBBW0' l12v3 < member_transactions_2026H1.sql
-- 或進到容器裡：
--   docker exec -it <mysql容器名> mysql -udocker2 -p'HLzjO86mLUxBBW0' l12v3
--   然後貼上下面的 SQL
-- ============================================================

-- STEP 1) 先確認兩張表實際欄位（若欄位名和下方查詢不同，把這段輸出貼回給我）
SHOW COLUMNS FROM transactions;
SHOW COLUMNS FROM users;

-- ============================================================
-- STEP 2) 報表主查詢
--   假設： transactions.user_id  ->  users.id
--          時間欄位為 created_at
--   時間範圍：2026-01-01 00:00:00 ~ 2026-07-31 23:59:59
-- ============================================================
SELECT
    u.line_display_name                         AS 會員,
    t.id                                         AS 交易ID,
    t.user_id                                    AS 會員ID,
    t.amount                                     AS 金額,          -- 若無此欄位請改成實際欄位
    t.type                                       AS 類型,          -- 若無此欄位可移除
    t.status                                     AS 狀態,          -- 若無此欄位可移除
    t.created_at                                 AS 交易時間
FROM transactions t
JOIN users u ON u.id = t.user_id
WHERE t.created_at >= '2026-01-01 00:00:00'
  AND t.created_at <  '2026-08-01 00:00:00'
ORDER BY u.line_display_name, t.created_at;

-- ============================================================
-- STEP 3)（選用）每位會員彙總：筆數與金額合計
-- ============================================================
SELECT
    u.line_display_name                          AS 會員,
    COUNT(*)                                      AS 交易筆數,
    SUM(t.amount)                                 AS 金額合計
FROM transactions t
JOIN users u ON u.id = t.user_id
WHERE t.created_at >= '2026-01-01 00:00:00'
  AND t.created_at <  '2026-08-01 00:00:00'
GROUP BY u.line_display_name
ORDER BY 金額合計 DESC;
