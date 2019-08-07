<?php
/**
 * Copyright (c) 2018. Allan Carvalho
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express
 * or implied. See the License for the specific language governing
 * permissions and limitations under the License.
 */

namespace DataTables\Controller;

use Cake\Core\Configure;
use Cake\Error\FatalErrorException;
use Cake\Http\ServerRequest;
use Cake\ORM\Query;
use Cake\Utility\Inflector;
use Cake\View\ViewBuilder;
use DataTables\View\DataTablesView;
use Cake\ORM\TableRegistry;

/**
 * CakePHP DataTablesComponent
 *
 * @property \DataTables\Controller\Component\DataTablesComponent $DataTables
 * @method ViewBuilder viewBuilder()
 * @method ServerRequest getRequest()
 * @method \Controller set($name, $value = null)
 * @author allan
 */
trait DataTablesAjaxRequestTrait
{

    private $config = [];

    private $params = [];
    /**
     * @var callable
     */
    private $dataTableBeforeAjaxFunction = null;

    /**
     * @var callable
     */
    private $dataTableAfterAjaxFunction = null;

    /**
     * Set a function to be exec before ajax request
     * @param callable $dataTableBeforeAjaxFunction
     */
    public function setDataTableBeforeAjaxFunction(callable $dataTableBeforeAjaxFunction)
    {
        if (!is_callable($dataTableBeforeAjaxFunction)) {
            throw new FatalErrorException(__d("datatables", "the parameter must be a function"));
        }
        $this->dataTableBeforeAjaxFunction = $dataTableBeforeAjaxFunction;
    }

    /**
     * Set a function to be exec after ajax request
     * @param callable $dataTableAfterAjaxFunction
     */
    public function setDataTableAfterAjaxFunction(callable $dataTableAfterAjaxFunction)
    {
        if (!is_callable($dataTableAfterAjaxFunction)) {
            throw new FatalErrorException(__d("datatables", "the parameter must be a function"));
        }
        $this->dataTableAfterAjaxFunction = $dataTableAfterAjaxFunction;
    }

    /**
     * Ajax method to get data dynamically to the DataTables
     * @param string $this->config
     */
    public function getDataTablesContent($configName)
    {
        if (!empty($this->dataTableBeforeAjaxFunction) and is_callable($this->dataTableBeforeAjaxFunction)) {
            call_user_func($this->dataTableBeforeAjaxFunction);
        }

        if(Configure::read('debug') !== true) {
            $this->getRequest()->allowMethod('ajax');
        }
        //set layout and global vars
        $this->config = $this->DataTables->getDataTableConfig($configName);
        $this->params = $this->getRequest()->getQuery();
        $this->viewBuilder()->setClassName(DataTablesView::class);
        $this->viewBuilder()->setTemplate(Inflector::underscore($configName));

        //if the table is not set set it
        if(!isset($this->table)) {
           $this->table = TableRegistry::get($this->config['table']);
        }
        if(isset($this->config['options']['filterByCompany']) && $this->config['options']['filterByCompany'] == false){
            $this->table->filterByCompany = false;
        }
        if(isset($this->config['options']['filterByTenantCompanies']) && $this->config['options']['filterByTenantCompanies'] == false){
            $this->table->filterByTenantCompanies = false;
        }
        if(isset($this->config['options']['filterByTenant']) && $this->config['options']['filterByTenant'] == false){
            $this->table->filterByTenant = false;
        }

        /** @var array $select */
        /** @var Query $results */
        $results = $this->table->find($this->config['finder'], $this->config['queryOptions'])
            ->where($this->config['where'], $this->config['cast'])
            ->contain($this->config['contain'])
            ->limit($this->params['length'])
            ->offset($this->params['start'])
            ->order($this->parseOrder());
        if($this->config['selectAll'] && $select = $this->parseSelect()){
            $results = $results->select($select);
        }

        $recordsTotal = (int) $results->count();

        if($where = $this->parseWhere()){
            $results = $results->where($where);
        }

        $recordsFiltered = (int) $results->count();
        $resultInfo = [
            'draw' => (int)$this->params['draw'],
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered
        ];

        $this->set([
            'results' => $results,
            'resultInfo' => $resultInfo,
        ]);

        if (!empty($this->dataTableAfterAjaxFunction) and is_callable($this->dataTableAfterAjaxFunction)) {
            call_user_func($this->dataTableAfterAjaxFunction);
        }
    }

