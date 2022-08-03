<?php
// +----------------------------------------------------------------------
// | SoulTable PHP
// +----------------------------------------------------------------------
// | Copyright (c) 2022 Jary All rights reserved.
// +----------------------------------------------------------------------
// | Author: Jary <github.com/SunJary>
// +----------------------------------------------------------------------
namespace app\common\lib;

class SoulTable {

    /**
     * @var array
     * 参数绑定
     */
    private $bind = [];
    /**
     * @var array
     * 字段替换，比如说前端列名称 create_time ,连表查询的时候，不清楚要查询哪一个表的数据，可以设置映射关系
     * [
     *      'create_time' => 's.create_time',   // 默认带字段过滤，s.create_time 返回 `s`.`create_time`
     *      'time'=>[                           // 如果需要跳过验证，可以传入数组
     *          'field_name'=>'',                    // 字段名，或者查询表达式
     *          'skip'=>true,                   // true 表示跳过
     *          'is_having'=>true,                   // true 表示是聚合查询中的聚合搜索条件
     *      ]
     * ]
     */
    private $fieldMap = [];

    /**
     * @param $filterSos string 筛选的数据
     * @param array $fieldMap fieldMap 对于复杂的查询，可能有多个表有相同的字段，用于字段替换，参考 $this->$fieldMap
     * @return array[
     *      sql 查询条件
     *      having 聚合搜索条件
     *      bind 参数绑定
     * ]
     */
    public function parse($filterSos, $fieldMap = [])
    {
        $filterSos = htmlspecialchars_decode($filterSos);
        $filterSos = json_decode($filterSos, true);

        $this->fieldMap = $fieldMap;

        list($sql, $having) = $this->parseSoulTable($filterSos);
        return [$sql, $having, $this->bind];
    }

    private function parseSoulTable($filter)
    {
        $sqlWhere = $havingWhere = '';
        $len = count($filter);
        if ($len === 0) return ['', ''];

        foreach ($filter as $item) {
            if (!isset($item['mode'])) {
                continue;
            }

            $field = isset($item['field']) ? $item['field'] : '';

            $prefix = isset($item['prefix']) ? $item['prefix'] : '';
            $sqlPrefix = $havingPrefix = '';
            // 如果之前的循环没有生成sql 则不写 and or
            if ($sqlWhere !== '') {
                $sqlPrefix = $this->prefix2Sql($prefix);
            }
            if ($havingWhere !== '') {
                $havingPrefix = $this->prefix2Sql($prefix);
            }

            if ($item['mode'] === 'group' && $item['children']) {
                // 如果是组查询，递归
                list($sql, $having) = $this->parseSoulTable($item['children']);

                // 如果不是空的，才拼接
                if ($having) $havingWhere .= $havingPrefix . $having;
                if ($sql) $sqlWhere .= $sqlPrefix . $sql;
            } elseif ($item['mode'] === 'condition') {
                $buildSql = $this->buildItemSql($item);

                // 如果是空的，跳过当前循环
                if ($buildSql === '') continue;

                // 如果是聚合查询的条件
                if (isset($this->fieldMap[$field]['is_having']) &&
                    $this->fieldMap[$field]['is_having']) {
                    $havingWhere .= $havingPrefix . $buildSql;
                } else {
                    $sqlWhere .= $sqlPrefix . $buildSql;
                }
            }
        }

        // 如果为空，
        $havingWhere = $havingWhere !== '' ? ' (' . $havingWhere . ') ' : '';
        $sqlWhere = $sqlWhere !== '' ? ' (' . $sqlWhere . ') ' : '';
        return [$sqlWhere, $havingWhere];
    }



    private function prefix2Sql($prefix)
    {
        if (in_array($prefix, ['and', 'or'])) {
            return $prefix;
        }
        // 设置默认值，防止sql注入
        return 'and';
    }


    private function buildItemSql($item)
    {
        $type = isset($item['type']) ? $item['type'] : 'eq';
        if (!isset($item['field']) || $item['field'] == '') {
            return '';
        }

        $field = $item['field'];

        $value = isset($item['value']) ? $item['value'] : '';

        // 参数绑定的key
        $valueBind = $this->getUniqId($field);
        $bind = $value;
        $needBind = true; // 是否需要参数绑定
        switch ($type) {
            case 'contain':
                $bind = '%'.$value.'%';
                $sql = " like :$valueBind ";
                break;
            case 'ne':
                $sql = " <> :$valueBind ";
                break;
            case 'gt':
                $sql = " > :$valueBind ";
                break;
            case 'ge':
                $sql = " >= :$valueBind ";
                break;
            case 'lt':
                $sql = " < :$valueBind ";
                break;
            case 'le':
                $sql = " <= :$valueBind ";
                break;
            case 'notContain':
                $bind = '%'.$value.'%';
                $sql = " not like :$valueBind ";
                break;
            case 'start':
                $bind = $value.'%';
                $sql = " like :$valueBind ";
                break;
            case 'end':
                $bind = '%'.$value;
                $sql = " like :$valueBind ";
                break;
            case 'null':
                $sql = " is null ";
                $needBind = false; // 不需要参数绑定
                break;
            case 'notNull':
                $sql = " is not null ";
                $needBind = false; // 不需要参数绑定
                break;
            case 'eq':
            default:
                $sql = " = :$valueBind ";
                break;
        }
        if ($needBind) {
            $this->bind[$valueBind] = $bind;
        }

        return ' ' . $this->parseField($field)  . $sql;
    }

    private function getUniqId($field) {
        return uniqid($field);
    }

    /**
     * 处理字段名称
     * 例如 name 返回 `name`，t.id 返回 `t`.`id`
     * @param $field string|array
     *    field 可以直接写字段名称
     *    如果需要跳过验证，可以传入数组 [
     *     'field_name'=>'', // 字段名，或者查询表达式
     *     'skip'=>true, // true 表示跳过
     *     ]
     * @return mixed|string
     */
    private function parseField($field) {
        // 字段映射
        $field = isset($this->fieldMap[$field]) ? $this->fieldMap[$field] : $field;

        if (is_array($field)){
            // 对于部分字段的查询条件可能是一个计算结果，此时不应再进行参数验证
            // 例如 有一个时间差的字段 cast((TIMESTAMPDIFF( second, start_time, end_time )+0.0)/3600 as decimal(18,2))
            if (isset($field['skip']) && $field['skip']) {
                return $field['field_name'];
            }
            $field = $field['field_name'];
        }

        // 正常来讲，字段名称不应含有空格。用于防止sql注入
        $field = str_replace(' ', '', $field);

        if (false === strpos($field, '`')) {
            // . 符号 一般用于表别名以及表字段的连接符
            // 例如 t.id 返回 `t`.`id`
            if (strpos($field, '.')) {
                $field = str_replace('.', '`.`', $field);
            }
        }
        return '`' . $field . '`';
    }

    /**
     * 按字段排序时，处理字段名称 防止 sql 注入
     * thinkPHP 框架会对字段进行处理，不需调用
     * @param $field
     * @return mixed|string
     */
    public static function handleField($field)
    {
        return (new self())->parseField($field);
    }

    /**
     * 按字段排序时，处理排序方式 防止 sql 注入
     * @param $order
     * @return string
     */
    public static function handleOrder($order)
    {
        if (in_array($order, ['asc', 'desc'])) {
            return $order;
        }
        return 'asc';
    }
}
