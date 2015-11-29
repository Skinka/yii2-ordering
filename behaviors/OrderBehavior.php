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

/**
 *
 * @property ActiveRecord $owner
 *
 * @property string $orderAttribute
 * @property array $groupOrder
 */
class OrderBehavior extends Behavior
{
    public $orderAttribute = 'ordering';
    public $groupOrder = [];

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
            if ($event->sender->attributes[$this->orderAttribute] === '') {
                $this->owner->{$this->orderAttribute} = 0;
            }
        }
    }

    /**
     * @param $event Event
     */
    public function afterSave($event)
    {
        if (!empty($this->groupOrder)) {
            $send = false;
            $groupOrder = [];
            foreach ($this->groupOrder as $item) {
                if (isset($event->changedAttributes[$item]) && $event->changedAttributes[$item] != $event->sender->attributes[$item]) {
                    $send = true;
                }
                $groupOrder = ArrayHelper::merge($groupOrder, [$item => $event->sender->attributes[$item]]);
            }
            if ($send) {
                $this->groupOrdering($groupOrder);
            }
        }
    }

    /**
     * @param $event Event
     */
    public function beforeSave($event)
    {
        if ($event->sender->oldAttributes[$this->orderAttribute] != $event->sender->attributes[$this->orderAttribute]) {
            $this->moveItem($event->sender->oldAttributes[$this->orderAttribute],
                $event->sender->attributes[$this->orderAttribute]);
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
        if (!empty($this->groupOrder)) {
            $group = [];
            foreach ($this->groupOrder as $item) {
                $group[] = [$item => $this->owner->{$item}];
            }
            $condition = ArrayHelper::merge(['and', $condition], $group);
        }
        $this->owner->updateAll($fieldUpdate, $condition);
    }

    public function getMaxOrderValue()
    {
        $max = (new Query())->from($this->owner->tableName());
        if (!empty($this->groupOrder)) {
            foreach ($this->groupOrder as $item) {
                $max->andWhere([$item => $this->owner->{$item}]);
            }
        }
        return $max->max($this->orderAttribute);
    }

    private function groupOrdering($groupItems = [])
    {
        /** @var \yii\db\Connection $db */
        $db = $this->owner->getDb();
        $sql = "update " . $this->owner->tableName() . " set " .
                $db->quoteColumnName($this->orderAttribute) . " = (select @a:= @a + 1 from (select @a:=-1) as inc)";
        if ($this->groupOrder !== null && !empty($groupItems)) {
            $groupSql = ' where ';
            foreach ($groupItems as $name => $value) {
                if (is_string($value)) {
                    $groupSql .= '`'.$name.'`' . " = '" . $value . "' and ";
                } else {
                    $groupSql .= '`'.$name.'`' . " = " . $value . ' and ';
                }
            }
            $sql .= substr($groupSql, 0, -5);
        }
        $sql .= " order by `" . $this->orderAttribute . "` asc";
        \Yii::$app->db->createCommand($sql)->execute();
    }

    public function getOrderingList($valueField, $textField)
    {
        /** @var \yii\db\ActiveRecord $items */
        $items = $this->owner;
        $items = $items::find();
        /** @var \yii\db\ActiveQuery $items */
        if (!empty($this->groupOrder)) {
            foreach ($this->groupOrder as $item) {
                $items->andWhere([$item => $this->owner->{$item}]);
            }
        }
        $items->orderBy([$this->orderAttribute => SORT_ASC]);
        \Yii::$app->i18n->translations['skinka/ordering/*'] = [
            'class' => PhpMessageSource::className(),
            'basePath' => '@vendor/skinka/yii2-ordering/messages',
            'fileMap' => [
                'skinka/ordering/core' => 'core.php',
            ],
        ];
        return ArrayHelper::merge(ArrayHelper::merge(['' => \Yii::t('skinka/ordering/core', '<< First >>')],
            ArrayHelper::map($items->asArray()->all(), $valueField, $textField)),
            ['-1' => \Yii::t('skinka/ordering/core', '<< Last >>')]);
    }
}
