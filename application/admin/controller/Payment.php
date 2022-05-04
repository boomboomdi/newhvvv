<?php

namespace app\admin\controller;

use app\admin\model\PaymentModel;
use app\admin\validate\PaymentValidate;
use tool\Log;

class Payment extends Base
{
    // 支付方式列表
    public function index()
    {
        if (request()->isAjax()) {

            $limit = input('param.limit');
            $merchantName = input('param.merchant_name'); //支付接口名称
            $where = [];
            if (!empty($merchantName)) {
                $where[] = ['merchant_name', 'like', $merchantName . '%'];
            }

            $admin = new PaymentModel();
            $list = $admin->getPayments($limit, $where);
            if (0 == $list['code']) {
                $data = $list['data'];
                foreach ($data as $key => $vo) {
                    $data[$key]['add_time'] = date('Y-m-d H:i:s', $data[$key]['add_time']);
                    $data[$key]['update_time'] = date('Y-m-d H:i:s', $data[$key]['update_time']);
                }
                return json(['code' => 0, 'msg' => 'ok', 'count' => $list['data']->total(), 'data' => $data->all()]);
            }

            return json(['code' => 0, 'msg' => 'ok', 'count' => 0, 'data' => []]);
        }
        return $this->fetch();
    }

    // 添加支付方式
    public function addPayment()
    {
        if (request()->isPost()) {

            $param = input('post.');

            $validate = new PaymentValidate();
            if (!$validate->check($param)) {
                return ['code' => -1, 'data' => '', 'msg' => $validate->getError()];
            }

            $param['payment_id'] = time();
            $param['add_time'] = time();
            $param['update_time'] = time();

            $admin = new PaymentModel();
            $res = $admin->addPayment($param);

            Log::write("添加支付方式接口：" . $param['payment_name']);
            return json($res);
        }

//        $this->assign([
//            'roles' => (new \app\admin\model\Role())->getAllRoles()['data']
//        ]);

        return $this->fetch('add');
    }

    // 编辑支付方式
    public function editPayment()
    {
        if (request()->isPost()) {

            $param = input('post.');

            $validate = new PaymentValidate();
            if (!$validate->scene('edit')->check($param)) {
                return ['code' => -1, 'data' => '', 'msg' => $validate->getError()];
            }

            $merchant = new PaymentModel();
            $res = $merchant->editPayment($param);

            Log::write("编辑支付方式接口：" . $param['payment_name']);

            return json($res);
        }

        $id = input('param.id');
//        var_dump($merchantId);exit;
        $payment = new PaymentModel();

        $this->assign([
            'payment' => $payment->getPaymentById($id)['data'],
//            'roles' => (new \app\admin\model\Role())->getAllRoles()['data']
        ]);

        return $this->fetch('edit');
    }

    /**
     * 删除支付方式
     * @return \think\response\Json
     */
    public function delPayment()
    {
        if (request()->isAjax()) {

            $merchantId = input('param.id');

            $admin = new PaymentModel();
            $res = $admin->delPayment($merchantId);

            Log::write("删除支付方式：" . $merchantId);

            return json($res);
        }
    }
}