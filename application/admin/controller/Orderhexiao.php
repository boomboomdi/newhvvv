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
            if (!empty(input('param.write_off_sign'))) {
                $where[] = ['write_off_sign', '=', input('param.write_off_sign')];
            }
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

    //止付
    public function notify()
    {
        $id = input('param.id');
        try {
            if (request()->isAjax()) {

                if (empty($id)) {
                    return json(modelReMsg(-1, '', '参数错误!'));
                }
                //查询订单
                $orderHxData = Db::table("bsa_order_hexiao")->where("id", $id)->find();
                if (empty($orderHxData)) {
                    return json(modelReMsg(-2, '', '无此核销单!'));
                }
                //查询是否有匹配订单
                $orderHXModel = new OrderhexiaoModel();
//                $orderData = Db::table("bsa_order")
//                    ->where("account", '=', $orderHxData['account'])
//                    ->where("order_pay", '=', $orderHxData['order_no'])
//                    ->where("order_status", '=', $orderHxData['order_no'])
//                    ->find();
//                if (!empty($orderData) || !empty($orderHxData['order_me'])) {
//                    return json(modelReMsg(-3, '', '已使用核销单不可止付!'));
//                }
                if ($orderHxData['order_limit_time'] != 0) {
                    return json(modelReMsg(-3, '', '匹配订单冻结期间不可止付!'));
                }
                Db::startTrans();
                $lock = Db::table("bsa_order_hexiao")->where("id", $id)->lock(true)->find();
                if (!$lock) {
                    return json(modelReMsg(-4, '', '止付失败！!'));
                }
                $updateHXData['check_result'] = "手动止付" . session('admin_user_name') . date("Y-m-d H:i:s", time());
                $updateHXData['status'] = 2;
                $updateHXData['limit_time'] = time();
                $updateHXData['order_status'] = 2;
                $updateHXData['check_status'] = 0;
                $updateHXData['pay_status'] = 2;
                $localUpdate = Db::table("bsa_order_hexiao")->where("id", $id)->update($updateHXData);
                if (!$localUpdate) {
                    Db::rollback();
                    logs(json_encode(["time" => date("Y-m-d H:i:s", time()),
                        'order_no' => $orderHxData['order_no'],
                        'phone' => $orderHxData['account'],
                        "localUpdateFail" => json_encode($localUpdate)
                    ]), 'orderHXNotify');
                    return json(modelReMsg(-5, '', '回调订单发生错误!'));
                }
                Db::commit();
                return json(modelReMsg(0, '', '止付成功'));
            } else {
                return json(modelReMsg(-99, '', '访问错误'));
            }
        } catch (\Exception $exception) {
            logs(json_encode(['id' => $id, 'file' => $exception->getFile(), 'line' => $exception->getLine(),
                'errorMessage' => $exception->getMessage()]), 'orderHXNotifyException');
            return json(modelReMsg(-11, '', '止付异常'));
        } catch (\Error $error) {
            logs(json_encode(['id' => $id, 'file' => $error->getFile(), 'line' => $error->getLine(),
                'errorMessage' => $error->getMessage()]), 'orderHXNotifyError');
            return json(modelReMsg(-22, '', '止付错误'));

        }


    }

}