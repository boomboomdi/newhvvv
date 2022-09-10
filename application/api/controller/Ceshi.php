<?php
/**
 * Created by PhpStorm.
 * User: 75763
 * Date: 2018/12/15
 * Time: 19:53
 */

namespace app\api\controller;

use app\common\model\SpendBalance;
use think\Db;
use think\Controller;
use think\Request;
use app\common\model\OrderModel;
use app\common\model\SystemConfigModel;
use tool\Log;

class Ceshi extends Controller
{
    /**
     * 测试上传核销单
     * @return void
     */
    public function addOrder(Request $request)
    {
        $data = @file_get_contents("php://input");
        $param = json_decode($data, true);
//        if (isset($param['account']) && !empty($param['account'])) {
//            $addData['account'] = $param['account'];
//        } else {
//            $addData['account'] = randomMobile();
//        }

//        var_dump($request->domain());exit;
        for ($i = 0; $i < 5; $i++) {
            $addData['account'] = randomMobile(1);
            //ceshi
            //jfkdakjfhamdfka29u9
            $addData['write_off_sign'] = 'ceshi';
            $token = 'jfkdakjfhamdfka29u9';
            $addData['order_no'] = guid12();
            $addData['order_amount'] = 100;
            $addData['operator'] = '移动';
            $addData['order_type'] = 'HUAFEI';
            $addData['limit_time'] = time() + 21600;
            $addData['notify_url'] = $request->domain() . "/api/ceshi/ordernotify";  //回调地址
            $addData['sign'] = md5($addData['write_off_sign'] . $addData['order_no'] . $addData['account'] . $addData['order_amount'] . $addData['limit_time'] . $addData['notify_url'] . $token);
//            $addData['sign'] = md5($addData['write_off_sign'] . $addData['order_no'] . $addData['account'] . "jfkdakjfhamdfka29u9");
//            $addOrderRes = curlPostJson($request->domain() . "/api/orderhexiao/uploadorder", $addData);
            $addOrderRes = curlGet1($request->domain() . "/api/orderhexiao/uploadorder", 'post', json_encode($addData));
            var_dump($addData);
//            var_dump($request->domain() . "/api/orderhexiao/uploadorder");
            echo "</pre>";
//            exit;
            var_dump($addOrderRes);
//            var_dump($request->domain() . "/api/orderhexiao/uploadorder");
            exit;
            $res = json_decode($addOrderRes);
            if (!isset($res['code']) || $res['code'] != 1) {
                echo "第" . ($i + 1) . "上传失败：" . json_encode($addOrderRes);
            } else {
                echo "第" . ($i + 1) . "上传成功：" . json_encode($addOrderRes);
            }

        }

    }

    /**
     * 测试下单
     * @return void
     */
    public function createOrder(Request $request)
    {
        $data = @file_get_contents("php://input");
        logs(json_encode(['message' => $data, "time" => date("Y-m-d H:i:s", time())]), 'uploadOrder_log');
        $param = json_decode($data, true);
        $addData['merchant_sign'] = "ceshi";
        $addData['order_no'] = guidForSelf();
        $token = '123';
        $addData['amount'] = 100;
        $addData['notify_url'] = $request->domain() . "/api/ceshi/createOrderNotify";
        $addData['payment'] = "微信";
        $addData['time'] = time();
        $addData['sign'] = md5($addData['merchant_sign'] . $addData['order_no'] . $addData['amount'] . $addData['time'] . $token);   //md5(merchant_sign+ order_no+amount+ time+token)
        $addOrderRes = curlGet1($request->domain() . "/api/orderinfo/order", 'post', json_encode($addData));
        var_dump($addData);
//            var_dump($request->domain() . "/api/orderhexiao/uploadorder");
        echo "</pre>/n";
//            exit;
        print_r($addOrderRes);

    }

    /**
     * 测试下单
     * @return void
     */
    public function createOrderNotify(Request $request)
    {
        $data = @file_get_contents("php://input");
        logs(json_encode(['message' => $data, "time" => date("Y-m-d H:i:s", time())]), 'uploadOrder_log');
        $param = json_decode($data, true);
        return "success";
//        $addData['merchant_sign'] = "ceshi";
//        $addData['order_no'] = guidForSelf();
//        $token = '123';
//        $addData['amount'] = 100;
//        $addData['notify_url'] = $request->domain() . "/api/ceshi/createOrderNotify";
//        $addData['payment'] = "微信";
//        $addData['time'] = time();
//        $addData['sign'] = md5($addData['merchant_sign']+$addData['order_no']+$addData['amount']+$addData['time']+$token);   //md5(merchant_sign+ order_no+amount+ time+token)
//        $addOrderRes = curlGet1($request->domain() . "/api/orderhexiao/uploadorder", 'post', json_encode($addData));
//        var_dump($addData);
////            var_dump($request->domain() . "/api/orderhexiao/uploadorder");
//        echo "</pre>";
////            exit;
//        var_dump($addOrderRes);

    }

    public function checkBalance()
    {
        $spendBalance = new SpendBalance();

        $account = 13782396069;

        $pay_type = '微信';
        $orderNo = 'YINHE8888888888';
        $amount = 100;
        $out_trade_no = 100;
        $res = $spendBalance->yinHeBalance($account, $amount, $orderNo, '88888888', $pay_type);
        $data = json_decode($res, true);
        $data =$data['data'];
//        $data = json_decode($data['data'], true);

        var_dump($data);
        exit;
    }
}