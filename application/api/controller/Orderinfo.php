<?php

namespace app\api\controller;

use app\common\model\OrderhexiaoModel;
use app\common\model\OrderModel;
use think\Controller;
use think\Db;
use app\api\model\OrderLog;
use app\api\validate\OrderinfoValidate;
use think\exception\ErrorException;
use think\facade\Log;
use think\Request;
use think\Validate;

class Orderinfo extends Controller
{
    /**
     * 正式入口
     * @param Request $request
     * @return void
     */
    public function order(Request $request)
    {
        $data = @file_get_contents('php://input');
        $message = json_decode($data, true);
        $updateParam = [];
        try {
            logs(json_encode(['message' => $message, 'line' => $message]), 'douyin_order_fist');
            $validate = new OrderinfoValidate();
            if (!$validate->check($message)) {
                return apiJsonReturn(-1, '', $validate->getError());
            }
            $db = new Db();
            //验证商户
            $token = $db::table('bsa_merchant')->where('merchant_sign', '=', $message['merchant_sign'])->find()['token'];
            if (empty($token)) {
                return apiJsonReturn(10001, "商户验证失败！");
            }
            $sig = md5($message['merchant_sign'] . $message['order_no'] . $message['amount'] . $message['time'] . $token);
            if ($sig != $message['sign']) {
                logs(json_encode(['orderParam' => $message, 'doMd5' => $sig]), 'orderParam_signfail');
                return apiJsonReturn(10006, "验签失败！");
            }
            $orderFind = $db::table('bsa_order')->where('order_no', '=', $message['order_no'])->count();
            if ($orderFind > 0) {
                return apiJsonReturn(11001, "单号重复！");
            }

            //$user_id = $message['user_id'];  //用户标识
            // 根据user_id  未付款次数 限制下单 end

            $orderMe = guid12();
            for ($x = 0; $x <= 3; $x++) {
                $orderFind = $db::table('bsa_order')->where('order_me', '=', $orderMe)->find();
                if (empty($orderFind)) {
                    $orderMe = guid12();
                    break;
                } else {
                    continue;
                }
            }

            //1、入库
            $insertOrderData['merchant_sign'] = $message['merchant_sign'];  //商户
            $insertOrderData['order_no'] = $message['order_no'];  //商户订单号
            $insertOrderData['order_status'] = 3;  //  1、支付成功（下单成功）！2、支付失败（下单成功）！3、下单失败！4、等待支付（下单成功）！5、已手动回调。
            $insertOrderData['order_me'] = $orderMe; //本平台订单号
            $insertOrderData['amount'] = $message['amount']; //支付金额
            $insertOrderData['payable_amount'] = $message['amount'];  //应付金额
            $insertOrderData['payment'] = "HUAFEI"; //alipay
            $insertOrderData['add_time'] = time();  //入库时间
            $insertOrderData['notify_url'] = $message['notify_url']; //下单回调地址 notify url

            $orderModel = new OrderModel();
            $createOrderOne = $orderModel->addOrder($insertOrderData);
            if (!isset($createOrderOne['code']) || $createOrderOne['code'] != '0') {
                return apiJsonReturn(10008, $createOrderOne['msg'] . $createOrderOne['code']);
            }
            //2、分配核销单
            $orderHXModel = new OrderhexiaoModel();
            $getUseHxOrderRes = $orderHXModel->getUseHxOrder($insertOrderData);
            if (!isset($getUseHxOrderRes['code']) || $getUseHxOrderRes['code'] != 0) {
                //修改订单为下单失败状态。
                $updateOrderStatus['last_use_time'] = time();
                $updateOrderStatus['order_desc'] = "下单失败|" . $getUseHxOrderRes['msg'];
                $orderModel->where('order_no', $insertOrderData['order_no'])->update($updateOrderStatus);
                return apiJsonReturn(10010, $getUseHxOrderRes['msg'], "");
            }
            $updateOrderStatus['order_status'] = 4;   //等待支付状态
            $updateOrderStatus['check_times'] = 1;   //下单成功就查询一次
            $updateOrderStatus['order_pay'] = $getUseHxOrderRes['data']['order_no'];   //匹配核销单订单号
            $updateOrderStatus['order_limit_time'] = time() + 900;  //订单表 限制使用时间
            $updateOrderStatus['start_check_amount'] = $getUseHxOrderRes['data']['last_check_amount'];  //开单余额
            $updateOrderStatus['last_check_amount'] = $getUseHxOrderRes['data']['last_check_amount'];  //开单余额
            $updateOrderStatus['end_check_amount'] = $getUseHxOrderRes['data']['last_check_amount'] + $insertOrderData['amount'];  //应到余额
            $updateOrderStatus['next_check_time'] = time() + 30;   //下次查询余额时间
            $updateOrderStatus['account'] = $getUseHxOrderRes['data']['account'];   //匹配核销单账号
            $updateOrderStatus['write_off_sign'] = $getUseHxOrderRes['data']['write_off_sign'];   //匹配核销单核销商标识
            $updateOrderStatus['order_desc'] = "下单成功,等待支付！";
            $url = "http://175.178.241.238/pay/#/huafei";
//            订单号order_id   金额 amount   手机号 phone  二维码链接 img_url    有效时间 limit_time 秒
            $imgUrl = "http://175.178.195.147:9090/upload/huafei.jpg";
            $imgUrl = urlencode($imgUrl);
            $limitTime = $updateOrderStatus['order_limit_time'] - 600;
            $url = $url . "?order_id=" . $message['order_no'] . "&amount=" . $message['amount'] . "&phone=" . $getUseHxOrderRes['data']['account'] . "&img_url=" . $imgUrl . "&limit_time=" . $limitTime;
            $updateOrderStatus['qr_url'] = $url;   //支付订单
            $updateWhere['order_no'] = $message['order_no'];
            $localOrderUpdateRes = $orderModel->localUpdateOrder($updateWhere, $updateOrderStatus);
//            logs(json_encode([
//                'orderWhere' => $updateWhere,
//                'updateOrderStatus' => $updateOrderStatus,
//                'localOrderUpdateRes' => $localOrderUpdateRes
//            ]), 'localhostUpdateOrder');
//            $orderModel->where('order_no', '=', $insertOrderData['order_no'])->update($updateOrderStatus);
            if (!isset($localOrderUpdateRes['code']) || $localOrderUpdateRes['code'] != 0) {
                return apiJsonReturn(10009, "下单失败！");
            }
            return apiJsonReturn(10000, "下单成功", $url);
        } catch (\Error $error) {
            logs(json_encode(['file' => $error->getFile(),
                'line' => $error->getLine(), 'errorMessage' => $error->getMessage()
            ]), 'orderError');
            return json(msg(-22, '', $error->getMessage() . $error->getLine()));
        } catch (\Exception $exception) {
            logs(json_encode(['file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'errorMessage' => $exception->getMessage()
            ]), 'orderException');
            return json(msg(-11, '', $exception->getMessage() . $exception->getFile() . $exception->getLine()));
        }
    }


