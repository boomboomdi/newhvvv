<?php

namespace app\api\controller;

use app\common\model\DeviceModel;
use app\api\model\OrderLog;
use app\api\model\TorderModel;
use app\common\model\OrderModel;
use app\common\model\PayapiModel;
use think\Db;
use think\facade\Log;
use think\Request;
use app\api\validate\OrderaaValidate;
use think\Controller;
use Zxing\QrReader;

class Info extends Controller
{
    /**
     * 下单
     * @param Request $request
     * @return void
     */
    public function order(Request $request)
    {
        $data = @file_get_contents('php://input');
        $message = json_decode($data, true);
        Log::info('order first!', $message);
        $updateParam = [];
        try {
            $validate = new OrderaaValidate();
            if (!$validate->check($message)) {
                return json(msg(-1, '', $validate->getError()));
            }
            $db = new Db();
            //验证商户
            $token = $db::table('bsa_merchant')->where('merchant_sign', '=', $message['merchant_sign'])->find()['token'];
            if (empty($token)) {
                return apiJsonReturn('100161', "商户验证失败！");
            }
            $sig = md5($message['merchant_sign'] . $token . $message['order_no'] . $message['amount'] . $message['time']);
            if ($sig != $message['sign']) {
                Log::info("create_order_10006!", $message);
                return apiJsonReturn('10006', "验签失败！");
            }
            $orderFind = $db::table('bsa_order')->where('order_no', '=', $message['order_no'])->count();
            if ($orderFind > 0) {
                return apiJsonReturn('11001', "单号重复！");
            }

//            $user_id = $message['user_id'];  //用户标识
            // 根据user_id  未付款次数 限制下单 end

            $deviceModel = new DeviceModel();
            $deviceCount = $db::table("bsa_device")
                ->leftJoin("bsa_studio", "bsa_device.studio = bsa_studio.studio")
                ->where([
                    "bsa_device.status" => 1,
                    "bsa_device.device_status" => 1,
                    "bsa_studio.status" => 1,
                ])->count();

            if ($deviceCount == 0) {
                return apiJsonReturn('10009', "设备不足，下单失败!");
            }

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
            $insertOrderData['payment'] = $message['payment']; //alipay
            $insertOrderData['add_time'] = time();  //入库时间
            $insertOrderData['notify_url'] = $message['notify_url']; //下单回调地址 player_name payrealname

            $orderModel = new \app\common\model\OrderModel();
            $createOrderOne = $orderModel->addOrder($insertOrderData);
            if (!isset($createOrderOne['code']) || $createOrderOne['code'] != '0') {
                return apiJsonReturn('10008', $createOrderOne['msg'] . $createOrderOne['code']);
            }
            //2、分配设备

            $getDeviceQrCode = $deviceModel->getZfbUseDevice($insertOrderData);
            if (!isset($getDeviceQrCode['code']) || $getDeviceQrCode['code'] != 0) {
                $updateOrderStatus['qr_url'] = "";
                $updateOrderStatus['order_status'] = 3;
                //修改订单为下单失败状态。
                $updateOrderStatus['update_time'] = time();
                $orderModel->where('order_no', '=', $insertOrderData['order_no'])->update($updateOrderStatus);
                $lastSql = $orderModel->getLastSql();
                logs(json_encode(['getDeviceQrCode' => $getDeviceQrCode, 'updateOrderStatus' => $updateOrderStatus, 'lastSql' => $lastSql]), 'create_order_get_url_fail');
                return apiJsonReturn(10013, $getDeviceQrCode['msg']);
            }

            if ($createOrderOne['code'] == 0 && $getDeviceQrCode['code'] == 0) {

                $updateOrderStatus['account'] = $getDeviceQrCode['data']['account'];
                $updateOrderStatus['qr_url'] = $getDeviceQrCode['data']['qr_url'];
                $updateOrderStatus['order_status'] = 4;
                $orderModel->where('order_no', '=', $insertOrderData['order_no'])->update($updateOrderStatus);
                $baseurl = request()->root(true);
                $orderUrl = $baseurl . "/api/zfbpay?orderNo=" . $insertOrderData['order_me'] . '&oid=' . $insertOrderData['order_no'] . "&amount=" . $insertOrderData['amount'];
                return apiJsonReturn('10000', "下单成功", $orderUrl);
            } else {
                return apiJsonReturn('19999', "设备不足，下单失败!!!");
            }
        } catch (\Error $e) {
            Log::error('order error!', $message);
            return json(msg('-22', '', 'create order error!' . $e->getMessage() . $e->getLine()));
        } catch (\Exception $e) {
            Log::error('order Exception!', $message);
            return json(msg('-11', '', 'create order Exception!' . $e->getMessage() . $e->getFile() . $e->getLine()));
        }
    }

