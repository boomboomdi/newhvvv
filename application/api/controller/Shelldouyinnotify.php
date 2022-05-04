<?php

namespace app\api\controller;

use app\admin\model\CookieModel;
use app\common\model\DeviceModel;
use app\common\model\OrderdouyinModel;

//use app\common\model\SystemConfigModel;
use app\common\model\SystemConfigModel;
use think\Db;
use think\facade\Log;
use think\Request;
use think\Controller;
use Zxing\QrReader;

class Shelldouyinnotify extends Controller
{
    /**
     * 下单  抖音
     * @param Request $request
     * @return void
     */
    public function Timecheckdouyinhuadan()
    {

        $totalNum = 0;
        $errorNum = 0;
        $doNum = 0;
        $orderData = [];
        try {
            $limit = 10;
            $limitTime = SystemConfigModel::getDouyinPayLimitTime();
//            $limitTime = 900;
            $now = time();
            $lockLimit = $now - $limitTime;
            $orderModel = new OrderdouyinModel();
//            $where[] = ['order_status', "!=", '1'];
//            $where[] = ['notify_status', "!=", '0'];
//            $where[] = ['add_time', "<", $lockLimit];
            //查询下单之前280s 到现在之前20s的等待付款订单
//            $updateData = $orderModel->where('add_time', '<', $lockLimit)->where($updateDataWhere)->select();

            $orderData = $orderModel
                ->where('order_status', '<>', 1)
                ->where('notify_status', '=', 0)
                ->where('add_time', '<', $lockLimit)
                ->select();
            $totalNum = count($orderData);
            if ($totalNum > 0) {
                logs(json_encode(['orderData' => $orderData, 'totalNum' => $totalNum, 'getLastSql' => Db::table('bsa_torder_douyin')->getLastSql()]), 'Timecheckdouyinhuadanfordata');
                foreach ($orderData as $k => $v) {
                    //请求查单接口
                    $orderModel->orderDouYinNotifyToWriteOff($v);
                    $doNum++;
                }
            }
            echo "Timecheckdouyinhuadan:订单总数" . $totalNum . "失败" . $errorNum;
        } catch (\Exception $exception) {
            logs(json_encode(['totalNum' => $totalNum, 'file' => $exception->getFile(), 'line' => $exception->getLine(), 'errorMessage' => $exception->getMessage()]), 'Timecheckdouyinhuadanexception');
            echo "Timecheckdouyinhuadan:订单总数" . $totalNum . "exception" . json_encode($orderData);
        } catch (\Error $error) {
            logs(json_encode(['totalNum' => $totalNum, 'file' => $error->getFile(), 'line' => $error->getLine(), 'errorMessage' => $error->getMessage()]), 'Timecheckdouyinhuadanerror');
            echo "Timecheckdouyinhuadan:订单总数" . $totalNum . "error" . json_encode($orderData);
        }
    }

    /**
     * 预拉单
     * @return void
     */
    public function Prepareorder()
    {
        $totalNum = 0;
        $successNum = 0;
        $errorNum = 0;
        $msg = "";
        $db = new Db();
        try {
            //时间差  话单时间差生成订单时间差
            $limitTime = SystemConfigModel::getTorderLimitTime();
            $now = time();

//            getUseCookie
            $orderDouYinModel = new OrderdouyinModel();
            //下单金额
            $prepareWhere['status'] = 1;
            $prepareAmountList = $db::table("bsa_prepare_set")->where($prepareWhere)->select();
            logs(json_encode(['prepareAmountList' => $prepareAmountList]), 'PrepareorderapiStart');

            if (count($prepareAmountList) > 0) {
                foreach ($prepareAmountList as $k => $v) {
                    if (($v['prepare_num'] - $v['can_use_num']) > 0) {
//                        logs(json_encode(['totalNum' => $totalNum, 'prepareAmountList' => $prepareAmountList]), 'Prepareorderapi');
                        for ($i = 1; $i < ($v['prepare_num'] - $v['can_use_num']); $i++) {
                            $res = $orderDouYinModel->createOrder($v, ($v['prepare_num'] - $v['can_use_num']));
                            logs(json_encode(['num' => $v['prepare_num'] - $v['can_use_num'], 'amount' => $v['order_amount'], 'res' => $res]), 'Prepareorderapi');

                            if ($res['code'] == 0 && $res['data'] > 0) {
                                $prepareSetWhere['id'] = $v['id'];
                                $db::table("bsa_prepare_set")->where($prepareSetWhere)->update(['can_use_num' => $v['can_use_num'] + $res['data']]);
                                $msg .= "金额:" . $v['order_amount'] . $res['msg'] . "(" . $res['data'] . "个)||/r/n";
                            } else {
                                $msg .= "金额:" . $v['order_amount'] . $res['msg'] . "(" . $res['data'] . "个)||/r/n";
                            }
                        }
                    }
                }
            }
            echo "Prepareorder:订单总数" . $msg;
        } catch (\Exception $exception) {
            logs(json_encode(['file' => $exception->getFile(), 'line' => $exception->getLine(), 'errorMessage' => $exception->getMessage()]), 'Timedevice exception');
            echo "Prepareorder:总应强制超时订单数" . $totalNum . "exception" . $exception->getMessage();
//            $output->writeln("Prepareorder:总应强制超时订单数" . $totalNum . "exception");
        } catch (\Error $error) {
            logs(json_encode(['file' => $error->getFile(), 'line' => $error->getLine(), 'errorMessage' => $error->getMessage()]), 'Timedevice  error');

            echo "Prepareorder:" . $totalNum . "exception" . $error->getMessage();
            //            $output->writeln("Prepareorder:总应强制超时订单数" . $totalNum . "error");
        }
    }

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

        Log::log('1', "notify merchant order " . json_encode($notifyResult));
        $result = json_decode($notifyResult, true);
        var_dump($result);
        exit;
    }
}