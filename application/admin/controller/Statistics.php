<?php
/**
 * Created by PhpStorm.
 * User: NickBai
 * Email: 876337011@qq.com
 * Date: 2019/2/28
 * Time: 8:23 PM
 */

namespace app\admin\controller;

use app\admin\model\OrderModel;
use app\admin\model\Orderhexiaomodel;

//use app\admin\validate\WriteoffValidate;
//use app\common\model\OrderModel;
use app\admin\model\WriteoffModel;
use tool\Log;

//统计
class Statistics extends Base
{
    //
    public function index()
    {
        if (request()->isAjax()) {

            $limit = input('param.limit');
            $writeOffSign = input('param.write_off_sign'); //核销名称

            $where = [];
            if (empty($writeOffSign)) {
                return json(['code' => 0, 'msg' => 'ok', 'count' => 0, 'data' => []]);
            }
//            $merchantSign = input('param.merchant_sign'); //商户

            $startTime = input('param.startTime'); //开始时间
            if (empty($startTime)) {
                $startTime = date("Y-m-d", time());
            }
            $where[] = ['write_off_sign', '=', $writeOffSign];
            $where[] = ['pay_time', '>', strtotime($startTime)];
            $where[] = ['pay_time', '<', (strtotime($startTime) + 86400)];
//            if (!empty($writeOffSign)) {
//                $where[] = ['write_off_sign', '=', $writeOffSign . '%'];
//            }
            $writeOffModel = new WriteoffModel();
            $writeOff = $writeOffModel->getWriteOffBySign($writeOffSign);

            if (empty($writeOff)) {
                return json(['code' => -1, 'msg' => '核销商标识输入有误！', 'count' => 0, 'data' => []]);
            }
//            $orderModel = new OrderModel();
            $orderHxModel = new Orderhexiaomodel();
            $list = $orderHxModel->field("order_amount,write_off_sign")
                ->where($where)
                ->group("order_amount")
                ->order("order_amount desc")
//                ->select();
//                ->limit($limit)
                ->paginate($limit);
//            $totalCount = $list->count();
//            $res = $this->field($prefix . 'order.*')
//                ->where($where)
//                ->order('id', 'desc')
//                ->paginate($limit);
//            $list = $orderModel->getStatistics($limit, $where);
            if (0 < count($list)) {
                $data = $list;
                $orderTotalNum = 0; //总推单数量
                $totalOrderAmount = 0; //推单总额
                $totalPayOrderAmount = 0; //总支付金额
                $totalPayOrderAmountNum = 0; //总支付数量
                $k = 0;
                foreach ($data as $key => $vo) {
                    $k++;
//                    $data[$key]['order_amount'] = $vo['order_amount'];
                    //推单数量（每个金额）
                    $data[$key]['orderTotalNum'] = $orderHxModel
                        ->where($where)
                        ->where("order_amount", "=", $vo['order_amount'])
                        ->count();
                    $orderTotalNum += $data[$key]['orderTotalNum'];

                    //推单总额（每个金额）
                    $data[$key]['totalOrderAmount'] = $orderHxModel
                        ->where($where)
                        ->field("SUM(order_amount) as totalOrderAmount")
                        ->where("order_amount", "=", $vo['order_amount'])
                        ->find()['totalOrderAmount'];
                    $totalOrderAmount += $data[$key]['totalOrderAmount'];

                    //总支付数量（每个金额）
                    $data[$key]['totalPayOrderAmountNum'] = $orderHxModel
                        ->where($where)
                        ->where("order_amount", "=", $vo['order_amount'])
                        ->where("", "=", $vo['order_amount'])
                        ->count();
                    $totalPayOrderAmountNum += $data[$key]['totalPayOrderAmountNum'];
                    //总支付金额（每个金额）
                    $data[$key]['totalPayOrderAmount'] = $orderHxModel
                        ->field("SUM(pay_amount) as totalPayOrderAmount")
                        ->where($where)
                        ->where("order_amount", "=", $vo['order_amount'])
                        ->find()['totalPayOrderAmount'];
                    $totalPayOrderAmount += $data[$key]['totalPayOrderAmount'];

                }
                $total['order_amount'] = '总统计';
                $total['write_off_sign'] = $writeOffSign;
                $total['orderTotalNum'] = $orderTotalNum;
                $total['totalOrderAmount'] = $totalOrderAmount;
                $total['totalPayOrderAmount'] = $totalPayOrderAmount;
                $total['totalPayOrderAmountNum'] = $totalPayOrderAmountNum;

                $data[] = $total;
                $list = $data;
//                var_dump(count($list));
////                exit;
//                $orderTotalNum = 0; //总推单数量
//                $totalOrderAmount = 0; //推单总额
//                $totalPayOrderAmount = 0; //总支付金额
//                $totalPayOrderAmountNum = 0; //总支付数量
                $list = $data;
//                var_dump($list->all());exit;
//                return json(['code' => 0, 'msg' => 'ok', 'count' => $list['data']->total(), 'data' => $list['data']->all()]);
                return json(['code' => 0, 'msg' => 'ok', 'count' => $list->total(), 'data' => $list->all()]);
            }

            return json(['code' => 0, 'msg' => 'ok', 'count' => 0, 'data' => []]);
        }

        return $this->fetch();
    }
}