<?php
namespace App\Areas\Menu\Models;

use App\Areas\Rbac\Models\Permission;
use ManaPHP\Db\Model;

class Item extends Model
{
    public $item_id;
    public $item_name;
    public $group_id;
    public $url;
    public $display_order;
    public $creator_name;
    public $updator_name;
    public $created_time;
    public $updated_time;

    public function getSource($context = null)
    {
        return 'menu_item';
    }

    public function rules()
    {
        return [
            'item_name' => ['length' => '5-32'],
            'group_id' => 'exists',
            'url' => ['length' => '1-128'],
            'display_order' => ['range' => '0-127']
        ];
    }
}