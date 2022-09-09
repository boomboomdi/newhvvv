<?php
/**
 * Created by PhpStorm.
 * User: 75763
 * Date: 2018/12/15
 * Time: 19:53
 */

namespace app\api\controller;

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

        for ($i = 0; $i < 5; $i++) {
            $addData['account'] = randomMobile(1);
            //ceshi
            //jfkdakjfhamdfka29u9
            $addData['write_off_sign'] = 'ceshi';
            $addData['order_no'] = guid12();
            $addData['order_amount'] = 100;
            $addData['operator'] = '移动';
            $addData['order_type'] = 'HUAFEI';
            $addData['limit_time'] = time() + 21600;
            $addData['notify_url'] = "http://" . $request->ip() . ":8808/api/ceshi/ordernotify";  //回调地址
            $addData['sign'] = md5($addData['write_off_sign'] . $addData['order_no'] . $addData['account'] . "jfkdakjfhamdfka29u9");
            $addOrderRes = curlPost("http://" . $request->ip() . ":8808/api/orderhexiao/uploadorder", $addData);
            $res = json_decode($addOrderRes);
            if (!isset($res['code']) || $res['code'] != 1) {
                echo "第".($i+1)."上传失败：".json_encode($addOrderRes);
            }else{
                echo "第".($i+1)."上传成功：".json_encode($addOrderRes);
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
        $param = json_decode($data, true);
        if (isset($param['account']) && !empty($param['account'])) {
            $createOrderData['account'] = $param['account'];
        } else {
            $createOrderData['account'] = randomMobile();
        }
    }

}