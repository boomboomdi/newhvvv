<?php
/**
 * Created by PhpStorm.
 * User: NickBai
 * Email: 876337011@qq.com
 * Date: 2019/3/17
 * Time: 4:48 PM
 */

namespace app\common\model;

use think\Db;
use think\Model;

//**
class ChecklogModel extends Model
{
    protected $table = 'bsa_check_log';

    /**
     * 增加查单日志
     * @param $data
     * @return array
     */
    public function addlog($data)
    {
        try {

//            $has = $this->where('admin_name', $admin['admin_name'])->findOrEmpty()->toArray();
//            if(!empty($has)) {
//                return modelReMsg(-2, '', '管理员名已经存在');
//            }

            $this->insert($data);
        }catch (\Exception $e) {

            return modelReMsg(-1, '', $e->getMessage());
        }

        return modelReMsg(0, '', '添加查单日志成功');
    }
}