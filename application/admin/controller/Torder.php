<?php
/**
 * Created by PhpStorm.
 * User: bl
 * Date: 2020/12/20
 * Time: 12:57
 */

namespace app\admin\controller;

use app\admin\model\TorderModel;
use think\Db;
use tool\Log;

class Torder extends Base
{
    //订单列表
    public function index()
    {
        if (request()->isAjax()) {

            $limit = input('param.limit');
            $apiMerchantOrderNo = input('param.apiMerchantOrderNo');
            $startTime = input('param.start_time');
            $endTime = input('param.end_time');

            $where = [];
            if (!empty($apiMerchantOrderNo)) {
//                $where[] = ['apiMerchantOrderNo', 'like', $apiMerchantOrderNo . '%'];
                $where[] = ['apiMerchantOrderNo', '=', $apiMerchantOrderNo];
            }
//            if (!empty($s tartTime)) {
//                $where[] = ['add_time', '>', strtotime($startTime)];
//            }
//            if (!empty($endTime)) {
//                $where[] = ['add_time', '<', strtotime($endTime)];
//            }
            $TroderModel = new TorderModel();
            $list = $TroderModel->getTorders($limit, $where);
            $data = $list['data'];
            foreach ($data as $key => $vo) {
                if (!empty($data[$key]['orderStatus']) && $data[$key]['orderStatus'] == '4') {
                    $data[$key]['orderStatus'] = '<button class="layui-btn layui-btn-info layui-btn-xs">未使用</button>';
                }
                if ($data[$key]['orderStatus'] == '1') {
                    $data[$key]['orderStatus'] = '<button class="layui-btn layui-btn-success layui-btn-xs">付款成功</button>';
                } else if ($data[$key]['orderStatus'] == '2') {
                    $data[$key]['orderStatus'] = '<button class="layui-btn layui-btn-important layui-btn-xs">付款失败</button>';
                } else if ($data[$key]['orderStatus'] == '3') {
                    $data[$key]['orderStatus'] = '<button class="layui-btn layui-btn-success layui-btn-xs">已手动回调</button>';
                } else if ($data[$key]['orderStatus'] == '5') {
                    $data[$key]['orderStatus'] = '<button class="layui-btn layui-btn-danger layui-btn-xs">已失败回调</button>';
                } else if ($data[$key]['orderStatus'] == '6') {
                    $data[$key]['orderStatus'] = '<button class="layui-btn layui-btn-disabled layui-btn-xs">支付回调成功</button>';
                }else{
                    $data[$key]['orderStatus'] = '<button class="layui-btn layui-btn-disabled layui-btn-xs">等待付款</button>';
                }
                $data[$key]['apiMerchantOrderDate'] = date('Y-m-d H:i:s', $data[$key]['apiMerchantOrderDate']);
                $data[$key]['orderCreateDate'] = date('Y-m-d H:i:s', $data[$key]['orderCreateDate']);
            }
            $list['data'] = $data;
            if (0 == $list['code']) {
                return json(['code' => 0, 'msg' => 'ok', 'count' => $list['data']->total(), 'data' => $list['data']->all()]);
            }

            return json(['code' => 0, 'msg' => 'ok', 'count' => 0, 'data' => []]);
        }

        return $this->fetch();
    }

    /**
     * 删除商户
     * @return \think\response\Json
     */
    public function delTorder()
    {
        if (request()->isAjax()) {
            $tId = input('param.t_id');
            $torderModel = new TorderModel();
            $res = $torderModel->delTorder($tId);
            Log::write("删除单：" . $tId);
            return json($res);
        }
    }

    /**
     * 修改设备状态
     */
    public function changestatus()
    {
        $t_id = input('param.t_id');
        $TorderModel = new TorderModel();
        try {
            $list = $TorderModel
                ->where('t_id', '=', $t_id)->find();
            $torder = session('username');
            //在线设备可以修改启用与否
            if ($list['orderStatus'] != '4') {
                return json(msg(0, '', '已使用订单无法操作！'));
            }
            if ($list['status'] == '1') {
                $updateData['status'] = 2;
                $result = $TorderModel
                    ->where('t_id', '=', $t_id)
                    ->update($updateData);
                if ($result) {
                    return json(msg(0, '', '修改成功！,已禁用'));
                }
            } else {
                $updateData['status'] = 1;
                $result = $TorderModel
                    ->where('t_id', '=', $t_id)
                    ->update($updateData);
                if ($result) {
                    return json(msg(0, '', '修改成功！,已启用'));
                }
            }
        } catch (\Exception $e) {
            return json(msg(-2, '', $e->getMessage()));
        }
    }

    /**
     * 失败回调
     * @return \think\response\Json
     */
    public function notifyfail()
    {

        if (request()->isAjax()) {
            $tId = input('param.t_id');
            $torderModel = new TorderModel();
            $db = new Db();
            $torder = $db::table('bsa_torder')->where('t_id', $tId)->find();
            if ($torder['orderStatus'] == 5) {
                return json(modelReMsg(-2, '', '订单已失败回调成功，无须再次回调!'));
            }
            $res = $torderModel->tOrderNotifyForFail($torder['apiMerchantOrderNo']);
            Log::write("手动回调" . json_encode($torder));
            return json($res);
        } else {
            $tId = input('param.t_id');
            $torderModel = new TorderModel();
            $db = new Db();
            $torder = $db::table('bsa_torder')->where('t_id', $tId)->find();
            $res = $torderModel->tOrderNotifyForFail($torder['apiMerchantOrderNo']);
            Log::write("手动回调" . json_encode($torder));
            return json($res);
        }
    }

    /**
     * 成功回调
     * @return \think\response\Json
     */
    public function notifysuccess()
    {

        if (request()->isAjax()) {
            $tId = input('param.t_id');
            $torderModel = new TorderModel();
            $db = new Db();
            $torder = $db::table('bsa_torder')->where('t_id', $tId)->find();
            if ($torder['orderStatus'] == 6) {
                return json(modelReMsg(-2, '', '订单已成功回调，无须再次回调!'));
            }
            $res = $torderModel->tOrderNotifyForSuccess($torder['apiMerchantOrderNo']);
            Log::write("手动回调" . json_encode($torder));
            return json($res);
        } else {
            $tId = input('param.t_id');
            $torderModel = new TorderModel();
            $db = new Db();
            $torder = $db::table('bsa_torder')->where('t_id', $tId)->find();
            if ($torder['orderStatus'] == 6) {
                return json(modelReMsg(-2, '', '订单已成功回调，无须再次回调!'));
            }
            $res = $torderModel->tOrderNotifyForSuccess($torder['apiMerchantOrderNo']);
            Log::write("手动回调" . json_encode($torder));
            return json($res);
        }
    }
}