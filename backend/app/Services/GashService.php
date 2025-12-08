<?php

namespace App\Services;

use App\Services\Service;
use App\Defined\ApiError;
use App\Tools\Gash\Trans;
use App\Services\Transaction\DepositService;
use App\Repositories\OrderRepository;
use App\Repositories\DepositRepository;
use App\Repositories\PaymentsRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GashService extends Service
{
    private static $cid; // 商家服務代碼
    private static $p;   // 交易密碼
    private static $k;   // 交易密鑰 1
    private static $v;   // 交易密鑰 2

    public function __construct(
    ) {
        self::$cid = config('chacha.gash.cid');
        self::$p = config('chacha.gash.trans_pwd');
        self::$k = config('chacha.gash.trans_key_i');
        self::$v = config('chacha.gash.trans_key_ii');
    }

    /**
     * GASH 儲值
     * @param Request $request
     * @return array
     */
    public function deposit($curUsername, Request $request)
    {
        $result = array(
            'error' => 'SUCCESS',
            'data'  => []
        );

        // 取得玩家資料
        $currentUser = new \stdClass();
        $currentUser->id = 1;

        // 初始化 GASH 交易
        $trans = new Trans(null);
        // 商家訂單編號
        $coid = time() . substr(strval(rand(10000, 19999)), 1, 4);
        // 取得付款代收業者代碼
        $paid = $request->input('paidType');
        // 取得交易金額
        $amount = $request->input('amount');

        // 交易訊息代碼
        $trans->nodes["MSG_TYPE"] = "0100";
        // 交易處理代碼
        $trans->nodes["PCODE"] = "300000";
        // 商家服務代碼
        $trans->nodes["CID"] = self::$cid;
        // 商家訂單編號
        $trans->nodes["COID"] = $coid;
        // 幣別
        $trans->nodes["CUID"] = "TWD";
        // 付款代收業者代碼
        $trans->nodes["PAID"] = $paid;
        // 交易金額
        $trans->nodes["AMOUNT"] = $amount;
        // 商家接收交易結果網址
        $trans->nodes["RETURN_URL"] = url('/api/gash/return');
        // 是否指定付款代收業者
        $trans->nodes["ORDER_TYPE"] = "M";
        // 交易備註
        $trans->nodes["MEMO"] = "";
        // 商家商品名稱
        $trans->nodes["PRODUCT_NAME"] = "點數儲值-" . $amount . "點";
        // 玩家帳號
        $trans->nodes["USER_ACCTID"] = $curUsername;

        // 以商家密碼、商家密鑰 I, II 取得 ERQC
        $erqc = $trans->GetERQC(self::$p, self::$k, self::$v);
        // 商家交易驗證壓碼
        $trans->nodes["ERQC"] = $erqc;

        // 取得送出之交易資料
        $formData = $trans->GetSendData();
        $actionUrl = config('chacha.gash.api_url') . '/order.aspx';

        // 組裝 HTML Form 和自動提交的 Script
        $html = $this->generateAutoSubmitForm($formData, $actionUrl);

        // 訂單資料
        $data = array(
            'html' => $html,
            'coid' => $trans->nodes["COID"],
            'action_url' => $actionUrl,
            'form_data' => $formData // 保留原始資料供參考
        );

        // 訂單細項資料
        // $product_arr = array();
        // $product = new \stdClass();
        // $product->product_id = 0;
        // $product->amount = 1;
        // $product->price = $amount;
        // $product->sum = $amount;
        // $product->type = 'Product';
        // $product->product_name = '儲值GASH' . $amount . '點';
        // $product_arr[] = $product;

        // 訂單資料
        // $obj = new \stdClass();
        // $obj->trade_id = $coid;
        // $obj->flow_type = 'GASH';
        // $obj->pay_type = $paid;
        // $obj->user_id = $currentUser->id;
        // $obj->product_arr = $product_arr;

        // 新增訂單
        // OrderRepository::create_order_v1($obj);

        // 新增儲值
        // $obj = new \stdClass();
        // $obj->trade_id = $coid;
        // $obj->flow_type = 'GASH';
        // $trans->nodes["REQUEST_BASE_64"] = $formData;
        // $obj->request_data = $trans->nodes;
        // $obj->payment_data = $payment_data;
        // DepositRepository::create_deposit_v1($obj);

        // 更新儲值資料 (付款請求回覆)
        // DepositRepository::update_request_resp($coid, $formData);

        $result['data'] = $data;

        return $result;
    }

    /**
     * 生成自動提交的 HTML Form
     * @param string $formData
     * @param string $actionUrl
     * @return string
     */
    private function generateAutoSubmitForm($formData, $actionUrl)
    {
        $html = '<!DOCTYPE html>
        <html lang="zh-TW">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
            </head>
            <body>
                <form id="gashPaymentForm" method="POST" action="' . htmlspecialchars($actionUrl) . '">
                <input type="hidden" name="data" value="' . htmlspecialchars($formData) . '">
                </form>
                <script>
                    window.onload = function() {
                        document.getElementById("gashPaymentForm").submit();
                    };
                </script>
            </body>
        </html>';

        return $html;
    }

    /**
     * 處理 GASH 回傳資料
     * @param Request $request
     * @return array
     */
    public static function callback(Request $request)
    {
        $result = array(
            'error' => 'SUCCESS',
            'data'  => []
	    );

        // 處理 GASH 回傳資料
        $return_data = self::handleReturnData($request);

        // 驗證 ERPC (GPS 交易驗證資料壓碼) 狀態
        $isCorrect = $return_data['isCorrect'];
        $trans = $return_data['data'];

        // 商家取得交易成功 (PAY_STATUS = S)，且驗證 ERPC (GPS 交易驗證資料壓碼)、CUID (幣別)、AMOUNT (金額) 無誤，商家必須進行請款作業
        if (
            $trans['PAY_STATUS'] === 'S' &&
            $isCorrect
            // $CUID === $trans['CUID'] &&
            // $AMOUNT === $trans['AMOUNT']
        ) {
            // 請款
            self::settle($trans);
            // 依據訂單金額給點
            // TODO: 依據訂單金額給點
        } else {
            // 通知使用者儲值失敗
            // TODO: 通知使用者儲值失敗
        }

        $result['data'] = $trans;

        return $result;
    }

    /**
     * 處理 GASH 主動通知資料
     * @param Request $request
     * @return array
     */
    public static function getReturnData(Request $request)
    {
        $result = '@RRN|S';

        // 處理 GASH 回傳資料
        $return_data = self::handleReturnData($request);

        $trans = $return_data['data'];

        // GASH 指定回傳值格式：RRN|PAY_STATUS
        $result = $trans['RRN'] . '|' . $trans['PAY_STATUS'];

        return $result;
    }

    /**
     * 處理 GASH 回傳資料
     * @param Request $request
     * @return array
     */
    protected static function handleReturnData($request)
    {
        $result = array(
            'isCorrect' => false,
            'data'  => []
	    );

        if ($request->has('data') || $request->has('DATA')) {
            $data = $request->input('data') ?? $request->input('DATA');

            // 將空白字元轉換為 +
            $data = str_replace(' ', '+', $data);

            // 轉換為 Trans 物件
            $trans = new Trans($data);
            $trans->nodes["RESPONSE_BASE_64"] = $data;

            // 驗證 GPS 交易驗證壓碼
            $isCorrect = $trans->VerifyERPC(self::$k, self::$v);

            $result['isCorrect'] = $isCorrect;
            $result['data'] = $trans->nodes;
        }

        return $result;
    }

    /**
     * 查詢 GASH 訂單狀態
     * @param Request $request
     * @param string $coid
     * @return array
     */
    public static function checkOrder($coid)
    {
        $result = array(
            'error' => 'SUCCESS',
            'data'  => []
        );

        // 初始化 GASH 交易
        $trans = new Trans(null);

        // 取得訂單資料
        // TODO: 取得訂單資料

        // 交易訊息代碼
        $trans->nodes["MSG_TYPE"] = "0100";
        // 交易處理代碼
        $trans->nodes["PCODE"] = "200000";
        // 商家服務代碼
        $trans->nodes["CID"] = self::$cid;
        // 商家訂單編號
        $trans->nodes["COID"] = $coid;
        // 幣別
        // $trans->nodes["CUID"] = $CUID;
        $trans->nodes["CUID"] = 'TWD';
        // 付款代收業者代碼
        // $trans->nodes["PAID"] = $PAID;
        $trans->nodes["PAID"] = 'COPGAM09';
        // 交易金額
        // $trans->nodes["AMOUNT"] = (string)intval($AMOUNT);
        $trans->nodes["AMOUNT"] = (string)intval(100);

        // 以商家密碼、商家密鑰 I, II 取得 ERQC
        $erqc = $trans->GetERQC(self::$p, self::$k, self::$v);
        // 商家交易驗證壓碼
        $trans->nodes["ERQC"] = $erqc;

        // 取得送出之交易資料
        $data = $trans->GetSendData();
        
        $client = new \SoapClient(config('chacha.gash.api_url') . '/checkorder.asmx?wsdl');
        $response =  $client->getResponse(array('data' => $data));

        // 如果 GASH 回傳資料不為空，則轉換為 Trans 物件
        if ($response->getResponseResult) {
            $trans = new Trans($response->getResponseResult);
        }

        $result['data'] = $response;

        return $result;
    }

    /**
     * 向 GASH 請款
     * @param Request $request
     * @return array
     */
    public static function settle($transData)
    {
        $result = array(
            'error' => 'SUCCESS',
            'data'  => []
	    );

        // 初始化 GASH 交易
        $trans = new Trans(null);

        // 交易訊息代碼
        $trans->nodes["MSG_TYPE"] = "0500";
        // 交易處理代碼
        $trans->nodes["PCODE"] = "300000";
        // 商家服務代碼
        $trans->nodes["CID"] = self::$cid;
        // 商家訂單編號
        $trans->nodes["COID"] = $transData['COID'];
        // 幣別
        $trans->nodes["CUID"] = $transData['CUID'];
        // 付款代收業者代碼
        $trans->nodes["PAID"] = $transData['PAID'];
        // 交易金額
        $trans->nodes["AMOUNT"] = (string)intval($transData['AMOUNT']);

        // 以商家密碼、商家密鑰 I, II 取得 ERQC
        $erqc = $trans->GetERQC(self::$p, self::$k, self::$v);
        // 商家交易驗證壓碼
        $trans->nodes["ERQC"] = $erqc;

        // 取得送出之交易資料
        $data = $trans->GetSendData();

        // 向 GASH 請款
        $client = new \SoapClient(config('chacha.gash.api_url')  . '/settle.asmx?wsdl');
        $response = $client->getResponse(array("data" => $data));

        // 如果 GASH 回傳資料不為空，則轉換為 Trans 物件
        if ($response->getResponseResult) {
            // 轉換為 Trans 物件
            $trans = new Trans($response->getResponseResult);
            $trans->nodes["RESPONSE_BASE_64"] = $response->getResponseResult;

            // 驗證 GPS 交易驗證壓碼
            $isCorrect = $trans->VerifyERPC(self::$k, self::$v);

            // 商家取得交易成功 (PAY_STATUS = S)，且驗證 ERPC (GPS 交易驗證資料壓碼) 無誤
            if (!isset($trans->nodes['PAY_STATUS']) || $trans->nodes['PAY_STATUS'] !== 'S' || !$isCorrect) {
                $result['error'] = 'DEPOSIT_SETTLE_VERIFY_ERROR';
                return $result;
            }

            $result['data'] = $trans->nodes;
        } else {
            $result['error'] = 'DEPOSIT_SETTLE_NO_RETURN_DATA';
        }

        return $result;
    }
}
