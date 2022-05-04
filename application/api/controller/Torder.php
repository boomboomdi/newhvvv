<?php

namespace app\api\controller;

use app\admin\model\WriteoffModel;
use app\api\validate\OrderdouyinValidate;
use app\common\model\OrderdouyinModel;
use app\common\model\OrderModel;
use app\common\model\SystemConfigModel;
use think\Db;
use think\facade\Log;
use think\Request;
use think\Controller;
use Zxing\QrReader;

class Torder extends Controller
{

    /**
     * 核销商上传推单
     * @param Request $request
     * @return void
     */
    public function uploadOrder()
    {
//        $aa = $request->param();
//        Log::log('douyin upload order first test!', $aa);

        $data = @file_get_contents("php://input");
//        $data = @file_get_contents('php://input');
        $param = json_decode($data, true);
//        var_dump($param);exit;
        logs(json_encode(['message' => $param, "time" => date("Y-m-d H:i:s", time())]), 'uploadOrder_log');

//        Log::log('douyin upload order first!', $param);
        try {
            $validate = new OrderdouyinValidate();
            if (!$validate->check($param)) {
                return json(msg(-1, '', $validate->getError()));
            }

            //验签
            $writeOffModel = new WriteoffModel();
            $writeOff = $writeOffModel->where(['write_off_sign' => $param['write_off_sign']])->find();
            if (empty($writeOff)) {
                return json(msg(-2, '', 'Useless write-off'));
            }
            $md5Sting = $param['write_off_sign'] . $param['order_no'] . $param['account'] . $param['total_amount'] . $param['limit_time'] . $param['notify_url'] . $writeOff['token'];
            $doMd5 = md5($md5Sting);
            if (md5($param['write_off_sign'] . $param['order_no'] . $param['account'] . $param['total_amount'] . $param['limit_time'] . $param['notify_url'] . $writeOff['token']) != $param['sign']) {
                logs(json_encode(['param' => $param, 'md5Sting' => $md5Sting, 'md5' => $doMd5]), 'uploadOrder_md5');
                return json(msg(-3, '', 'fuck you!'));
            }
            $orderDouYinModel = new OrderdouyinModel();
//            $addParam['add_time'] = date("Y-m-d H:i:s", time());

            $addParam = $param;
            unset($addParam['sign']);
            $addParam['add_time'] = time();
            $addParam['status'] = 0;
            $where['account'] = $param['account'];
            $where['order_no'] = $param['order_no'];
            $limitTime = SystemConfigModel::getTorderPrepareLimitTime();
            $payLimitTime = SystemConfigModel::LimitTime();    //默认900s
            if (is_int(strtotime($param['limit_time']))) {
                $addParam['limit_time_1'] = strtotime($param['limit_time']);   //最终回调时间时间戳
                $addParam['limit_time_2'] = strtotime($param['limit_time']) - time();  //最终回调时间与当前时间间隔
                $addParam['prepare_limit_time'] = strtotime($param['limit_time']) - 300;   //预拉单限制终止时间
            } else {
                $addParam['limit_time_1'] = time() + $payLimitTime;   //最终回调时间
                $addParam['limit_time_2'] = $payLimitTime;    //最终回调时间与当前时间间隔
                $addParam['prepare_limit_time'] = time() + ($payLimitTime - $limitTime);  //预拉单限制终止时间  limit_time-300
            }

            $res = $orderDouYinModel->addOrder($where, $addParam);

            if ($res['code'] != 0) {
                logs(json_encode(['addParam' => $addParam, 'addRes' => $res, "time" => date("Y-m-d H:i:s", time())]), 'uploadOrder_log');

                return json(msg('-4', '', $res['msg']));
            }
            $returnData['code'] = 1;
            $returnData['order_no'] = $param['order_no'];

            return json(msg(1, '', "success"));

        } catch (\Exception $e) {
            Log::error('uploadOrder error!', $param);
            return json(msg('-11', '', 'saveBase64toImg error!' . $e->getMessage()));
        }
    }

    /**
     * 推单查询状态
     */
    public function orderInfo(Request $request)
    {
        $param = $request->param();
        $data = @file_get_contents('php://input');
        $param = json_decode($data, true);
        Log::info('douyin orderInfo first!', $param);
        try {
            $validate = new OrderdouyinValidate();
            if (!$validate->scene("order_info")->check($param)) {
                return json(msg(-1, '', $validate->getError()));
            }

            //验签
            $writeOffModel = new WriteoffModel();
            $writeOff = $writeOffModel->where(['write_off_sign' => $param['write_off_sign']])->find();
            if (empty($writeOff)) {
                return json(msg(-1, '', '错误的核销商'));
            }
            if (md5($param['write_off_sign'] . $param['order_no'] . $param['account'] . $writeOff['token']) != $param['sign']) {
                return json(msg(-1, '', 'fuck you!'));
            }
            $orderDouYinModel = new OrderdouyinModel();
            $where['account'] = $param['account'];
            $where['order_no'] = $param['order_no'];
            $res = $orderDouYinModel->getTorderInfo($where);

            if ($res['code'] != 0) {
                return json(msg('-2', $where['order_no'], $res['msg']));
            }
//            $data['order_status'] = $res['data']['order_status']; // 0：等待付款(使用中)1：已付款2：未到账(使用中) 4：未使用
//            $data['success_amount'] = $res['data']['success_amount']; // 付款金额  1 整型
            if (isset($res['data']['order_status']) && $res['data']['order_status'] != 1) {
                if (isset($res['data']['status']) && $res['data']['status'] == 2) {
                    $res['data']['order_status'] = 5;
                }
            }
            return json(msg($res['data']['order_status'], $where['order_no'], "success"));

        } catch (\Exception $exception) {
            logs(json_encode(['param' => $param, 'file' => $exception->getFile(), 'line' => $exception->getLine(), 'errorMessage' => $exception->getMessage()]), 'orderInfo_exception');
            return apiJsonReturn('-11', "orderInfo exception!" . $exception->getMessage());
        } catch (\Error $error) {
            logs(json_encode(['param' => $param, 'file' => $error->getFile(), 'line' => $error->getLine(), 'errorMessage' => $error->getMessage()]), 'orderInfo_error');
            return json(msg('-22', '', 'orderInfo error!' . $error->getMessage()));
        }
    }

    public function getStrTest()
    {

    }

}