    /**
     *  商户查单接口
     * apiMerchantNo    是    string    商户编码
     * apiMerchantOrderNo    是    string    所查订单编号
     * sign    是    string    签名
     * {
     * apiMerchantNo:76153933,
     * apiMerchantOrderNo:"TA1626885076531"
     * sign:"2151EBF75C25FA24937FF723898294FB"
     * }
     * @return JSON
     *
     * {
     * "code": "2100",
     * "msg": "查询成功",
     * "orderStatus": 0,
     * "officialMsg": "已付款",
     * "amount": 500,
     * "cardNo": "1000111100014749155",
     * "orderCreateDate": "2021-07-22 02:48:50",
     * "orderExpireDate": "2021-07-22 02:58:50",
     * "orderDiscount": 0.9500
     * }
     */
    public function status()
    {
        try {
            $data = @file_get_contents('php://input');
            $param = json_decode($data, true);
            Log::write("/n/t TOrder/status: /n/t" . json_encode($param) . "/n/t", "Log");
            //                var_dump($param);exit();
            if (!isset($param['apiMerchantNo']) || empty($param['apiMerchantNo'])) {
                $returnMsg['code'] = 2002;
                $returnMsg['msg'] = "参数错误!.apiMerchantNo must be require";
                return json_encode($returnMsg);
            }
            if (!isset($param['apiMerchantOrderNo']) || empty($param['apiMerchantOrderNo'])) {
                $returnMsg['code'] = 2002;
                $returnMsg['msg'] = "参数错误!.apiMerchantOrderNo must be require";
                return json_encode($returnMsg);
            }
            if (!isset($param['sign']) || empty($param['sign'])) {
                $returnMsg['code'] = 2002;
                $returnMsg['msg'] = "参数错误!.sign must be require";
                return json_encode($returnMsg);
            }

            $db = new Db();
            $where['merchant_sign'] = $param['apiMerchantNo'];
            //验证商户
            $validatetmerchant = $db::table('bsa_tmerchant')->where($where)->find();
            if (empty($validatetmerchant)) {
                $returnMsg['code'] = 2004;
                $returnMsg['msg'] = "无效商户!";
                $returnMsg['orderStatus'] = 0;
                $returnMsg['officialMsg'] = "";
                $returnMsg['amount'] = "";
                $returnMsg['cardNo'] = "";
                $returnMsg['orderCreateDate'] = date(time());
                $returnMsg['orderExpireDate'] = date(time());
                $returnMsg['orderDiscount'] = "0.00";
                return json_encode($returnMsg);
            }
            //验签
            $orderSign = $param['sign'];
            unset($param['sign']);
            ksort($param);
            $returnMsg = array();
            if ($orderSign != strtoupper(md5(urldecode(http_build_query($param)) . "&key=" . $validatetmerchant['token']))) {
//                var_dump(urldecode(http_build_query($param)) . "&key=" . $validatetmerchant['token']);
//                var_dump(strtoupper(md5(urldecode(http_build_query($param)) . "&key=" . $validatetmerchant['token'])));
//                exit;
                $returnMsg['code'] = 2101;
                $returnMsg['msg'] = "签名无效!";
                return json_encode($returnMsg);
            }
            //查询推单
            $torderWhere['apiMerchantOrderNo'] = $param['apiMerchantOrderNo'];
            $torderWhere['apiMerchantNo'] = $param['apiMerchantNo'];
//            $torderWhere['orderStatus'] = 0;
//            $torderWhere['status'] = 4;
            $tOrderModel = new TorderModel();
            $has = $tOrderModel->getTorderByWhere($torderWhere);
            if ($has['code'] != 0) {
                $returnMsg['code'] = 2102;
                $returnMsg['msg'] = "订单不存在!";
//                $returnMsg['msg'] = "订单不存在!".$db::table("bas_torder")->getLastSql();
                $returnMsg['orderDiscount'] = "0.00";
                $returnMsg['orderExpireDate'] = date(time());
                return json_encode($returnMsg);
            }
            $tOrderData = $has['data'];
            $returnMsg['code'] = 2100;
            $returnMsg['msg'] = "查单成功!";
            $tOrderData['orderStatus'] = 2;
            if ($tOrderData['orderStatus'] == 4) {
                $tOrderData['orderStatus'] = 0;
            }
            if ($tOrderData['orderStatus'] == 1 || $tOrderData['orderStatus'] == 3) {
                $tOrderData['orderStatus'] = 1;
            }
            $returnMsg['orderStatus'] = $tOrderData['orderStatus'];
            $returnMsg['officialMsg'] = $tOrderData['apiMerchantOrderOfficialMsg'];
            $returnMsg['amount'] = $tOrderData['apiMerchantOrderAmount'];
            $returnMsg['cardNo'] = $tOrderData['apiMerchantOrderCardNo'];
            $returnMsg['orderCreateDate'] = date('Y-m-d h:i:s', $tOrderData['orderCreateDate']);
            $returnMsg['orderExpireDate'] = date('Y-m-d h:i:s', $tOrderData['orderExpireDate']);
            $returnMsg['orderDiscount'] = $tOrderData['orderDiscount'];
            return json_encode($returnMsg);
        } catch (\Exception $e) {
            $returnMsg['code'] = 1009;
            $returnMsg['msg'] = "系统错误错误!" . $e->getMessage();
            return json_encode($returnMsg);
        }
    }

