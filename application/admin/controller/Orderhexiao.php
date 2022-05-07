<?php
/**
 * Created by PhpStorm.
 * User: bl
 * Date: 2020/12/20
 * Time: 12:57
 */

namespace app\admin\controller;

use think\Db;
use tool\Log;

use app\admin\model\Orderhexiaomodel;

class Orderhexiao extends Base
{
    //核销单列表
    public function index()
    {
        if (request()->isAjax()) {

            $limit = input('param.limit');
//            $apiMerchantOrderNo = input('param.apiMerchantOrderNo');
            $order_no = input('param.order_no');
            $order_me = input('param.order_me');
            $order_pay = input('param.order_pay');
            $account = input('param.account');
            $startTime = input('param.start_time');
//            $endTime = input('param.end_time');

            $where = [];
            if (!empty($order_no)) {
                $where[] = ['order_no', '=', $order_no];
            }
            if (!empty($order_me)) {
                $where[] = ['order_me', '=', $order_me];
            }
            if (!empty($order_pay)) {
                $where[] = ['order_pay', '=', $order_me];
            }
            if (!empty($account)) {
                $where[] = ['account', '=', $account];
            }
            if (!empty($startTime)) {
//                $endTime = stototime($startTime,);
                $endTime = mktime(date("Y-m-d", $startTime));
                $where[] = ['add_time', 'between', [strtotime($startTime), strtotime($startTime . ' 23:59:59')]];
            }

            $writeOffNodeId = session("admin_role_id");
            if ($writeOffNodeId == 8) {
                $where['write_off_sign'] = ['=', session("admin_user_name")];   //默认情况下 登录名就是
            }
            $orderhexiaomodel = new Orderhexiaomodel();
            $list = $orderhexiaomodel->getOrders($limit, $where);
            $data = empty($list['data']) ? array() : $list['data'];
            foreach ($data as $key => $vo) {
//                if (!empty($data[$key]['order_status']) && $data[$key]['order_status'] == '1') {
//                    $data[$key]['order_status'] = '<button class="layui-btn layui-btn-success layui-btn-xs">付款成功</button>';
//                }
//                if (!empty($data[$key]['order_status']) && $data[$key]['order_status'] == '2') {
//
//                    $data[$key]['order_status'] = '<button class="layui-btn layui-btn-danger layui-btn-xs">付款失败</button>';
//                }
//                if (!empty($data[$key]['order_status']) && $data[$key]['order_status'] == '3') {
//                    $data[$key]['order_status'] = '<button class="layui-btn layui-btn-disabled layui-btn-xs">下单失败</button>';
//                }
//                if (!empty($data[$key]['order_status']) && $data[$key]['order_status'] == '4') {
//                    $data[$key]['order_status'] = '<button class="layui-btn layui-btn-primary layui-btn-xs">等待支付</button>';
//                }
                $data[$key]['add_time'] = date('Y-m-d H:i:s', $data[$key]['add_time']);
                $data[$key]['use_time'] = date('Y-m-d H:i:s', $data[$key]['use_time']);
                $data[$key]['pay_time'] = date('Y-m-d H:i:s', $data[$key]['pay_time']);
                $data[$key]['limit_time'] = date('Y-m-d H:i:s', $data[$key]['limit_time']);
                $data[$key]['last_use_time'] = date('Y-m-d H:i:s', $data[$key]['last_use_time']);
                $data[$key]['notify_time'] = date('Y-m-d H:i:s', $data[$key]['notify_time']);
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
     * 删除推单
     * @return \think\response\Json
     */
    public function delTorder()
    {
        if (request()->isAjax()) {
            $tId = input('param.t_id');
            $torderModel = new Torderdouyinmodel();
            $res = $torderModel->delTorder($tId);
            Log::write("删除推单：" . $tId);
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
            if ($list['status'] != '4') {
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


}