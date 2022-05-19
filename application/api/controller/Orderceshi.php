<?php

namespace app\api\controller;


use app\admin\model\WriteoffModel;
use app\api\validate\OrderhexiaoValidate;
use app\common\model\OrderexceptionModel;
use app\common\model\SystemConfigModel;
use think\Controller;
use think\Db;
use app\common\model\OrderhexiaoModel;
use app\common\Redis;
use think\Request;

class Orderceshi extends Controller
{
    /**
     * 核销商上传推单
     * @return \think\response\Json
     */
    public function uploadOrder()
    {
        $redis = new Redis();
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

            //查询是否已经有有相同手机号
            $isHasWhere['account'] = $param['account'];
            $isHasWhere['notify_status'] = 0;
            $isHas = $orderHeXModel->where($isHasWhere)->find();
            if ($isHas) {
                $exception['order_no'] = $param['order_no'];
                $exception['action_result'] = 'check sign fail!';
                $exception['content'] = json_encode(['param' => $param]);
                $exception['desc'] = "有未回调订单" . $isHas['order_no'];
                $orderExceptionModel->addLog($param['write_off_sign'], 'uploadOrder', $exception);
                return json(msg(-4, '', '该账号有未回调订单!'));
            }

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
                return json(msg(-5, '', $res['msg']));
            }
////            $returnData['code'] = 1;
//            $returnData['order_no'] = $param['order_no'];
            if (($param['limit_time'] - time()) > 420) ;
            $hxOrderKey = $param['write_off_sign'] . "" .
                $redis->lpush($param['write_off_sign'] . "upload", ($param['limit_time'] - time()));
            return json(msg(1, '', "success"));

        } catch (\Exception $exception) {
            logs(json_encode(['param' => $param, 'file' => $exception->getFile(), 'line' => $exception->getLine(), 'errorMessage' => $exception->getMessage()]), 'uploadOrder_exception');
            return json(msg('-11', '', '下单异常:uploadOrder_exception!' . $exception->getMessage()));
        } catch (\Error $error) {
            logs(json_encode(['param' => $param, 'file' => $error->getFile(), 'line' => $error->getLine(), 'errorMessage' => $error->getMessage()]), 'uploadOrder_error');
            return json(msg('-22', '', '下单异常:uploadOrder_exception!' . $error->getMessage()));
        }
    }
    public function ceshi1(Request $request)
    {
        $autoCheckOrderTime = SystemConfigModel::getAutoCheckOrderTime();
        var_dump($autoCheckOrderTime);exit;
//        $rootPath = $request->domain();
//        var_dump($rootPath);exit;
    }
}