    /**
     *  商户by amount 获取接口
     * amount    是    string    商户编码
     * sign    是    string    签名
     * {
     * amount:100
     * }
     * @return JSON
     *
     * {
     * "code": "2100",
     * "msg": "查询成功",
     * "orderStatus": 0,
     * "officialMsg": "已付款",
     * "amount": 500,
     * "cardNo": "1000111100014749155",
     * "orderCreateDate": "2021-07-22 02:48:50",
     * "orderExpireDate": "2021-07-22 02:58:50",
     * "orderDiscount": 0.9500
     * }
     */
    public function get(Request $request)
    {
        try {
            $data = @file_get_contents('php://input');
            $param = json_decode($data, true);
            //                var_dump($param);exit();
            $db = new Db();

            if (!isset($param['amount']) || empty($param['amount'])) {
                $returnMsg['code'] = 2002;
                $returnMsg['msg'] = "参数错误!.apiMerchantOrderAmount must be require";
                return json_encode($returnMsg);
            }
            //查询推单
            $torderWhere['apiMerchantOrderAmount'] = $param['amount'];
            $tOrderModel = new TorderModel();
            $field = "apiMerchantOrderCardNo,apiMerchantOrderNo,apiMerchantOrderAmount";
            $has = $tOrderModel->getTorderForGet($torderWhere, $field);
            if ($has['code'] != 0) {
                $returnMsg['code'] = 2102;
                $returnMsg['msg'] = "无可用 no useful order!";
                return json_encode($returnMsg);
            }
            $db::startTrans();//开启事务
            $db::table('bsa_torder')->where($torderWhere)->lock();
            $updateParam['status'] = 1;
            $db::table('bsa_torder')->where($torderWhere)->update($updateParam);
            $db::commit();

            $returnMsg['code'] = 1000;
            $returnMsg['msg'] = "查单成功!";
//            $returnMsgData = json_encode($has['data']);
//            ltrim($returnMsgData, "[");
//            rtrim($returnMsgData, "]");
//            $returnMsg['data'] = $returnMsgData;
            $returnMsg['data'] = $has['data'];
            return json_encode($returnMsg);
        } catch (\Exception $e) {
            $returnMsg['code'] = 1009;
            $returnMsg['msg'] = "系统错误! system error" . $e->getMessage();
            return json_encode($returnMsg);
        }
    }

