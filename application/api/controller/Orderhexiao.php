<?php

namespace app\api\controller;

use app\api\validate\OrderhexiaoValidate;
use app\admin\model\WriteoffModel;
use app\common\model\OrderhexiaoModel;
use app\common\model\OrderexceptionModel;

use think\Db;
use think\facade\Log;
use think\Request;
use think\Controller;
use Zxing\QrReader;

class Orderhexiao extends Controller
{

    /**
     * 核销商上传推单
     * @return \think\response\Json
     */
    public function uploadOrder()
    {

        $data = @file_get_contents("php://input");
        $param = json_decode($data, true);
        logs(json_encode(['message' => $param, "time" => date("Y-m-d H:i:s", time())]), 'uploadOrder_log');
        $orderExceptionModel = new OrderexceptionModel();
        try {
            $validate = new OrderhexiaoValidate();
            if (!$validate->scene('uploadOrder')->check($param)) {
                return json(msg(-1, '', $validate->getError()));
            }
            $operatorArray[] = '移动';
            $operatorArray[] = '联通';
            $operatorArray[] = '电信';
            //operator 运营商  in_array($input,$allowedChars)
            if (!in_array($param['operator'], $operatorArray)) {
                return json(msg(-1, '', "运营商格式错误"));
            }

            //验签
            $writeOffModel = new WriteoffModel();
            $writeOff = $writeOffModel->where(['write_off_sign' => $param['write_off_sign']])->find();
            if (empty($writeOff)) {
                $exception['order_no'] = $param['order_no'];
                $exception['content'] = $data;
                $exception['action_result'] = 'Useless write-off';
                $exception['desc'] = "核销售商不存在！";
                $orderExceptionModel->addLog($param['write_off_sign'], 'uploadOrder', $exception);
                return json(msg(-2, '', 'Useless write-off'));
            }
            $md5Sting = $param['write_off_sign'] . $param['order_no'] . $param['account'] . $param['order_amount'] . $param['limit_time'] . $param['notify_url'] . $writeOff['token'];
            $doMd5 = md5($md5Sting);
            if (md5($param['write_off_sign'] . $param['order_no'] . $param['account'] . $param['order_amount'] . $param['limit_time'] . $param['notify_url'] . $writeOff['token']) != $param['sign']) {
//                logs(json_encode(['param' => $param, 'md5Sting' => $md5Sting, 'md5' => $doMd5]), 'uploadOrder_md5');
                $exception['order_no'] = $param['order_no'];
                $exception['action_result'] = 'check sign fail!';
                $exception['content'] = json_encode(['param' => $param, 'md5Sting' => $md5Sting, 'md5' => $doMd5]);
                $exception['desc'] = "上传签名有误！";
                $orderExceptionModel->addLog($param['write_off_sign'], 'uploadOrder', $exception);
                return json(msg(-3, '', 'check sign fail!'));
            }
            $orderHeXModel = new OrderhexiaoModel();

            $addParam = $param;
            unset($addParam['sign']);
            $addParam['add_time'] = time();
            $addParam['status'] = 0;
            $where['account'] = $param['account'];
            $where['order_no'] = $param['order_no'];

            $res = $orderHeXModel->addOrder($where, $addParam);

            if ($res['code'] != 0) {
                $exception['order_no'] = $param['order_no'];
                $exception['content'] = json_encode(['param' => $param, 'md5Sting' => $md5Sting, 'md5' => $doMd5]);
                $exception['action_result'] = json_encode($res);
                $exception['desc'] = "上传签名有误！";
                $orderExceptionModel->addLog($param['write_off_sign'], 'uploadOrder', $exception);
//                logs(json_encode(['addParam' => $addParam, 'addRes' => $res, "time" => date("Y-m-d H:i:s", time())]), 'uploadOrder_log');
                return json(msg('-4', '', $res['msg']));
            }
////            $returnData['code'] = 1;
//            $returnData['order_no'] = $param['order_no'];

            return json(msg(1, '', "success"));

        } catch (\Exception $exception) {
            logs(json_encode(['param' => $param, 'file' => $exception->getFile(), 'line' => $exception->getLine(), 'errorMessage' => $exception->getMessage()]), 'uploadOrder_exception');
            return json(msg('-11', '', '下单异常:uploadOrder_exception!' . $exception->getMessage()));
        } catch (\Error $error) {
            logs(json_encode(['param' => $param, 'file' => $error->getFile(), 'line' => $error->getLine(), 'errorMessage' => $error->getMessage()]), 'uploadOrder_error');
            return json(msg('-22', '', '下单异常:uploadOrder_exception!' . $error->getMessage()));
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