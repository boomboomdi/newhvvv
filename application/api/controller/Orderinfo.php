<?php

namespace app\api\controller;

use app\common\model\OrderModel;
use think\Controller;
use think\Db;
use app\api\model\OrderLog;
use app\common\model\PayapiModel;
use app\api\validate\OrderinfoValidate;
use think\exception\ErrorException;
use think\facade\Log;
use think\Request;
use think\Validate;

class Orderinfo extends Controller
{
    /**
     * 正式下单接口
     * @return bool
     */
    public function create()
    {
        $data = @file_get_contents('php://input');
        $message = json_decode($data, true);
        //验证协议参数 -> 检查商户状态 -> 查询通道状态 ->通道下单
        try {
            if (!isset($message['merchant_sign']) || empty($message['merchant_sign'])) {
                return apiJsonReturn('10001', "缺少必要参数:merchant_sign");
            }
            if (!isset($message['order_no']) || empty($message['order_no'])) {
                return apiJsonReturn('10002', "缺少必要参数:order_no");
            }
            if (!isset($message['payment']) || empty($message['payment'])) {
                return apiJsonReturn('10003', "缺少必要参数:payment");
            }
            if (!isset($message['amount']) || empty($message['amount'])) {
                return apiJsonReturn('10004', "缺少必要参数:amount");
            }
            //
            if (!isset($message['currency']) || empty($message['currency'])) {
                return apiJsonReturn('10005', "缺少必要参数:currency");
            }
            if (!isset($message['subject']) || empty($message['subject'])) {
                return apiJsonReturn('10006', "缺少必要参数:subject");
            }
            //商品描述
            if (!isset($message['order_desc']) || empty($message['order_desc'])) {
                return apiJsonReturn('10007', "缺少必要参数:order_desc");
            }
            if (!isset($message['notify_url']) || empty($message['notify_url'])) {
                return apiJsonReturn('10007', "缺少必要参数:notify_url");
            }
            if (!isset($message['return_url']) || empty($message['return_url'])) {
                return apiJsonReturn('10007', "缺少必要参数:return_url");
            }

            if (!isset($message['version']) || empty($message['version'])) {
                return apiJsonReturn('10008', "缺少必要参数:version");
            }

            if (!isset($message['sign']) || empty($message['sign'])) {
                return apiJsonReturn('10009', "缺少必要参数:sign");
            }
            if (!isset($message['signType']) || empty($message['signType'])) {
                return apiJsonReturn('10010', "缺少必要参数:signType");
            }
            if (!isset($message['reqTime']) || empty($message['reqTime'])) {
                return apiJsonReturn('10011', "缺少必要参数:reqTime");
            }
            $message['add_time'] = int($message['reqTime']);
            //OrderLog
            if (is_int($message['amount'])) {
                $message['amount'] = $message['amount'] . ".00";
            }
            $db = new Db();
            //验证商户
            $merchantWhere['merchant_sign'] = $message['merchant_sign'];
//            $merchantWhere['client_ip'] = $message['merchant_sign'];
            $merchant = $db::table('bsa_merchant')->where($merchantWhere)->find();

//            $sign = md5($message['merchant_sign'] . $token . $message['order_no'] . $message['amount'] . $message['time']);
            //amount=10000&clientIp=192.168.0.111
            //&mchOrderNo=P0123456789101¬ifyUrl=https://www.baidu.com&platId=1000
            //&reqTime=20190723141000
            //&returnUrl= https://www.baidu.com&version=1.0
            //&key=EWEFD123RGSRETYDFNGFGFGSHDFGH =
            $sign = md5("amount=" . $message['amount'] . "&clientIp=" . $merchant['client_ip'] . "&mchOrderNo=" . $message['order_no'] . "&reqTime=" . $message['reqTime'] . "&returnUrl=" . $message['returnUrl'] . "&key=" . $merchant['token']);
            $sign = strtoupper($sign);
            $orderLogModel = new OrderLog();
            if ($sign != $message['sign']) {
                $orderData['merchant_sign'] = $message['merchant_sign'];
                $orderData['order_desc'] = "验签失败！";
                $orderLogModel->writeCreateOrderLog($orderData, 2);
                return apiJsonReturn('10006', "验签失败！");
            }
            $orderFind = $db::table('bsa_order')->where('order_no', '=', $message['order_no'])->count();
            if ($orderFind > 0) {
                $orderData['merchant_sign'] = $message['merchant_sign'];
                $orderData['order_desc'] = "单号重复！";
                $orderLogModel->writeCreateOrderLog($orderData, 2);
                return apiJsonReturn('11001', "单号重复！");
            }
            //查询开启通道  默认轮询
            //唯一单号
            for ($x = 0; $x <= 3; $x++) {
                $orderMe = guidForSelf();
                $orderFind = $db::table('bsa_order')->where('order_me', '=', $orderMe)->find();
                if (empty($orderFind)) {
                    $orderMe = guidForSelf();
                    break;
                } else {
                    continue;
                }
            }
            $where['payment'] = $message['payment'];
            $PayApiModel = new PayapiModel();
            $getPayUrlData['amount'] = $message['amount'];
            $getPayapiRes = $PayApiModel->getPayApisForMerchant($where, $getPayUrlData, "");
            //1、支付成功（下单成功）！2、支付失败（下单成功）！3、下单失败！4、等待支付（下单成功）！5、已手动回调。

            $param['order_status'] = 5;  //订单状态 //
            $param['order_pay'] = "";  //通道订单号
            $param['qr_url'] = "";  //通道订单号
            if ($getPayapiRes['code'] = 200) {
                $param['order_status'] = 4;  //订单状态 4下单成功
                $param['order_pay'] = $getPayapiRes['data']['order_pay'];  ///通道订单号
                $param['qr_url'] = $getPayapiRes['data']['qr_url'];  //付款链接
            }
            //1、入库
            $insertOrderData['api_sign'] = $param['api_sign']; //匹配通道标识
            $insertOrderData['order_pay'] = $param['order_pay']; //通道订单号
            $insertOrderData['qr_url'] = $param['qr_url'];  //付款链接
            $insertOrderData['merchant_sign'] = $message['merchant_sign'];  //商户
            $insertOrderData['order_no'] = $message['order_no'];  //商户订单id
            $insertOrderData['order_status'] = $param['order_status'];  //订单状态
            $insertOrderData['order_me'] = $orderMe; //本平台订单号
            $insertOrderData['amount'] = $message['amount']; //支付金额
            $insertOrderData['payable_amount'] = $message['amount'];  //应付金额
            $insertOrderData['payment'] = $message['payment']; //支付方式
            $insertOrderData['add_time'] = time();  //添加时间
            $insertOrderData['return_url'] = $message['return_url']; //下单同步回调地址
            $insertOrderData['notify_url'] = $message['notify_url']; //下单异步回调地址

            $OrderModel = new OrderModel();
            $createOrderOne = $OrderModel->addOrder($insertOrderData);
            if (0 == $createOrderOne['code']) {
                $orderData['merchant_sign'] = $message['merchant_sign'];
                $orderData['order_desc'] = "下单失败!无可用通道！";
                $orderLogModel->writeCreateOrderLog($orderData, 2);
                return apiJsonReturn('19999', "通道下单失败，下单失败!!!");
            }
            //获取订单信息
            if (0 == $getPayapiRes['code']) {
                return apiJsonReturn('20001', "下单失败!无可用通道", $message);
            }
            return apiJsonReturn('10000', "下单成功", $createOrderOne['data']);
        } catch (\Exception $exception) {
            Log::write("/n/t Orderinfo/create: /n/t" . json_encode($data) . "/n/t" . $exception->getMessage(), "exception");
            return apiJsonReturn('20009', "通道异常" . $exception->getMessage());
        } catch (\Error $error) {
            Log::write("/n/t Orderinfo/create: /n/t" . json_encode($data) . "/n/t" . $error->getMessage(), "error");
            return apiJsonReturn('20099', "通道异常" . $error->getMessage());

        }
    }