    /**
     * 余额
     * @return false|string
     */
    public function balance(Request $request)
    {
        try {
            $param = $request->get();
//            $param = json_decode($data, true);
            if (!isset($param['apiMerchantNo']) || empty($param['apiMerchantNo'])) {
                $returnMsg['code'] = 2002;
                $returnMsg['msg'] = "参数错误!.apiMerchantNo must be require";
                return json_encode($returnMsg);
            }

            if (!isset($param['sign']) || empty($param['sign'])) {
                $returnMsg['code'] = 2002;
                $returnMsg['msg'] = "参数错误!.sign must be require";
                return json_encode($returnMsg);
            }

            $db = new Db();
            $where['merchant_sign'] = $param['apiMerchantNo'];
            //验证商户
            $validatetmerchant = $db::table('bsa_tmerchant')->where($where)->find();
            if (empty($validatetmerchant)) {
                $returnMsg['code'] = 2004;
                $returnMsg['msg'] = "无效商户!";
                $returnMsg['balance'] = "0.00";
                return json_encode($returnMsg);
            }
            //验签
            $orderSign = $param['sign'];
            unset($param['sign']);
            ksort($param);
            $returnMsg = array();
            if ($orderSign != strtoupper(md5(urldecode(http_build_query($param)) . "&key=" . $validatetmerchant['token']))) {
//                var_dump(urldecode(http_build_query($param)) . "&key=" . $validatetmerchant['token']);
//                var_dump(strtoupper(md5(urldecode(http_build_query($param)) . "&key=" . $validatetmerchant['token'])));
//                exit;
                $returnMsg['code'] = 2006;
                $returnMsg['msg'] = "签名无效!";
                return json_encode($returnMsg);
            }
            //查询推单
            $torderWhere['apiMerchantNo'] = $param['apiMerchantNo'];
            $torderWhere['orderStatus'] = 1;
//            $torderWhere['status'] = 1;
            $tOrderModel = new TorderModel();
            $has = $tOrderModel->getTmerchangBalanceByWhere($torderWhere);
//            var_dump($has);exit;
            if ($has['code'] != 0) {
                $returnMsg['code'] = 2102;
                $returnMsg['msg'] = "订单不存在!";
                $returnMsg['balance'] = "0.00";
                return json_encode($returnMsg);
            }
            $tOrderData = $has['data'];
            $returnMsg['code'] = 2100;
            $returnMsg['msg'] = "查单成功!";
            $returnMsg['balance'] = $has['data'];
            return json_encode($returnMsg);
        } catch (\Exception $e) {
            $returnMsg['code'] = 1009;
            $returnMsg['msg'] = "系统错误错误!" . $e->getMessage();
            $returnMsg['balance'] = '0.00';
            return json_encode($returnMsg);
        }
    }

    public function shell_exec1()
    {

//        require __DIR__ . "https://attach.52pojie.cn/forum/202004/01/140413wyewb2uf2yw0xfa3.png";

        $img = "https://attach.52pojie.cn/forum/202004/01/140413wyewb2uf2yw0xfa3.png";
        $qrcode = new QrReader($img);
        $text = $qrcode->text(); //return decoded text from QR Code
        var_dump($text);
        exit;
        $img = "https://attach.52pojie.cn/forum/202004/01/140413wyewb2uf2yw0xfa3.png";
        $res = shel_exec($img);
        var_dump($res);
        exit;
    }


