# 🎯 Agent 爬蟲系統完整指南

## 📋 **正確的文件說明**

由於您已經刪除了 API 路由和 Controller，**現在只使用 Console Commands**。以下是正確的使用指南：

---

## 🚀 **快速開始（3 步驟）**

### 1️⃣ **檢查系統狀態**
```bash
php artisan agent:status
```

### 2️⃣ **開始爬取數據**
```bash
# 方法一：瀏覽器自動化（推薦，直接爬畫面）
php artisan agent:scrape-browser "https://agent2.chichengwld.com/#/record/chessRecord"

# 方法二：HTML 結構解析（快速）
php artisan agent:scrape-content "/record/chessRecord"

# 方法三：智能 API 發現
php artisan agent:smart-scrape
```

### 3️⃣ **查看結果**
```bash
ls storage/app/scraped_data/
```

---

## 📁 **文件結構**

```
backend/
├── app/
│   ├── Services/
│   │   └── AgentLoginService.php          # 核心認證服務
│   └── Console/Commands/
│       ├── AgentStatus.php                # 狀態檢查
│       ├── ScrapeBrowserContent.php       # 瀏覽器自動化
│       ├── ScrapePageContent.php          # HTML 解析
│       ├── ScrapeGameLogApi.php           # 遊戲日誌專用
│       ├── SmartApiScraper.php            # 智能 API 發現
│       └── TestApiEndpoint.php            # API 測試
├── config/
│   └── chacha.php                         # 配置文件
└── storage/app/scraped_data/              # 數據保存目錄
```

---

## ⚙️ **環境配置**

### 1. 在 `.env` 文件中配置 Cookie：

```env
AGENT_AUTH=CWbbK08XWTe0hf
AGENT_BG_LANGUAGE_KEY=zh-cn
AGENT_PHPSESSID=你的完整PHPSESSID值
AGENT_TOKEN=你的完整Token值
```

### 2. 獲取 Cookie 值：

1. 在瀏覽器中登入 https://agent2.chichengwld.com/
2. 打開開發者工具（F12）
3. 在控制台執行：
```javascript
document.cookie.split(';').forEach(cookie => {
    const [name, value] = cookie.trim().split('=');
    console.log(name + ': ' + value);
});
```
4. 複製值到 `.env` 文件

---

## 🛠️ **所有可用命令**

### ✅ **核心命令**

| 命令 | 功能 | 使用場景 |
|------|------|----------|
| `agent:status` | 檢查登入狀態 | 開始前檢查 |
| `agent:scrape-browser` | 瀏覽器自動化爬取 | **推薦：直接爬畫面** |
| `agent:scrape-content` | HTML 結構解析 | 快速嘗試 |
| `agent:smart-scrape` | 智能 API 發現 | 尋找 API 端點 |

---

## 📖 **詳細使用說明**

### 🌐 **方法一：瀏覽器自動化（推薦）**

**直接爬取渲染後的頁面內容，包含 JavaScript 執行後的數據**

```bash
php artisan agent:scrape-browser "https://agent2.chichengwld.com/#/record/chessRecord"
```

**選項：**
```bash
--selector=".table"      # 指定元素選擇器
--wait=10000            # 等待時間（毫秒）
--output=csv            # 輸出格式（json/csv）
```

**優點：**
- ✅ 看得到什麼就爬什麼
- ✅ 自動執行 JavaScript
- ✅ 無需尋找 API
- ✅ 生成截圖驗證

---

### 📄 **方法二：HTML 結構解析**

**解析 HTML 結構，提取表格、列表等數據**

```bash
php artisan agent:scrape-content "/record/chessRecord"
```

**選項：**
```bash
--selector="table"      # CSS 選擇器
--output=csv           # 輸出格式
--wait-js              # 提示可能需要 JS
```

**優點：**
- ✅ 速度最快
- ✅ 無需外部依賴
- ⚠️ 不適合純 SPA 應用

---

### 🔍 **方法三：智能 API 發現**

**自動測試所有發現的 API 端點**

```bash
php artisan agent:smart-scrape
```

**選項：**
```bash
--endpoint="/api/endpoint"  # 測試特定端點
--full-session              # 建立完整會話
```

**優點：**
- ✅ 自動發現可用 API
- ✅ 測試多種認證方法
- ✅ 提供詳細分析

---

## 💾 **數據保存位置**

所有爬取的數據保存在：
```
storage/app/scraped_data/
├── browser_scrape_full_*.json    # 瀏覽器爬取完整結果
├── screenshot_*.png              # 頁面截圖
├── table_*.csv                   # 提取的表格數據
├── api_calls_*.json              # 捕獲的 API 調用
└── html_scrape_*.json            # HTML 解析結果
```

---

## 🔧 **編碼問題處理**

系統自動處理編碼問題：
- ✅ 自動檢測編碼（UTF-8, BIG5, GB2312, GBK）
- ✅ 自動轉換為 UTF-8
- ✅ 清理無效字符
- ✅ 支援 gzip 解壓縮

---

## 🆘 **常見問題**

### Q: Cookie 過期怎麼辦？
**A:** 重新登入瀏覽器，獲取新的 Cookie 值更新到 `.env`

### Q: 瀏覽器自動化失敗？
**A:** Docker 環境限制，嘗試在本地環境運行或使用 HTML 解析方法

### Q: 找不到數據？
**A:** 這是 SPA 應用，需要使用瀏覽器自動化方法

### Q: 如何查看日誌？
**A:** `tail -f storage/logs/laravel.log`

---

## 📚 **相關文檔**

- **快速開始**：查看 `QUICK_START_SCRAPING.md`
- **SPA 爬取詳情**：查看 `README_SPA_SCRAPING.md`
- **API vs Commands**：已刪除 API，只使用 Commands

---

## ✅ **總結**

**您現在擁有一套完整的命令行爬蟲工具！**

- ✅ 所有功能通過 Console Commands 使用
- ✅ 無需 HTTP API 路由
- ✅ 適合後台任務和排程
- ✅ 數據自動保存到 `storage/app/scraped_data/`

**立即開始：** `php artisan agent:status` 🚀

---

*最後更新：2025-12-12*  
*狀態：✅ 只使用 Console Commands，API 路由已移除*