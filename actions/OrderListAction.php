<?php

namespace skinka\components\ordering\actions;

use Yii;
use yii\base\Action;
use yii\base\InvalidConfigException;
use yii\i18n\PhpMessageSource;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class OrderListAction extends Action
{
    public $model;
    public $group = null;
    public $keyField;
    public $valueField;
    private $_group_id = null;

    public function run()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        if (Yii::$app->user->isGuest || !Yii::$app->request->isAjax) {
            throw new ForbiddenHttpException(Yii::t('yii', 'You are not allowed to perform this action.'));
        }
        if (!$this->model) {
            throw new InvalidConfigException('The "model" property must be set.');
        }
        if (!$this->keyField) {
            throw new InvalidConfigException('The "keyField" property must be set.');
        }
        if (!$this->valueField) {
            throw new InvalidConfigException('The "valueField" property must be set.');
        }
        if ($this->group !== null && ($this->_group_id = Yii::$app->request->post('group_id', null)) === null) {
            throw new BadRequestHttpException('Bad request');
        }

        Yii::$app->i18n->translations['skinka/ordering/*'] = [
            'class' => PhpMessageSource::className(),
            'basePath' => '@vendor/skinka/yii2-ordering/messages',
            'fileMap' => [
                'skinka/ordering/core' => 'core.php',
            ],
        ];

        $model = new $this->model;

        $model = $model->find();

        if ($this->group !== null && $this->_group_id !== null) {
            $model = $model->where([$this->group => $this->_group_id]);
        }
        $listItems = [];
        $listItems[] = ['index' => 0, 'value' => Yii::t('skinka/ordering/core', '<< First >>')];
        $data = $model->orderBy([$this->keyField => SORT_ASC])->all();
        foreach ($data as $item) {
            $listItems[] = ['index' => $item->{$this->keyField}, 'value' => $item->{$this->valueField}];
        }
        $listItems[] = ['index' => -1, 'value' => Yii::t('skinka/ordering/core', '<< Last >>')];

        return ['listItems' => $listItems];
    }
}