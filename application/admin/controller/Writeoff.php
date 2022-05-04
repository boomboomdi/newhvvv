<?php
/**
 * Created by PhpStorm.
 * User: NickBai
 * Email: 876337011@qq.com
 * Date: 2019/2/28
 * Time: 8:23 PM
 */

namespace app\admin\controller;

use app\admin\model\WriteoffModel;
use app\admin\validate\WriteoffValidate;
use app\common\model\OrderModel;
use tool\Log;

class Writeoff extends Base
{
    // 核销列表
    public function index()
    {
        if (request()->isAjax()) {

            $limit = input('param.limit');
            $merchantName = input('param.merchant_name'); //核销名称

            $where = [];
            if (!empty($merchantName)) {
                $where[] = ['merchant_name', 'like', $merchantName . '%'];
            }

            $writeOffModel = new WriteoffModel();
            $list = $writeOffModel->getWriteoffs($limit, $where);
            if (0 == $list['code']) {
//                $data = $list['data'];
//                foreach ($data as $key => $vo) {
//                    //查询核销订单量 总
//                    $data[$key]['order_total'] = (new \app\admin\model\OrderModel())->getAllOrderNumberByMerchantSign($data[$key]['merchant_sign'])['data'];
//                    //查询核销订单量 支付成功量
//                    $data[$key]['success_order_total'] = (new \app\admin\model\OrderModel())->getAllOrderSuccessNumberByMerchantSign($data[$key]['merchant_sign'])['data'];
//                    $data[$key]['success_order_rate'] = makeSuccessRate((int)$data[$key]['success_order_total'], (int)$data[$key]['order_total']);
//
//                    //查询核销订单量 总 30分钟
//                    $startTime = time() - 300;
//                    $data[$key]['order_total5'] = (new \app\admin\model\OrderModel())->getAllOrderNumberByMerchantSign($data[$key]['merchant_sign'], $startTime)['data'];
//                    //查询核销订单量 支付成功量 30分钟
//                    $data[$key]['success_order_total5'] = (new \app\admin\model\OrderModel())->getAllOrderSuccessNumberByMerchantSign($data[$key]['merchant_sign'], $startTime)['data'];
//                    $data[$key]['success_order_rate5'] = makeSuccessRate((int)$data[$key]['success_order_total'], (int)$data[$key]['order_total']);
//                }
                return json(['code' => 0, 'msg' => 'ok', 'count' => $list['data']->total(), 'data' => $list['data']->all()]);
            }

            return json(['code' => 0, 'msg' => 'ok', 'count' => 0, 'data' => []]);
        }

        return $this->fetch();
    }

    // 添加核销
    public function addwriteoff()
    {
        if (request()->isPost()) {

            $param = input('post.');
//            var_dump($param);exit;
            $validate = new WriteoffValidate();
            if (!$validate->check($param)) {
                return ['code' => -1, 'data' => '', 'msg' => $validate->getError()];
            }

//            $param['merchant_password'] = makePassword($param['merchant_password']);
//            $param['merchant_validate_password'] = makePassword($param['merchant_validate_password']);

            $writeOffModel = new WriteoffModel();
            $res = $writeOffModel->addWriteoff($param);

            Log::write("添加核销：" . $param['write_off_username']);

            return json($res);
        }

//        $this->assign([
//            'roles' => (new \app\admin\model\Role())->getAllRoles()['data']
//        ]);

        return $this->fetch('add');
    }

    // 编辑核销
    public function editWriteoff()
    {
        if (request()->isAjax()) {

            $param = input('post.');

            $validate = new WriteoffValidate();
            if (!$validate->check($param)) {
                return ['code' => -1, 'data' => '', 'msg' => $validate->getError()];
            }

            $writeOffModel = new WriteoffModel();
            $res = $writeOffModel->editWriteoff($param);

            Log::write("编辑核销：" . $param['write_off_username']);

            return json($res);
        }
        $writeOffId = input('param.write_off_id');
//        var_dump($merchantId);exit;
        $writeOffModel = new WriteoffModel();
//        $writeOffData = $writeOffModel->get(["write_off_id" => $writeOffId]);
//        $writeOffData = $writeOffData['data'];
        $this->assign([
            'writeOff' => $writeOffModel->getWriteoffById($writeOffId)['data'],
        ]);

        return $this->fetch('edit');
    }

    /**
     * 删除核销
     * @return \think\response\Json
     */
    public function delWriteoff()
    {
        if (request()->isAjax()) {

            $writeOffId = input('param.write_off_id');

            $writeOffModel = new WriteoffModel();
            $res = $writeOffModel->delWriteoff($writeOffId);

            Log::write("删除核销：" . $writeOffId);

            return json($res);
        }
    }
}