    /**
     * 引导页面查询订单状态
     */
    public function getOrderInfo(Request $request)
    {
        header('Access-Control-Allow-Origin:*');
        header("Access-Control-Allow-Credentials:true");
        header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept,Authorization");
        header('Access-Control-Allow-Methods:GET,POST,PUT,DELETE,OPTIONS,PATCH');
        $data = @file_get_contents('php://input');
        $message = json_decode($data, true);
        if (!isset($message['order_no']) || empty($message['order_no'])) {
            return json(msg(-1, '', '单号有误！'));
        }
        try {
            $orderModel = new OrderModel();
            $where['order_no'] = $message['order_no'];
            $orderInfo = $orderModel->where($where)->find();
            if (empty($message['order_no'])) {
                return json(msg(-2, '', '无此推单！'));
            }
            if ($orderInfo['order_status'] != 4) {
                return json(msg(-3, '', '请重新下单！'));
            }

            if (($orderInfo['order_limit_time'] - 600) < time()) {
                return json(msg(-4, '', '订单超时，请重新下单'));
            }

            return json(msg(0, ($orderInfo['order_limit_time'] - 30), "success"));

        } catch (\Exception $exception) {
            logs(json_encode(['param' => $message,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'errorMessage' => $exception->getMessage()
            ]), 'orderInfoException');
            return apiJsonReturn(-11, "orderInfo exception!" . $exception->getMessage());
        } catch (\Error $error) {
            logs(json_encode(['param' => $message,
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'errorMessage' => $error->getMessage()]), 'orderInfoError');
            return json(msg(-22, '', 'orderInfo error!' . $error->getMessage()));
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