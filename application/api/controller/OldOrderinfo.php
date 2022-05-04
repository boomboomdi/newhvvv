<?php

namespace app\api\controller;

use app\common\model\OrderModel;
use think\Controller;
use think\Db;
use app\api\model\OrderLog;
use app\common\model\PayapiModel;
use think\exception\ErrorException;
use think\Request;
use think\Validate;

class Orderinfo extends Controller
{
    /**
     * 正式下单接口
     * @return bool
     */
    public function order()
    {
        $data = @file_get_contents('php://input');
        $message = json_decode($data, true);
        //验证协议参数 -> 检查商户状态 -> 查询通道状态 ->通道下单
        try {
            if (!isset($message['mchNo']) || empty($message['mchNo'])) {
                return apiJsonReturn('10001', "缺少必要参数:mchNo");
            }
            $message['merchant_sign'] = $message['mchNo'];
            if (!isset($message['mchOrderNo']) || empty($message['mchOrderNo'])) {
                return apiJsonReturn('10002', "缺少必要参数:mchOrderNo");
            }
            $message['order_no'] = $message['mchOrderNo'];
            if (!isset($message['wayCode']) || empty($message['wayCode'])) {
                return apiJsonReturn('10003', "缺少必要参数:wayCode");
            }
            $message['payment'] = $message['wayCode'];
            if (!isset($message['amount']) || empty($message['amount'])) {
                return apiJsonReturn('10004', "缺少必要参数:amount");
            }
            if (!isset($message['currency']) || empty($message['currency'])) {
                return apiJsonReturn('10005', "缺少必要参数:currency");
            }
            if (!isset($message['subject']) || empty($message['subject'])) {
                return apiJsonReturn('10006', "缺少必要参数:subject");
            }
            if (!isset($message['body']) || empty($message['body'])) {
                return apiJsonReturn('10007', "缺少必要参数:body");
            }
            $message['order_desc'] = $message['body'];
            if (!isset($message['notifyUrl']) || empty($message['notifyUrl'])) {
                return apiJsonReturn('10007', "缺少必要参数:notifyUrl");
            }
            $message['notify_url'] = $message['notifyUrl'];
            if (!isset($message['returnUrl']) || empty($message['returnUrl'])) {
                return apiJsonReturn('10007', "缺少必要参数:returnUrl");
            }
            $message['return_url'] = $message['returnUrl'];
            if (!isset($message['version']) || empty($message['version'])) {
                return apiJsonReturn('10008', "缺少必要参数:version");
            }
            $message['return_url'] = $message['returnUrl'];
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
            $merchant = $db::table('bsa_merchant')->where()->find();

//            $sign = md5($message['merchant_sign'] . $token . $message['order_no'] . $message['amount'] . $message['time']);
            //amount=10000&clientIp=192.168.0.111
            //&mchOrderNo=P0123456789101¬ifyUrl=https://www.baidu.com&platId=1000
            //&reqTime=20190723141000
            //&returnUrl= https://www.baidu.com&version=1.0
            //&key=EWEFD123RGSRETYDFNGFGFGSHDFGH =
            $sign = md5("amount=".$message['amount'] ."&clientIp=". $merchant['client_ip']."&mchOrderNo=".$message['order_no']."&reqTime=".$message['reqTime']."&returnUrl=".$message['returnUrl']."&key=".$merchant['token']);
            $sign = strtoupper($sign);
            $OrderLogModel = new OrderLog();
            if ($sign != $message['sign']) {
                $orderData['merchant_sign'] = $message['merchant_sign'];
                $orderData['order_desc'] = "验签失败！";
                $OrderLogModel->writeCreateOrderLog($orderData, 2);
                return apiJsonReturn('10006', "验签失败！");
            }
            $orderFind = $db::table('bsa_order')->where('order_no', '=', $message['order_no'])->count();
            if ($orderFind > 0) {
                $orderData['merchant_sign'] = $message['merchant_sign'];
                $orderData['order_desc'] = "单号重复！";
                $OrderLogModel->writeCreateOrderLog($orderData, 2);
                return apiJsonReturn('11001', "单号重复！");
            }
            //查询开启通道  默认轮询

            $where['payment'] =  $message['payment'];
            $PayApiModel = new PayapiModel();
            $getPayapiRes = $PayApiModel->getPayapisFormerchant($where,"","");
            if(0 == $getPayapiRes['code']) {
                $orderData['merchant_sign'] = $message['merchant_sign'];
                $orderData['order_desc'] = "下单失败!无可用通道！";
                $OrderLogModel->writeCreateOrderLog($orderData, 2);
                return apiJsonReturn('20001', "下单失败!无可用通道", $message);
            }
            //1、入库
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
            $insertOrderData['merchant_sign'] = $message['merchant_sign'];  //商户
            $insertOrderData['order_no'] = $message['order_no'];  //商户订单id
            $insertOrderData['order_status'] = 3;  //订单状态
            $insertOrderData['order_me'] = $orderMe; //本平台订单号
            $insertOrderData['amount'] = $message['amount']; //支付金额
            $insertOrderData['payable_amount'] = $message['amount'];  //应付金额
            $insertOrderData['payment'] = $message['payment']; //支付方式
            $insertOrderData['add_time'] = time();  //添加时间
            $insertOrderData['qr_url'] = $getPayapiRes['data'];  //添加时间
            $insertOrderData['return_url'] = $message['return_url']; //下单同步回调地址
            $insertOrderData['notify_url'] = $message['notify_url']; //下单异步回调地址

            $OrderModel = new OrderModel();
            $createOrderOne = $OrderModel->addOrder($insertOrderData);
            if(0 == $createOrderOne['code']) {
                $orderData['merchant_sign'] = $message['merchant_sign'];
                $orderData['order_desc'] = "下单失败!无可用通道！";
                $OrderLogModel->writeCreateOrderLog($orderData, 2);
                return apiJsonReturn('10000', "下单成功", $createOrderOne['data']);
            }

            return apiJsonReturn('19999', "通道下单失败，下单失败!!!");
        } catch (\Exception $exception) {
            logs(json_encode(['message' => $message, 'file' => $exception->getFile(), 'line' => $exception->getLine(), 'errorMessage' => $exception->getMessage()]), 'hbdhtestCreateIndexOrder_exception');
            return apiJsonReturn('20009', "通道异常" . $exception->getMessage());
        } catch (\Error $error) {
            logs(json_encode(['message' => $message, 'file' => $error->getFile(), 'line' => $error->getLine(), 'errorMessage' => $error->getMessage()]), 'hbdhtestCreateCybIndexOrder_error');
            return apiJsonReturn('20009', "通道异常" . $error->getMessage());

        }
    }

}