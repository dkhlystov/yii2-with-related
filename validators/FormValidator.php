<?php

namespace dkhlystov\validators;

use yii\validators\Validator;
use dkhlystov\forms\Form;

class FormValidator extends Validator
{

    /**
     * @var string
     */
    public $type = Form::HAS_ONE;

    /**
     * @inheritdoc
     */
    public function validateAttribute($model, $attribute)
    {
        if ($this->type == Form::HAS_ONE) {
            $this->validateOne($model, $attribute);
        } elseif ($this->type == Form::HAS_MANY) {
            $this->validateMany($model, $attribute);
        }
    }

    /**
     * Has one validation
     * @param yii\base\Model $model 
     * @param string $attribute 
     * @return void
     */
    protected function validateOne($model, $attribute)
    {
        if (!$model->$attribute->validate()) {
            $this->addError($model, $attribute, 'Attribute validation error.');
        }
    }

    /**
     * Has many validation
     * @param yii\base\Model $model 
     * @param string $attribute 
     * @return void
     */
    protected function validateMany($model, $attribute)
    {
        $hasError = false;
        foreach ($model->$attribute as $item) {
            if (!$item->validate()) {
                $hasError = true;
            }
        }

        if ($hasError) {
            $this->addError($model, $attribute, 'Attribute validation error.');
        }
    }

}
