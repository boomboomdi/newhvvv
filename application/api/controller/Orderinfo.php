<?php

namespace app\api\controller;

use think\Controller;
use think\Db;
use app\common\model\OrderhexiaoModel;
use app\common\model\OrderModel;
use app\api\validate\OrderinfoValidate;
use app\api\validate\CheckPhoneAmountNotifyValidate;
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
            logs(json_encode(['message' => $message, 'line' => $message]), 'order_fist');
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

                logs(json_encode(['action' => 'getUseHxOrderRes',
                    'insertOrderData' => $insertOrderData,
                    '$getUseHxOrderRes' => $getUseHxOrderRes]
                ), 'getUseHxOrder_log');

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
            $updateOrderStatus['next_check_time'] = time() + 90;   //下次查询余额时间
            $updateOrderStatus['account'] = $getUseHxOrderRes['data']['account'];   //匹配核销单账号
            $updateOrderStatus['write_off_sign'] = $getUseHxOrderRes['data']['write_off_sign'];   //匹配核销单核销商标识
            $updateOrderStatus['order_desc'] = "下单成功,等待支付！";
            $url = "http://175.178.241.238/pay/#/huafei";
//            订单号order_id   金额 amount   手机号 phone  二维码链接 img_url    有效时间 limit_time 秒
//            $imgUrl = "http://175.178.195.147:9090/upload/huafei.jpg";
            $imgUrl = "http://175.178.195.147:9090/upload/tengxun.jpg";
            $imgUrl = urlencode($imgUrl);
            $limitTime = ($updateOrderStatus['order_limit_time'] - 720);
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

            if (empty($orderInfo['order_no'])) {
                return json(msg(-2, '', '无此推单！'));
            }
            if ($orderInfo['order_status'] != 4) {

                return json(msg(-3, '', '请重新下单！'));
            }

            if (($orderInfo['order_limit_time'] - 720) < time()) {
                return json(msg(-4, '', '订单超时，请重新下单'));
            }

            return json(msg(0, ($orderInfo['order_limit_time'] - 720), "success"));

        } catch (\Exception $exception) {
            logs(json_encode(['param' => $message,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'errorMessage' => $exception->getMessage()]), 'orderInfoException');
            return apiJsonReturn(-11, "orderInfo exception!" . $exception->getMessage());
        } catch (\Error $error) {
            logs(json_encode(['param' => $message,
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'errorMessage' => $error->getMessage()]), 'orderInfoError');
            return json(msg(-22, '', 'orderInfo error!' . $error->getMessage()));
        }
    }

    //结果回调
    public function checkPhoneAmountNotify0076()
    {
        $data = @file_get_contents('php://input');
        $message = json_decode($data, true);
        try {
            logs(json_encode([
                'param' => $message,
                'startTime' => date("Y-m-d H:i:s", time())
            ]), 'checkPhoneAmountNotify0076');
            $validate = new CheckPhoneAmountNotifyValidate();
            if (!$validate->check($message)) {
                return apiJsonReturn(-1, '', $validate->getError());
            }
            $orderModel = new OrderModel();
            $orderWhere['order_no'] = $message['order_no'];  //四方单号
            $orderWhere['account'] = $message['phone'];   //订单匹配手机号
            $orderInfo = $orderModel->where($orderWhere)->find();

            if (empty($orderInfo)) {
                return json(msg(-2, '', '无此订单！'));
            }
            if ($orderInfo['order_status'] == 1) {
                return json(msg(-3, '', '订单已支付！'));
            }
            $db = new Db();
            $checkResult = "第" . ($orderInfo['check_times'] + 1) . "次查询结果" . $message['amount'] . "(" . date("Y-m-d H:i:s") . ")";
            $nextCheckTime = time() + 40;
            if ($orderInfo['check_times'] > 3) {
                $nextCheckTime = time() + 50;
            }
            if ($message['check_status'] != 1) {
                $updateCheckTimesRes = $db::table("bsa_order")->where($orderWhere)
                    ->update([
                        "check_status" => "0",  //查询结束
                        "check_times" => $orderInfo['check_times'] + 1,
                        "next_check_time" => $nextCheckTime,
                        "order_desc" => $checkResult,
                        "check_result" => $checkResult,
                    ]);
                if (!$updateCheckTimesRes) {
                    logs(json_encode(['phone' => $orderInfo['account'],
                        "order_no" => $orderInfo['order_no'],
                        "notifyTime" => date("Y-m-d H:i:s", time()),
                        "getPhoneAmountRes" => $message
                    ]), '0076updateCheckPhoneAmountFail');
                }
                return json(msg(1, '', '接收成功'));
            }
            //查询成功
            $orderWhere['order_no'] = $orderInfo['order_no'];
            $orderUpdate['check_times'] = $orderInfo['check_times'] + 1;
            $orderUpdate['check_status'] = 0;   //可在查询状态
            $orderUpdate['last_check_amount'] = $message['amount'];
            $orderUpdate['next_check_time'] = $nextCheckTime;
            $orderUpdate['check_result'] = $checkResult;
            $updateCheck = $db::table("bsa_order")->where($orderWhere)
                ->update($orderUpdate);
            if (!$updateCheck) {
                logs(json_encode(["time" => date("Y-m-d H:i:s", time()),
                    'action' => "checkNotifySuccess",
                    'order_no' => $message['order_no'],
                    'phone' => $message['account'],
                    "getPhoneAmountRes" => $checkResult
                ]), '0076updateCheckFail');
            }
            //1、支付到账
            if ($message['amount'] > ($orderInfo['end_check_amount'] - 5)) {
                //本地更新
                $orderHXModel = new OrderhexiaoModel();
                $orderHXData = $orderHXModel->where($orderWhere)->find();
                $localUpdate = $orderHXModel->orderLocalUpdate($orderInfo);
                if (!isset($localUpdate['code']) || $localUpdate['code'] != 0) {
                    logs(json_encode(["time" => date("Y-m-d H:i:s", time()),
                        'writeOrderNo' => $orderHXData['order_no'],
                        'account' => $orderHXData['account'],
                        "localUpdateFail" => json_encode($localUpdate)
                    ]), 'checkPhoneAmountNotify0076Fail');

                    return json(msg(1, '', '接收成功,更新失败！'));
                }
                return json(msg(1, '', '接收成功,更新成功！'));
            }
            logs(json_encode([
                "time" => date("Y-m-d H:i:s", time()),
                'noMatchOrder' => $message
            ]), 'MatchOrderFailCheckPhoneAmountNotify0076');
            return json(msg(1, '', '接收成功,匹配失败！'));
        } catch (\Exception $exception) {
            logs(json_encode(['param' => $message,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'errorMessage' => $exception->getMessage()]), 'checkPhoneAmountNotify0076Exception');
            return json(msg(-11, '', '接收异常！'));
        } catch (\Error $error) {
            logs(json_encode(['param' => $message,
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'errorMessage' => $error->getMessage()]), 'checkPhoneAmountNotify0076Error');
            return json(msg(-22, '', "接收错误！"));
        }
    }

}