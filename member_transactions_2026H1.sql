-- ============================================================
-- 會員交易紀錄報表 — 2026 年 1~7 月（含中文對照）
-- transactions 對應 user.line_display_name
-- tran_type / coin 依後端語言檔 transactions.trans_types 轉中文
-- ============================================================
-- 建議執行方式（把本檔放到伺服器後）：
--   docker exec -i l12v3_mysql mysql --default-character-set=utf8mb4 \
--       -udocker2 -p'HLzjO86mLUxBBW0' l12v3 < member_transactions_2026H1.sql
-- 注意：before / after / value 是保留字，需加反引號 ``。
-- ============================================================

-- ------------------------------------------------------------
-- 1) 明細：每一筆交易 → 會員名 + 中文類型 + 中文幣別
-- ------------------------------------------------------------
SELECT
    u.line_display_name AS member,
    t.id                AS tx_id,
    t.user_id           AS user_id,
    CASE t.coin
        WHEN 'POINT'        THEN '金幣'
        WHEN 'MU_POINT'     THEN '銀幣'
        WHEN 'ROOM_CARD'    THEN '房卡'
        WHEN 'MU_ROOM_CARD' THEN '銀幣房卡'
        ELSE t.coin
    END                 AS coin_name,
    t.tran_type         AS tran_type,
    CASE t.tran_type
        WHEN 101 THEN '系統重製'
        WHEN 111 THEN '公會抽水收入'
        WHEN 201 THEN '管理員上分'
        WHEN 202 THEN '管理員下分'
        WHEN 203 THEN '優惠'
        WHEN 301 THEN '買入籌碼'
        WHEN 302 THEN '兌現籌碼'
        WHEN 303 THEN 'MyCard儲值'
        WHEN 304 THEN '金幣轉帳'
        WHEN 305 THEN '購買道具'
        WHEN 306 THEN '使用道具'
        WHEN 307 THEN '送出禮物'
        WHEN 308 THEN '收到禮物'
        WHEN 309 THEN '返水領取'
        WHEN 310 THEN 'GASH儲值'
        WHEN 311 THEN '報名錦標賽'
        WHEN 312 THEN '錦標賽獎金'
        WHEN 313 THEN '轉點手續費'
        WHEN 314 THEN '贈禮手續費'
        WHEN 315 THEN '遊戲幣入帳'
        WHEN 316 THEN '轉帳/贈禮 退回'
        WHEN 317 THEN 'VIP升級'
        WHEN 318 THEN '退回錦標賽費用'
        WHEN 319 THEN '取消報名錦標賽'
        WHEN 320 THEN 'Jackpot 獎金'
        WHEN 321 THEN '簽到獎勵'
        WHEN 322 THEN '任務獎勵'
        WHEN 323 THEN '儲值贈點'
        WHEN 324 THEN '房卡獲得'
        WHEN 325 THEN '房卡扣除'
        WHEN 326 THEN '房卡預扣退回'
        WHEN 327 THEN '房卡預扣'
        WHEN 328 THEN '房卡預扣沖正'
        WHEN 329 THEN '道具退回'
        WHEN 330 THEN '錦標賽保底獎金扣除'
        WHEN 331 THEN '錦標賽保底獎金退回'
        WHEN 332 THEN '公會轉帳'
        WHEN 333 THEN '公會轉帳入帳'
        WHEN 334 THEN '公會扣款'
        WHEN 335 THEN '公會扣款入帳'
        WHEN 336 THEN '退出/踢出公會 銀幣重置歸零'
        WHEN 401 THEN '外接上分'
        WHEN 402 THEN '外接下分'
        ELSE CONCAT('未知(', t.tran_type, ')')
    END                 AS tran_type_name,
    t.balance_type      AS balance_type,
    t.`value`           AS amount,
    t.`before`          AS bal_before,
    t.`after`           AS bal_after,
    t.trade_id          AS trade_id,
    t.created_at        AS created_at
FROM transactions t
JOIN user u ON u.id = t.user_id
WHERE t.created_at >= '2026-01-01 00:00:00'
  AND t.created_at <  '2026-08-01 00:00:00'
ORDER BY u.line_display_name, t.created_at;

-- ------------------------------------------------------------
-- 2) 彙總：每位會員 × 交易類型（中文） × 幣別
-- ------------------------------------------------------------
SELECT
    u.line_display_name AS member,
    CASE t.tran_type
        WHEN 101 THEN '系統重製'      WHEN 111 THEN '公會抽水收入'
        WHEN 201 THEN '管理員上分'    WHEN 202 THEN '管理員下分'
        WHEN 203 THEN '優惠'          WHEN 301 THEN '買入籌碼'
        WHEN 302 THEN '兌現籌碼'      WHEN 303 THEN 'MyCard儲值'
        WHEN 304 THEN '金幣轉帳'      WHEN 305 THEN '購買道具'
        WHEN 306 THEN '使用道具'      WHEN 307 THEN '送出禮物'
        WHEN 308 THEN '收到禮物'      WHEN 309 THEN '返水領取'
        WHEN 310 THEN 'GASH儲值'      WHEN 311 THEN '報名錦標賽'
        WHEN 312 THEN '錦標賽獎金'    WHEN 313 THEN '轉點手續費'
        WHEN 314 THEN '贈禮手續費'    WHEN 315 THEN '遊戲幣入帳'
        WHEN 316 THEN '轉帳/贈禮 退回' WHEN 317 THEN 'VIP升級'
        WHEN 318 THEN '退回錦標賽費用' WHEN 319 THEN '取消報名錦標賽'
        WHEN 320 THEN 'Jackpot 獎金'  WHEN 321 THEN '簽到獎勵'
        WHEN 322 THEN '任務獎勵'      WHEN 323 THEN '儲值贈點'
        WHEN 324 THEN '房卡獲得'      WHEN 325 THEN '房卡扣除'
        WHEN 326 THEN '房卡預扣退回'  WHEN 327 THEN '房卡預扣'
        WHEN 328 THEN '房卡預扣沖正'  WHEN 329 THEN '道具退回'
        WHEN 330 THEN '錦標賽保底獎金扣除' WHEN 331 THEN '錦標賽保底獎金退回'
        WHEN 332 THEN '公會轉帳'      WHEN 333 THEN '公會轉帳入帳'
        WHEN 334 THEN '公會扣款'      WHEN 335 THEN '公會扣款入帳'
        WHEN 336 THEN '退出/踢出公會 銀幣重置歸零'
        WHEN 401 THEN '外接上分'      WHEN 402 THEN '外接下分'
        ELSE CONCAT('未知(', t.tran_type, ')')
    END                 AS tran_type_name,
    CASE t.coin
        WHEN 'POINT' THEN '金幣' WHEN 'MU_POINT' THEN '銀幣'
        WHEN 'ROOM_CARD' THEN '房卡' WHEN 'MU_ROOM_CARD' THEN '銀幣房卡'
        ELSE t.coin
    END                 AS coin_name,
    COUNT(*)            AS tx_count,
    SUM(t.`value`)      AS amount_sum,
    MIN(t.created_at)   AS first_time,
    MAX(t.created_at)   AS last_time
FROM transactions t
JOIN user u ON u.id = t.user_id
WHERE t.created_at >= '2026-01-01 00:00:00'
  AND t.created_at <  '2026-08-01 00:00:00'
GROUP BY u.line_display_name, tran_type_name, coin_name
ORDER BY member, tran_type_name, coin_name;
