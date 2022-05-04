<?php
/**
 * Created by PhpStorm.
 * User: NickBai
 * Email: 876337011@qq.com
 * Date: 2019/2/28
 * Time: 8:23 PM
 */

namespace app\admin\controller;

use app\admin\model\MerchantApiModel;
use app\admin\model\MerchantModel;
use app\admin\validate\MerchantapiValidate;
use tool\Log;

class Merchanta extends Base
{

    // 商户接口列表
    public function index()
    {
        if (request()->isAjax()) {

            $limit = input('param.limit');
            $merchantName = input('param.merchant_name'); //商户名称
            $merchantSign = input('param.merchant_sign'); //商户标识
            $apiSign = input('param.api_sign'); //商户标识

            $where = [];
            if (!empty($merchantName)) {
                $where[] = ['merchant_name', 'like', $merchantName . '%'];
            }
            if (!empty($merchantSign)) {
                $where[] = ['merchant_sign', 'like', $merchantSign . '%'];
            }
            if (!empty($apiSign)) {
                $where[] = ['api_sign', 'like', $apiSign . '%'];
            }

            $merchantApi = new MerchantApiModel();
            $list = $merchantApi->getMerchantApis($limit, $where);
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

    // 添加商户
    public function addMerchantapi()
    {

        if (request()->isPost()) {

            $param = input('post.');

            $validate = new MerchantapiValidate();
            if (!$validate->check($param)) {
                return ['code' => -1, 'data' => '', 'msg' => $validate->getError()];
            }
            $merchant = new MerchantApiModel();
            $res = $merchant->addMerchantapi($param);
            Log::write("添加商户通道：" . $param['merchant_name']."通道".$param['api_sign']);

            return json($res);
        } else {

            $getParam = input();
            if (!isset($getParam['id'])) {
                return json(reMsg(-403, '', '无商户信息！'));
            }

            $merchantModel = new MerchantModel();
            $merchant = $merchantModel->getMerchantById($getParam['id']);
            if ($merchant['code'] != 0) {
                return json(reMsg(-403, '', '无商户信息！'));
            }
            $this->assign([
                'merchant' => $merchant['data'],
                'payapis' => (new \app\admin\model\PayapiModel())->getAllPayApis()['data']
            ]);

            return $this->fetch('add');
        }
    }

}