    public function parseSelect(){
        $select = [];
        if($options['selectAll'] == false){
            foreach ($this->config['columns'] as $key => $item) {
                if ($item['database'] == true) {
                    $select[] = $key;
                }
            }
    
            if (!empty($this->config['databaseColumns'])) {
                foreach ($this->config['databaseColumns'] as $key => $item) {
                    $select[] = $item;
                }
            }
        }

        return array_merge($select, $options['select']);
    }

    public function parseOrder(){
        $order = [];

        if (!empty($this->params['order'])) {
            foreach ($this->params['order'] as $item) {
                $order[$this->config['columnsIndex'][$item['column']]] = $item['dir'];
            }
        }
        if(!empty($order)) {
            unset($this->config['queryOptions']['order']);
        }

        return $order;
    }

    public function parseWhere(){
        $where = [];
        if (!empty($this->params['search']['value'])) {
            foreach ($this->config['columns'] as $column) {
                if ($column['searchable'] == true) {
                    $explodedColumnName = explode(".", $column['name']);
                    if (count($explodedColumnName) == 2) {
                        if ($explodedColumnName[0] === $this->table->getAlias()) {
                            $columnType = !empty($this->table->getSchema()->getColumn($explodedColumnName[1])['type']) ? $this->table->getSchema()->getColumn($explodedColumnName[1])['type'] : 'string';
                        } else {
                            $columnType = !empty($this->table->{$explodedColumnName[0]}->getSchema()->getColumn($explodedColumnName[1])['type']) ? $this->table->getSchema()->getColumn($explodedColumnName[1])['type'] : 'string';
                        }
                    } else {
                        $columnType = !empty($this->table->getSchema()->getColumn($column['name'])['type']) ? $this->table->getSchema()->getColumn($column['name'])['type'] : 'string';
                    }
               
                    switch ($columnType) {
                        case "integer":
                            if (is_numeric($this->params['search']['value'])) {
                                $where['OR']["{$column['name']}"] = $this->params['search']['value'];
                            }
                            break;
                        case "boolean":
                           
                            break;
                        case "decimal":
                            if (is_numeric($this->params['search']['value'])) {
                                $where['OR']["{$column['name']}"] = $this->params['search']['value'];
                            }
                            break;
                        case "string":
                            $where['OR']["{$column['name']} like"] = "%{$this->params['search']['value']}%";
                            break;
                        case "text":
                            $where['OR']["{$column['name']} like"] = "%{$this->params['search']['value']}%";
                            break;
                        case "datetime":
                            $where['OR']["{$column['name']} like"] = "%{$this->params['search']['value']}%";
                            break;
                        default:
                            $where['OR']["{$column['name']} like"] = "%{$this->params['search']['value']}%";
                            break;
                    }
                }
            }
        }
        // searching individual field
        foreach ($this->params['columns'] as $paramColumn) {
            $columnSearch = $paramColumn['search']['value'];
            if (!$columnSearch || !$paramColumn['searchable']) {
                continue;
            }

            $explodedColumnName = explode(".", $paramColumn['name']);
            if (count($explodedColumnName) == 2) {
                if ($explodedColumnName[0] === $this->table->getAlias()) {
                    $columnType = !empty($this->table->getSchema()->getColumn($explodedColumnName[1])['type']) ? $this->table->getSchema()->getColumn($explodedColumnName[1])['type'] : 'string';
                } else {
                    $columnType = !empty($this->table->{$explodedColumnName[0]}->getSchema()->getColumn($explodedColumnName[1])['type']) ? $this->table->getSchema()->getColumn($explodedColumnName[1])['type'] : 'string';
                }
            } else {
                $columnType = !empty($this->table->getSchema()->getColumn($paramColumn['name'])['type']) ? $this->table->getSchema()->getColumn($paramColumn['name'])['type'] : 'string';
            }
            switch ($columnType) {
                case "integer":
                    if (is_numeric($this->params['search']['value'])) {
                        $where[] = [$paramColumn['name'] => $columnSearch];
                    }
                    break;
                case "decimal":
                    if (is_numeric($this->params['search']['value'])) {
                        $where[] = [$paramColumn['name'] => $columnSearch];
                    }
                    break;
                case 'string':
                    $where[] = ["{$paramColumn['name']} like" => "%$columnSearch%"];
                    break;
            }
        }
        return $where;
    }

}
