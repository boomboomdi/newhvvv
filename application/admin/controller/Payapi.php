<?php
namespace app\admin\controller;

use app\admin\model\PayapiModel;
use app\admin\validate\PayapiValidate;
use tool\Log;

class Payapi extends Base
{
    // 支付接口列表
    public function index()
    {
        if(request()->isAjax()) {

            $limit = input('param.limit');
            $apiName = input('param.api_name'); //支付接口名称
            $where = [];
            if (!empty($merchantName)) {
                $where[] = ['api_name', 'like', $apiName . '%'];
            }

            $admin = new PayapiModel();
            $list = $admin->getPayapis($limit, $where);
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

    // 添加支付接口
    public function addPayapi()
    {
        if(request()->isPost()) {

            $param = input('post.');

            $validate = new PayapiValidate();
            if(!$validate->check($param)) {
                return ['code' => -1, 'data' => '', 'msg' => $validate->getError()];
            }

            $param['add_time'] = time();
            $param['update_time'] = time();

            $admin = new PayapiModel();
            $res = $admin->addPayapi($param);

            Log::write("添加支付接口：" . $param['api_name']);

            return json($res);
        }

        $this->assign([
            'payments' => (new \app\admin\model\PaymentModel())->getAllPayments()['data']
        ]);

        return $this->fetch('add');
    }

    // 编辑支付接口
    public function editPayapi()
    {
        if(request()->isPost()) {

            $param = input('post.');

            $validate = new PayapiValidate();
            if(!$validate->scene('edit')->check($param)) {
                return ['code' => -1, 'data' => '', 'msg' => $validate->getError()];
            }

            $payapi = new PayapiModel();
            $res = $payapi->editPayapi($param);

            Log::write("编辑支付接口：" . $param['api_name']);

            return json($res);
        }

        $apiId = input('param.id');
        $payapi = new PayapiModel();

        $this->assign([
            'payapi' => $payapi->getPayapiById($apiId)['data'],
            'payments' => (new \app\admin\model\PaymentModel())->getAllPayments()['data']
        ]);
        return $this->fetch('edit');
    }

    /**
     * 删除支付接口
     * @return \think\response\Json
     */
    public function delPayapi()
    {
        if(request()->isAjax()) {

            $id = input('param.id');

            $payapi = new PayapiModel();
            $res = $payapi->delPayapi($id);

            Log::write("删除支付接口：" . $id);

            return json($res);
        }
    }
}