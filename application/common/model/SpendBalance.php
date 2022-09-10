<?php
/**
 * Created by PhpStorm.
 * User: NickBai
 * Email: 876337011@qq.com
 * Date: 2019/3/17
 * Time: 4:48 PM
 */

namespace app\common\model;

use think\Db;
use think\facade\Log;
use think\Model;
use app\common\model\ChecklogModel;

class SpendBalance extends Model
{
    /**
     * 银河余额查询
     * @param $account //需查询帐号
     * @param $amount //订单金额
     * @param $orderNo //查询订单号
     * @param $outTradeNo //订单号
     * @param $payType //支付类型
     * @return Model|\think\response\Json|void
     */
    public function yinHeBalance($account, $amount = 100, $orderNo = "YINHE8888888888", $outTradeNo = '88888888', $payType = '微信')
    {
        try {
            $checkParam['phone'] = $account;
            $checkParam['amount'] = 100;
            $checkParam['order_me'] = $orderNo;
            $url = "http://119.91.82.145/api/createOrder?token=47a4f42371348b1dad5c813eb89e4db7
            &phone=13782396069&channel=swye
            &pay_type=微信&amount=100
            &out_trade_no=88888888
            &lock_time=10
            &callback_url=http://119.91.82.145/api/callback";

            $callbackUrl = "http://47.242.148.5:8808/api/orderhexiao/checkPhoneBalanceCallback";
            $url = "http://119.91.82.145/api/createOrder";
            $data['token'] = '47a4f42371348b1dad5c813eb89e4db7';
            $data['phone'] = $account;
            $data['channel'] = 'swye';
            $data['pay_type'] = $payType;
            $data['amount'] = (int)$amount;
            $data['out_trade_no'] = $outTradeNo;
            $data['lock_time'] = 10;
            $data['callback_url'] = 'http://47.242.148.5:8808/api/orderhexiao/checkPhoneBalanceCallback';
            $checkRes = curlGet1($url, 'get', $data);

//            return $res;
            logs(json_encode([
                "url" => $url,
                "data" => $data,
                "time" => date("Y-m-d H:i:s", time()),
                "checkAmountResult" => json_decode($checkRes)
            ]), 'yinHeBalance');
            $db = new Db();
            $res = json_to_array($checkRes);
            $returnCode = -2;
            $addParam = [];
            $addParam['check_sign'] = '银河';
            $addParam['status'] = 3;
            $addParam['check_desc'] = '查询异常';
            if (!$res) {
                $returnCode = -1;
                $addParam['check_desc'] = '查询失败';
            }

            $returnBalanceData = [];
            if (isset($res['code']) && $res['code'] == 1) {
                $returnCode = 1;
                $addParam['status'] = 1;
                $addParam['check_desc'] = '查询成功';
                $balanceDataOne = $res['data'];
                $balanceData = json_decode($balanceDataOne['data'], true);
                $returnBalanceData['account'] = $balanceData['phoneNumber'];
                $returnBalanceData['balance'] = $balanceData['totalBalance'];
            }
            $addParam['order_no'] = $orderNo;
            $addParam['account'] = $account;
            $addParam['amount'] = $amount;
            $addParam['check_time'] = time();
            logs(json_encode(['param' => $addParam,
            ]), 'yinHeBalancerInsert');
            print_r($addParam);
            $checklogModel = new ChecklogModel();
//            $type = is_array($addParam);
//            var_dump($type);exit;
            $insert = $checklogModel->addlog([
                'check_sign' => $addParam['check_sign'],
                'status' => $addParam['status'],
                'order_no' => $addParam['order_no'],
                'account' => $addParam['account'],
                'check_time' => $addParam['check_time'],
                'check_result' => "接口返回|" . json_encode($res)
            ]);
            if (!$insert) {
                return modelReMsg(-12, $returnBalanceData, $addParam);
            }
            return modelReMsg($returnCode, $returnBalanceData, $addParam['check_desc']);

        } catch (\Error $error) {

            logs(json_encode(['file' => $error->getFile(),
                'line' => $error->getLine(), 'errorMessage' => $error->getMessage()
            ]), 'yinHeBalanceError');
            return modelReMsg(-22, '', "接口异常!-22");
        } catch (\Exception $exception) {
            logs(json_encode(['file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'errorMessage' => $exception->getMessage(),
            ]), 'yinHeBalancerException');
            return modelReMsg(-11, '', "接口异常!-11");
        }


    }
}