    /**
     * zfb固额下单
     * @return bool|false|string
     */
    public function index()
    {
        $data = @file_get_contents('php://input');
        $message = json_decode($data, true);
        try {
            if (!isset($message['merchant_sign']) || empty($message['merchant_sign'])) {
                return apiJsonReturn('10001', "缺少必要参数:merchant_sign");
            }
            if (!isset($message['order_no']) || empty($message['order_no'])) {
                return apiJsonReturn('10002', "缺少必要参数:order_no");
            }
            if (!isset($message['amount']) || empty($message['amount'])) {
                return apiJsonReturn('10003', "缺少必要参数:amount");
            }
            if (!isset($message['user_id']) || empty($message['user_id'])) {
//                return apiJsonReturn('100001', "缺少必要参数:user_id");
                $message['user_id'] = guidForSelf();
            }
            if (!isset($message['sign']) || empty($message['sign'])) {
                return apiJsonReturn('10004', "缺少必要参数:sig");
            }
            if (!isset($message['time']) || empty($message['time'])) {
                return apiJsonReturn('10005', "缺少必要参数:time");
            }
//            if (isset($message['payrealname'])) {
//                if (!is_string($message['payrealname']) || strlen($message['payrealname']) >= 50) {
//                    $message['payrealname'] = "";
//                }
//            }

            if (is_int($message['amount'])) {
                $message['amount'] = $message['amount'] . ".00";
            }
            $db = new Db();
            //验证商户
            $token = $db::table('bsa_merchant')->where('merchant_sign', '=', $message['merchant_sign'])->find()['token'];
            if (empty($token)) {
                return apiJsonReturn('10016', "验签失败！");
            }
            $sig = md5($message['merchant_sign'] . $token . $message['order_no'] . $message['amount'] . $message['time']);
            if ($sig != $message['sign']) {
                Log::info("create_order_10006!", $message);
                return apiJsonReturn('10006', "验签失败！");
            }
            $orderFind = $db::table('s_order')->where('order_no', '=', $message['order_no'])->count();
            if ($orderFind > 0) {
                return apiJsonReturn('11001', "单号重复！");
            }

            $user_id = $message['user_id'];  //用户标识
            // 根据user_id  未付款次数 限制下单 end

            $deviceModel = new DeviceModel();
            $deviceCount = $db->table("bsa_device")->leftJoin("bsa_studio", "bsa_device.studio=bsa_studio.studio")
                ->where([
                    "bsa_device.status" => 1,
                    "bsa_device.device_status" => 1,
                    "bsa_studio.status" => 1,
                ])->count();
            if ($deviceCount == 0) {
                return apiJsonReturn('10009', "设备不足，下单失败!");
            }

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
            $insertOrderData['payment'] = $message['payment']; //alipay
            $insertOrderData['add_time'] = time();  //入库时间
            $insertOrderData['notify_url'] = $message['notify_url']; //下单回调地址 player_name payrealname

            $orderModel = new \app\common\model\OrderModel();
            $createOrderOne = $orderModel->addOrder($insertOrderData);
            if (!isset($createOrderOne['code']) || $createOrderOne['code'] != '10000') {
                return apiJsonReturn('10008', $createOrderOne['msg']);
            }
            //2、分配设备

            $getDeviceQrCode = $deviceModel->getZfbUseDevice($insertOrderData);
            if (!isset($getDeviceQrCode['code']) || $getDeviceQrCode['code'] != 0) {
                //修改订单为下单失败状态。
                $updateOrderStatus['update_time'] = time();
                if (isset($getDeviceQrCode['data'])) {
                    if (isset($getDeviceQrCode['data']['account']) && !empty($getDeviceQrCode['data']['account'])) {
                        $updateOrderStatus['account'] = $getDeviceQrCode['data']['account'];
                        $updateOrderStatus['qr_url'] = $getDeviceQrCode['data']['qr_url'];
                    }
                }
                $orderModel->where('order_no', '=', $insertOrderData['order_no'])->update($updateOrderStatus);
                $lastSql = $orderModel->getLastSql();
                logs(json_encode(['getDeviceQrCode' => $getDeviceQrCode, 'updateOrderStatus' => $updateOrderStatus, 'lastSql' => $lastSql]), 'create_order_get_url_fail');
                return apiJsonReturn(10013, $getDeviceQrCode['msg']);
            }

            if ($createOrderOne['code'] == 0 && $getDeviceQrCode['code'] == 0) {

                $updateOrderStatus['account'] = $getDeviceQrCode['data']['account'];
                $updateOrderStatus['qr_url'] = $getDeviceQrCode['data']['qr_url'];
                $orderModel->where('order_no', '=', $insertOrderData['order_no'])->update($updateOrderStatus);
                $baseurl = request()->root(true);
                $orderUrl = $baseurl . "/api/zfbpay?orderNo=" . $insertOrderData['order_no'] . '&oid=' . $insertOrderData['order_no'] . "&amount=" . $insertOrderData['amount'];
                return apiJsonReturn('10000', "下单成功", $orderUrl);
            } else {
                return apiJsonReturn('19999', "设备不足，下单失败!!!");
            }
        } catch (\Exception $exception) {
            logs(json_encode(['message' => $message, 'file' => $exception->getFile(), 'line' => $exception->getLine(), 'errorMessage' => $exception->getMessage()]), 'create_order_exception');
            return apiJsonReturn('20009', "通道异常" . $exception->getMessage());
        } catch (\Error $error) {
            logs(json_encode(['message' => $message, 'file' => $error->getFile(), 'line' => $error->getLine(), 'errorMessage' => $error->getMessage()]), 'create_order_error');
            return apiJsonReturn('20099', "通道异常" . $error->getMessage());
        }
    }

    

