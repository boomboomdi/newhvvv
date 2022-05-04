<?php

namespace app\api\controller;

use app\api\model\OrderLog;
use think\Db;
use think\facade\Log;
use think\Controller;
use app\common\model\OrderModel;
use app\common\model\PayapiModel;

class Test extends Controller
{
    protected $testToken = "7897897978979";
    protected $merchantSign = "ceshi";

    public function index()
    {
        $message = [];
//        try {
        if (request()->isPost()) {
            $message = input('post.');
            if (!isset($message['api_sign']) || empty($message['api_sign'])) {
                return apiJsonReturn('10003', "请选择接口:api_sign");
            }
            if (!isset($message['amount']) || empty($message['amount'])) {
                return apiJsonReturn('10003', "请输入金额:amount");
            }

            //OrderLog
            if (is_int($message['amount'])) {
                $message['amount'] = $message['amount'] . ".00";
            }
            $param['amount'] = $message['amount'];
            $param['order_me'] = $message['order_me'];
            //            $param['merchant_sign'] = $param['merchant_sign'];
            $param['order_no'] = guidForSelf();
            $param['subject'] = "测试订单";  //商品标题
            $param['order_desc'] = "测试订单" . $param['order_me'] . "金额" . $param['amount'];  //商品描述
            $param['notify_url'] = "www.baidu.com";
            $param['return_url'] = "www.baidu.com";
            $param['version'] = "1";
            $param['api_sign'] = $message['api_sign'];
            $param['add_time'] = time();
            $param['update_time'] = $param['add_time'];
            $param['payment'] = Db::table("bsa_payapi")->where("api_sign", $param['api_sign'])->find()['payment'];
            $payApiModel = new PayapiModel();

            $where['payment'] = $param['payment'];
            $where['api_sign'] = $param['api_sign'];
            $getPayRes = $payApiModel->getPayApisForMerchant($where, $param);
            $param['order_status'] = 5;  //订单状态 5：下单失败
            $param['order_pay'] = "";  //通道订单号
            $param['qr_url'] = "";  //通道订单号
            if ($getPayRes['code'] == 0) {

                $param['order_status'] = 6;  //订单状态 6下单成功
                $param['order_pay'] = $getPayRes['data']['order_pay'];  ///通道订单号
                $param['qr_url'] = $getPayRes['data']['qr_url'];  //付款链接
            }
            //1、入库
            $insertOrderData['api_sign'] = $param['api_sign']; //匹配 通道标识
            $insertOrderData['qr_url'] = $param['qr_url'];  //付款链接
            $insertOrderData['merchant_sign'] = "ceshi";  //商户测试
            $insertOrderData['order_no'] = $param['order_no'];  //商户订单id
            $insertOrderData['order_me'] = $param['order_me']; //本平台订单号
            $insertOrderData['order_pay'] = $param['order_pay']; //通道订单号
            $insertOrderData['order_status'] = $param['order_status']; //订单状态
            $insertOrderData['amount'] = $param['amount']; //支付金额
            $insertOrderData['payable_amount'] = $param['amount'];  //应付金额
            $insertOrderData['payment'] = $param['payment']; //支付方式
            $insertOrderData['add_time'] = time();  //添加时间
            $insertOrderData['return_url'] = $param['return_url']; //下单同步回调地址
            $insertOrderData['notify_url'] = $param['notify_url']; //下单异步回调地址
            $orderLogModel = new OrderLog();
            $orderModel = new OrderModel();
            $orderModel->addOrder($insertOrderData);
            if (0 != $getPayRes['code']) {
                var_dump($getPayRes);
                exit;
                $orderData['merchant_sign'] = "ceshi";
                $orderData['order_desc'] = "下单失败!无可用通道！";
                $orderLogModel->writeCreateOrderLog($orderData, 2);
                $returnData['code'] = -2;
                $returnData['msg'] = "下单失败!无可用通道！";
                $returnData['data'] = "";
                return json($returnData);
            }

            $returnData['code'] = 0;
            $returnData['msg'] = "下单成功！";
            $returnData['data'] = $param['qr_url'];
            return json($returnData);
        }

        $this->assign([
            'order_me' => guidForSelf(),
            'payapis' => (new \app\admin\model\PayapiModel())->getAllPayApis()['data']
        ]);

        return $this->fetch();
//        } catch (\Exception $exception) {
//            Log::write("/n/t Orderinfo/test: exception n/t" . $exception->getMessage(), "exception");
//            return apiJsonReturn('20009', "下单异常" . $exception->getMessage());
//        } catch (\Error $error) {
//            Log::write("/n/t Orderinfo/test:exception /n/t" . $error->getMessage(), "error");
//            return apiJsonReturn('20099', "下单异常" . $error->getMessage());
//        }
    }
}