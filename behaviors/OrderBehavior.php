<?php
namespace skinka\components\ordering\behaviors;

use yii\base\Behavior;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\db\ActiveRecord;
use yii\db\Expression;
use yii\db\Query;
use yii\helpers\ArrayHelper;
use yii\i18n\PhpMessageSource;


class OrderBehavior extends Behavior
{
    public $orderAttribute = 'ordering';
    public $groupOrder = null;

    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
            ActiveRecord::EVENT_BEFORE_INSERT => 'beforeInsert',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeSave',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterSave',
            ActiveRecord::EVENT_AFTER_DELETE => 'afterDelete',
        ];
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        if ($this->orderAttribute === null) {
            throw new InvalidConfigException('The "orderAttributes" property must be set.');
        }
    }

    /**
     * @param $event Event
     */
    public function beforeValidate($event)
    {
        $max = $this->getMaxOrderValue();
        if ($max === null) {
            $this->owner->{$this->orderAttribute} = 0;
        } else {
            if (isset($event->sender->oldAttributes[$this->orderAttribute])
                && $event->sender->oldAttributes[$this->orderAttribute] == $max
                && ($event->sender->attributes[$this->orderAttribute] > $max || $event->sender->attributes[$this->orderAttribute] < 0)
            ) {
                $this->owner->{$this->orderAttribute} = $max;
            }
            if ($event->sender->attributes[$this->orderAttribute] === null ||
                $event->sender->attributes[$this->orderAttribute] < 0 ||
                $event->sender->attributes[$this->orderAttribute] > ($max + 1)
            ) {
                $this->owner->{$this->orderAttribute} = $max + 1;
            }
            if ($event->sender->attributes[$this->orderAttribute]==='') {
                $this->owner->{$this->orderAttribute} = 0;
            }
        }
    }

    /**
     * @param $event Event
     */
    public function afterSave($event)
    {
        if ($this->groupOrder !== null && $event->changedAttributes[$this->groupOrder] != $event->sender->attributes[$this->groupOrder]) {
            $this->groupOrdering($event->changedAttributes[$this->groupOrder]);
        }
    }

    /**
     * @param $event Event
     */
    public function beforeSave($event)
    {
        if ($this->groupOrder !== null && $event->sender->oldAttributes[$this->groupOrder] != $event->sender->attributes[$this->groupOrder]) {
            $this->moveItem(null, $event->sender->attributes[$this->orderAttribute]);
        } else if ($event->sender->oldAttributes[$this->orderAttribute] != $event->sender->attributes[$this->orderAttribute]) {
            $this->moveItem($event->sender->oldAttributes[$this->orderAttribute], $event->sender->attributes[$this->orderAttribute]);
        }
    }

    /**
     * @param $event Event
     */
    public function beforeInsert($event)
    {
        $this->moveItem(null, $event->sender->attributes[$this->orderAttribute]);
    }

    /**
     * @param $event Event
     */
    public function afterDelete($event)
    {
        $this->moveItem($event->sender->attributes[$this->orderAttribute], null);
    }


    /**
     * @param int|null $from
     * @param int|null $to
     */
    public function moveItem($from, $to)
    {
        $db = $this->owner->getDb();
        if ($from === null) {
            $fieldUpdate = [$this->orderAttribute => new Expression($db->quoteColumnName($this->orderAttribute) . '+1')];
            $condition = ['>=', $this->orderAttribute, (int)$to];
        } elseif ($to === null) {
            $fieldUpdate = [$this->orderAttribute => new Expression($db->quoteColumnName($this->orderAttribute) . '-1')];
            $condition = ['>', $this->orderAttribute, (int)$from];
        } elseif ($from > $to) {
            $fieldUpdate = [$this->orderAttribute => new Expression($db->quoteColumnName($this->orderAttribute) . '+1')];
            $condition = ['between', $this->orderAttribute, (int)$to, (int)$from - 1];
        } else {
            $fieldUpdate = [$this->orderAttribute => new Expression($db->quoteColumnName($this->orderAttribute) . '-1')];
            $condition = ['between', $this->orderAttribute, (int)$from + 1, (int)$to];
        }
        if ($this->groupOrder !== null) {
            $condition = ArrayHelper::merge(['and', $condition], [[$this->groupOrder => $this->owner->{$this->groupOrder}]]);
        }
        $this->owner->updateAll($fieldUpdate, $condition);
    }

    public function getMaxOrderValue()
    {
        $max = (new Query())->from($this->owner->tableName());
        if ($this->groupOrder !== null) {
            $max = $max->where([$this->groupOrder => $this->owner->{$this->groupOrder}]);
        }
        $max = $max->max($this->orderAttribute);
        return $max;
    }

    /**
     * @param null|integer $group_id
     */
    private function groupOrdering($group_id = null)
    {
        $db = $this->owner->getDb();
        $db->createCommand("update " . $db = $this->owner->tableName() . " set " .
                $db->quoteColumnName($this->orderAttribute) . " = (select @a:= @a + 1 from (select @a:=-1) as inc)" .
                (($this->groupOrder !== null) ? " where " . $db->quoteColumnName($this->groupOrder) . " = " . $group_id : "") .
                " order by " . $db->quoteColumnName($this->orderAttribute) . " asc")
            ->execute();
    }

    public function getOrderingList($valueField, $textField)
    {
        /** @var \yii\db\ActiveRecord $items */
        $items = new $this->owner;
        $items = $items->find();
        if ($this->groupOrder !== null) {
            $items = $items->where([$this->groupOrder => $this->owner->{$this->groupOrder}]);
        }
        $items = $items->orderBy([$this->orderAttribute => SORT_ASC]);
        $items = $items->all();
        \Yii::$app->i18n->translations['skinka/ordering/*'] = [
            'class' => PhpMessageSource::className(),
            'basePath' => '@vendor/skinka/yii2-ordering/messages',
            'fileMap' => [
                'skinka/ordering/core' => 'core.php',
            ],
        ];
        return ArrayHelper::merge(ArrayHelper::merge(['' => \Yii::t('skinka/ordering/core', '<< First >>')], ArrayHelper::map($items, $valueField, $textField)), ['-1' => \Yii::t('skinka/ordering/core', '<< Last >>')]);
    }
}