    public function testDouyinOrder()
    {
        $data = @file_get_contents('php://input');
        $message = json_decode($data, true);
        $createParam = [];
        if (isset($message['amount']) && is_float($message['amount'])) {
            $createParam['amount'] = $message['amount'];
        } else {
            $createParam['amount'] = 1;
        }
        $createParam['ck'] = "s_v_web_id=verify_l1ajlgnu_Ymx30kZZ_X9uJ_4fWe_9SOY_2hnr2ZGDdcIW;  passport_csrf_token=7b720517a639c63b2f9e93def8d8b51c;  passport_csrf_token_default=7b720517a639c63b2f9e93def8d8b51c;  d_ticket=4613d00a0d7cee49e5400f5cde943a0cea9a6;  n_mh=ZTZFiiWKrzklhzViHoddljtlDeDE1CwtPULaZy5Qnoo;  sso_auth_status=5e69e268b88b8737cd1abd7da22fc53d;  sso_auth_status_ss=5e69e268b88b8737cd1abd7da22fc53d;  sso_uid_tt=790456e9b3db8f990fab6904c307ba34;  sso_uid_tt_ss=790456e9b3db8f990fab6904c307ba34;  toutiao_sso_user=2c6b07168edbc9133a5c61075bfe4359;  toutiao_sso_user_ss=2c6b07168edbc9133a5c61075bfe4359;  uid_tt=790456e9b3db8f990fab6904c307ba34;  uid_tt_ss=790456e9b3db8f990fab6904c307ba34;  sid_tt=2c6b07168edbc9133a5c61075bfe4359;  sessionid=2c6b07168edbc9133a5c61075bfe4359;  sessionid_ss=2c6b07168edbc9133a5c61075bfe4359;  ttcid=16029a0e6cf94a0783115b09ddff479d21;  passport_auth_status=2b4819934a1fe404466e3c93d7b88f01%2C39c8ece5d0de8319169cdf3d2e921ca2;  passport_auth_status_ss=2b4819934a1fe404466e3c93d7b88f01%2C39c8ece5d0de8319169cdf3d2e921ca2;  sid_ucp_sso_v1=1.0.0-KGVmMWZlNzE2ZDk0YTk3MWUxMTlhMjIxM2U2YmQ0YzgyYjVjODYzOWEKHwjAr5CWlvTTAxCDkYaSBhiWTiAMMJj62PgFOAJA8QcaAmxmIiAyYzZiMDcxNjhlZGJjOTEzM2E1YzYxMDc1YmZlNDM1OQ;  ssid_ucp_sso_v1=1.0.0-KGVmMWZlNzE2ZDk0YTk3MWUxMTlhMjIxM2U2YmQ0YzgyYjVjODYzOWEKHwjAr5CWlvTTAxCDkYaSBhiWTiAMMJj62PgFOAJA8QcaAmxmIiAyYzZiMDcxNjhlZGJjOTEzM2E1YzYxMDc1YmZlNDM1OQ;  sid_guard=2c6b07168edbc9133a5c61075bfe4359%7C1648461956%7C5184000%7CFri%2C+27-May-2022+10%3A05%3A56+GMT;  sid_ucp_v1=1.0.0-KDliNGM3ZmE4ZjNjYWEyYjlkOWJkNjgxNzc3Y2JlMmIzMDY2ZWE3OWYKHwjAr5CWlvTTAxCEkYaSBhiWTiAMMJj62PgFOAJA8QcaAmxxIiAyYzZiMDcxNjhlZGJjOTEzM2E1YzYxMDc1YmZlNDM1OQ;  ssid_ucp_v1=1.0.0-KDliNGM3ZmE4ZjNjYWEyYjlkOWJkNjgxNzc3Y2JlMmIzMDY2ZWE3OWYKHwjAr5CWlvTTAxCEkYaSBhiWTiAMMJj62PgFOAJA8QcaAmxxIiAyYzZiMDcxNjhlZGJjOTEzM2E1YzYxMDc1YmZlNDM1OQ;  odin_tt=34334635d6a6a8c364c19ee740a5da612096c9d8917be3e495d70415ae2d465dfdaf38d782e7a55e6026356ca404b3090eb9946f090f42057d97b73362d3c475;  tt_scid=f.8YP8XlAJN-oZyDXg56yh4jgOdmHGTgl6hZcRcHIbZoE2aS8hqa6xRwwp.MWZUi3585;  MONITOR_WEB_ID=501d02a1-aac5-44e9-b579-7d2883001e75;  msToken=sfUpbhPxWfs7Oo8qNXK2nDEduQtDdWAQmzZEhrYQ-s7wyZiKYtLT-V0vb_BgKvuaFZ7AGPt4iGQ7GoEoYae5DjsJSKVrg9eEzMOCmvNPMCzU2agsIMODmD7h3yrzLT5x;  ttwid=1%7CKjghJAYvBrbaKjmZ6-KFD5N9RAVaTxkrKE7qJl8zfrs%7C1648647933%7C8544ccc36224113fef831f8cb73c8c38f533de1edc0d0cc3b0043459991a84ef;  msToken=t_2TgsXuhnmOhhsQD3TW3erdpaGbXQwCD9kLPuydY06C25zCAD0w0DHisbm5Fff4AlQSN-ve8NxCTrrdiocLLKgnJZv5vejkv0us9C7o9-iMkCoHMUZQFLZoRzdUyvnr; ";
        $createParam['account'] = 13283544162;
        $notifyResult = curlPostJson("http://127.0.0.1:23946/createOrder", $createParam);

        Log::log('1', "notify merchant order ".json_encode($notifyResult));
        $result = json_decode($notifyResult, true);
        var_dump($result);exit;
    }
}