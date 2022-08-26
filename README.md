# layui-soul-table 后台PHP版
## 使用方法

```
    public function test()
    {
        $filterSos = input('filterSos');
        $order = input('order');
        $field = input('field');

        if (!$order || !$field) {
            $orderSql = 'self.id desc';
        } else {
            $orderSql = $field . ' ' . SoulTable::handleOrder($order);
        }


        list($sqlWhere, $having, $bind) = (new SoulTable)->parse($filterSos, [
            'id'=>'self.id', // 连表查询时，都有id，不知道查哪个表的id，加上表名称映射。默认处理成 `self`.`id`，如果不需要处理，请使用下方的skip参数
            'train_num'=>[
                'field_name'=>'count(self.id)',
                'skip'=>true,// 这里是一个表达式，skip表示外边不要加 `` 符号
                'is_having'=>true,// 如果是聚合查询的条件，则单独处理
            ],
        ]);

        $list = TestModel::alias('self')
            ->join('test_sub ts', 'seld.id = ts.test_id', 'left')
            ->field("self.id, count(seld.id) as train_num")
            ->where($sqlWhere)
            ->bind($bind) // 使用字符串查询时，使用参数绑定，确保更加安全
            ->order($orderSql)
            ->group('seld.id')
            ->having('count(self.id) > 0 and' . $having)
            ->select()
            ->toArray();

        var_dump($list);
    }
```