    //
    public function callbackformerchant()
    {

    }
    //订单回调 @todo  //通道触发->四方
    public function callback()
    {
        $data = @file_get_contents('php://input');
        $message = json_decode($data, true);
        //验证协议参数 -> 检查商户状态 -> 通知商户付款成功
        try {
            $validate = new OrderinfoValidate();
            //请求参数不完整
            if (!$validate->check($message)) {
                $returnMsg['code'] = 1002;
                $returnMsg['msg'] = "参数错误!";
                $returnMsg['data'] = $validate->getError();
                return json_encode($returnMsg);
            }
            $db = new Db();
            //验签
            $merchantWhere['merchant_sign'] = $message['merchant_sign'];
            $merchant = $db::table('bsa_merchant')->where($merchantWhere)->find();
            if (empty($merchant) && $merchant['status']) {

            }
            //    {
//        "merchant_sign":"cest",
//        "client_ip":"192.168.1.1"
//        "order_no":"cest",
//        "order_pay":"cest",
//        "payment":"cest",
//        "amount":"cest",
//        "actual_amount":"cest",
//        "pay_time":"cest",
//        "sign":"cest"
//    }
            //
            $sign = md5("merchant_sign=" . $message['merchant_sign'] .
                "&client_ip=" . $merchant['client_ip'] .
                "&order_no=" . $message['order_no'] .
                "&order_pay=" . $message['order_pay'] .
                "&payment=" . $message['payment'] .
                "&amount=" . $message['amount'] .
                "&actual_amount=" . $message['actual_amount'] .
                "&pay_time=" . $message['pay_time'] .
                "&returnUrl=" . $message['returnUrl'] .
                "&key=" . $merchant['token']
            );
            $sign = strtoupper($sign);
            $orderLogModel = new OrderLog();
            if ($sign != $message['sign']) {
                $orderData['merchant_sign'] = $message['merchant_sign'];
                $orderData['order_desc'] = "验签失败！";
                $orderLogModel->writeNotifyOrderLog($orderData, 2);
                return apiJsonReturn('10006', "验签失败！");
            }
            //处理通知

        } catch (\Exception $exception) {
            Log::write("/n/t Orderinfo/callback: /n/t" . json_encode($data) . "/n/t" . $exception->getMessage(), "exception");
            return apiJsonReturn('20009', "通道异常" . $exception->getMessage());
        } catch (\Error $error) {
            Log::write("/n/t Orderinfo/callback: /n/t" . json_encode($data) . "/n/t" . $error->getMessage(), "error");
            return apiJsonReturn('20099', "通道异常" . $error->getMessage());

        }
    }
}