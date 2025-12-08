# GASH 儲值系統

## 簡介

整合 GASH 第三方支付的儲值系統，支援線上儲值、訂單查詢、自動請款功能。

---

## 環境設定

在 `.env` 檔案加入以下設定：

```env
GASH_API_URL=https://stage-api.eg.gashplus.com/CP_Module // 測試機
GASH_API_URL=https://api.eg.gashplus.com/CP_Module       // 正式機
GASH_CID=C013910004270
GASH_TRANS_PWD=S34l98Sm
GASH_TRANS_KEY_I=qjuCDtHEJQLEFl1VhPf8IKSpu24ByWGK
GASH_TRANS_KEY_II=FB3Wx4FNrBE=
```

---

## 檔案結構

```
backend/
└── app/
    ├── Http/
    │   └── Controllers/
    │       └── GashController.php       # API 控制器
    ├── Services/
    │   └── GashService.php              # 業務邏輯
    └── Tools/
        └── Gash/
            ├── Trans.php                # 交易處理工具
            └── Cryptography.php         # 加解密工具
```

---

## API 端點

### 1. 發起儲值
```
POST /api/gash/deposit
```

**參數:**
```json
{
  "amount": 100,
  "paidType": "COPGAM09"
}
```

**回應:**
```json
{
  "error": "SUCCESS",
  "data": {
    "form_data": "base64_encoded_data",
    "coid": "17339123451234",
    "action_url": "https://stage-api.eg.gashplus.com/CP_Module/order.aspx"
  }
}
```

### 2. 交易回傳
```
POST /api/gash/return
```
GASH 會將交易結果導回此端點，自動驗證並請款。

### 3. 主動通知
```
POST /api/gash/callback
```
GASH 服務器主動通知交易狀態。

### 4. 查詢訂單
```
GET /api/gash/checkOrder/{coid}
```

---

## 使用流程

1. 前端呼叫 `/api/gash/deposit` 取得交易資料
2. 動態建立表單提交到 GASH
3. 使用者在 GASH 頁面完成付款
4. GASH 回傳交易結果到 `/api/gash/return`
5. 系統驗證後自動請款
6. 完成交易（給點）

---

## 前端範例

```typescript
function deposit() {
    axios.post('/api/gash/deposit', {
        amount: 100
    }).then(response => {
        // 建立動態表單
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = response.data.data.action_url;
        form.target = '_blank';

        // 加入 data 參數
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'data';
        input.value = response.data.data.form_data;

        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();

        // 清理
        setTimeout(() => {
            document.body.removeChild(form);
        }, 100);
    });
}
```

---

## 待開發功能

- 訂單管理（OrderRepository）
- 儲值記錄（DepositRepository）
- 自動給點邏輯
- 失敗通知功能
