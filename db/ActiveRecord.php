<?php

namespace dkhlystov\db;

use Exception;
use ReflectionClass;
use yii\db\ActiveQueryInterface;
use dkhlystov\forms\Form;

/**
 * Extended [[yii\db\ActiveRecord]]
 * Can set relations with Array or [[dkhlystov\forms\Form]] data
 * Can save with relations
 */
class ActiveRecord extends \yii\db\ActiveRecord
{

    /**
     * @inheritdoc
     */
    public function __set($name, $value)
    {
        // Check for get method
        $methodName = 'get' . ucfirst($name);
        if (!$this->hasMethod($methodName)) {
            return parent::__set($name, $value);
        }

        // Check for relation
        $relation = call_user_func(array($this,$methodName));
        if (!($relation instanceof ActiveQueryInterface)) {
            return parent::__set($name, $value);
        }

        // Assign related
        if (is_array($this->$name)) {
            $this->assignMany($name, $value, $relation->modelClass);
        } else {
            $this->assignOne($name, $value, $relation->modelClass);
        }
    }

    /**
     * Assign HAS_ONE relation with value
     * @param string $name relation name
     * @param array|Form $value 
     * @param string $class relation class name
     * @return void
     */
    private function assignOne($name, $value, $class)
    {
        $object = $this->$name;
        if ($object === null) {
            $object = new $class;
        }
        $this->assignObject($object, $value);
        $this->populateRelation($name, $object);
    }

    /**
     * Assign HAS_MANY relation with value
     * @param string $name relation name
     * @param array $value 
     * @param string $class relation class name
     * @return void
     */
    private function assignMany($name, $value, $class)
    {
        $objects = [];
        foreach ($value as $v) {
            // Object
            $object = new $class;
            $this->assignObject($object, $v);

            // Primary key
            $pkIsSet = true;
            foreach ($object->getPrimaryKey(true) as $key => $value) {
                if (empty($value)) {
                    $pkIsSet = false;
                    break;
                }
            }
            if ($pkIsSet) {
                $object->setIsNewRecord(false);
            }

            $objects[] = $object;
        }
        $this->populateRelation($name, $objects);
    }

    /**
     * Assign object with value
     * @param ActiveRecord $object 
     * @param array|Form $value 
     * @return void
     */
    private function assignObject($object, $value)
    {
        if (is_array($value)) {
            $object->setAttributes($value, false);
        } elseif ($value instanceof Form) {
            $value->assignTo($object);
        }
    }

    /**
     * Save with relations using transaction
     * @param bool $runValidation 
     * @param array|null $attributeNames 
     * @return bool
     */
    public function saveWithRelated($runValidation = true, $attributeNames = null)
    {
        // Save with transaction
        $transaction = self::getDb()->beginTransaction();
        try {
            $success = $this->saveWithRelatedInternal($runValidation, $attributeNames);
        } catch (Exception $e) {
            $transaction->rollBack();
            throw $e;
        }

        // Transaction process
        if ($success) {
            $transaction->commit();
        } else {
            $transaction->rollBack();
        }

        // Return
        return $success;
    }

    /**
     * Save with relations
     * @param bool $runValidation 
     * @param array|null $attributeNames 
     * @return bool
     */
    public function saveWithRelatedInternal($runValidation = true, $attributeNames = null)
    {
        // Get relation names
        $stack = [];
        $reflector = new ReflectionClass(self::className());
        $baseClassMethods = get_class_methods('yii\db\ActiveRecord');
        foreach ($reflector->getMethods() as $method) {
            if (in_array($method->name, $baseClassMethods)) {
                continue;
            }
            if (strpos($method->name, 'get') !== 0) {
                continue;
            }
            $relation = call_user_func(array($this,$method->name));
            if ($relation instanceof ActiveQueryInterface) {
                $stack[] = lcfirst(substr($method->name, 3));
            }
        }

        // Prepare names
        $names = $attributeNames ? $attributeNames : [];
        $attributeNames = $relationAttributeNames = [];
        foreach ($names as $key => $value) {
            if (is_array($value)) {
                $relationAttributeNames[$key] = $value;
            } elseif (in_array($value, $stack)) {
                $relationAttributeNames[$value] = [];
            } else {
                $attributeNames[] = $value;
            }
        }

        // Save object
        $success = $this->save($runValidation, empty($attributeNames) ? null : $attributeNames);

        // Save related
        if ($success) {
            $stack = array_intersect($stack, array_keys($relationAttributeNames));
            foreach ($stack as $relationName) {
                $related = $this->$relationName;
                $attributeNames = empty($relationAttributeNames[$relationName]) ? null : $relationAttributeNames[$relationName];
                if (is_array($related)) {
                    $success = $this->saveRelatedMany($relationName, $related, $runValidation, $attributeNames);
                } else {
                    $success = $this->saveRelatedOne($relationName, $related, $runValidation, $attributeNames);
                }
                if ($success === false) {
                    break;
                }
            }
        }

        return $success;
    }

    /**
     * Save related object with hasOne relation
     * @param string $relationName 
     * @param ActiveRecord $related 
     * @param bool $runValidation 
     * @param array|null $attributeNames 
     * @return bool
     */
    private function saveRelatedOne($relationName, $related, $runValidation, $attributeNames)
    {
        if ($related === null) {
            $old = $this->getRelation($relationName)->one();
            if ($old !== null) {
                $old->delete();
            }
            return true;
        }

        // Link
        $this->linkRelated($relationName, $related);

        // Save
        if ($related->hasMethod('saveWithRelatedInternal')) {
            $success = $related->saveWithRelatedInternal($runValidation, $attributeNames);
        } else {
            $success = $related->save($runValidation, $attributeNames);
        }
        return $success;
    }

    /**
     * Save related objects with hasMany relation
     * @param string $relationName 
     * @param array $related 
     * @param bool $runValidation 
     * @param array|null $attributeNames 
     * @return bool
     */
    private function saveRelatedMany($relationName, $related, $runValidation, $attributeNames)
    {
        // Old
        $old = [];
        foreach ($this->getRelation($relationName)->all() as $object) {
            $old[serialize($object->getPrimaryKey())] = $object;
        };

        // Save
        foreach ($related as $object) {
            $pk = serialize($object->getPrimaryKey());
            if (array_key_exists($pk, $old)) {
                unset($old[$pk]);
            }
            // Link
            $this->linkRelated($relationName, $object);
            // Save
            if ($object->hasMethod('saveWithRelatedInternal')) {
                $success = $object->saveWithRelatedInternal($runValidation, $attributeNames);
            } else {
                $success = $object->save($runValidation, $attributeNames);
            }
            if ($success === false) {
                break;
            }
        }

        //Delete
        if ($success) {
            foreach ($old as $object) {
                $object->delete();
            }
        }

        //Return
        return $success;
    }

    /**
     * Link object to current model
     * @param string $relationName 
     * @param ActiveRecord $related 
     * @return void
     */
    private function linkRelated($relationName, $related)
    {
        $relation = $this->getRelation($relationName);
        $p1 = $related->isPrimaryKey(array_keys($relation->link));
        $p2 = $this->isPrimaryKey(array_values($relation->link));
        if (($p1 && $p2) || (!$p1 && !$p2)) {
            throw new InvalidCallException('Unable to link models.');
        } elseif ($p1) {
            foreach ($relation->link as $fk => $pk) {
                $this->$pk = $related->$fk;
            }
        } else {
            foreach ($relation->link as $fk => $pk) {
                $related->$fk = $this->$pk;
            }
        }
    }

}
