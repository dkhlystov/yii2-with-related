<?php

namespace dkhlystov\forms;

use ArrayObject;
use yii\base\Model;
use yii\db\ActiveRecord;
use yii\helpers\Html;
use yii\validators\Validator;
use dkhlystov\validators\FormValidator;

class Form extends Model
{

    // Type
    const HAS_ONE = 'one';
    const HAS_MANY = 'many';

    /**
     * @var array
     */
    private $_config = [];

    /**
     * @var array
     */
    private $_forms = [];

    /**
     * @var string|null HTML form name
     */
    public $formName;

    /**
     * @inheritdoc
     */
    public function __get($name)
    {
        // Inherit
        if (!array_key_exists($name, $this->_forms)) {
            return parent::__get($name);
        }

        // Forms
        return $this->_forms[$name];
    }

    /**
     * @inheritdoc
     */
    public function __set($name, $value)
    {
        // Inherit
        if (!array_key_exists($name, $this->_forms)) {
            parent::__set($name, $value);
            return;
        }

        // One
        if ($this->_config[$name]['type'] == self::HAS_ONE) {
            $this->formSet($this->_forms[$name], $value);
            return;
        }

        // Many
        if (!is_array($value)) {
            $value = [];
        }
        $class = $this->_config[$name]['class'];
        $forms = [];
        foreach ($value as $key => $data) {
            $form = new $class;
            $form->formName = Html::getInputName($this, $name) . '[' . $key . ']';
            $this->formSet($form, $data);
            $forms[$key] = $form;
        }
        $this->_forms[$name] = $forms;
    }

    /**
     * @inheritdoc
     */
    public function __construct($config = [])
    {
        // Prepare config
        $c = [];
        foreach ($this->forms() as $item) {
            $c[$item[0]] = [
                'type' => $item[1],
                'class' => $item[2],
            ];
        }
        $this->_config = $c;

        // Init nested forms
        $forms = [];
        foreach ($this->_config as $attribute => $c) {
            if ($c['type'] == self::HAS_ONE) {
                $forms[$attribute] = new $c['class'];
                $forms[$attribute]->formName = Html::getInputName($this, $attribute);
            } elseif ($c['type'] == self::HAS_MANY) {
                $forms[$attribute] = [];
            }
        }
        $this->_forms = $forms;

        // Inherit
        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function createValidators()
    {
        // Create nested forms validators
        $rules = $this->rules();
        foreach ($this->_config as $attribute => $config) {
            $rules[] = [$attribute, FormValidator::className(), 'type' => $config['type']];
        }

        // Inherit
        $validators = new ArrayObject();
        foreach ($rules as $rule) {
            if ($rule instanceof Validator) {
                $validators->append($rule);
            } elseif (is_array($rule) && isset($rule[0], $rule[1])) { // attributes, validator type
                $validator = Validator::createValidator($rule[1], $this, (array) $rule[0], array_slice($rule, 2));
                $validators->append($validator);
            } else {
                throw new InvalidConfigException('Invalid validation rule: a rule must specify both attribute names and validator type.');
            }
        }
        return $validators;
    }

    /**
     * @inheritdoc
     */
    public function formName()
    {
        return $this->formName === null ? parent::formName() : $this->formName;
    }

    /**
     * Configure nested forms
     * [attribute_name, relation_type, form_class]
     * relation_type = 'one'|'many'
     * @return array
     */
    public function forms()
    {
        return [];
    }

    /**
     * Assign form with active record object
     * @param ActiveRecord $object 
     * @return void
     */
    public function assignFrom($object)
    {
        $this->setAttributes($object->getAttributes());
        foreach ($this->_config as $attribute => $_config) {
            $this->$attribute = $object->$attribute;
        }
    }

    /**
     * Assign active record object with form
     * @param ActiveRecord $object 
     * @return void
     */
    public function assignTo($object)
    {
        $object->setAttributes($this->getAttributes());
        foreach ($this->_config as $attribute => $_config) {
            $object->$attribute = $this->$attribute;
        }
    }

    /**
     * Fill form with data from object or request
     * @param Form $form 
     * @param ActiveRecord|array $data 
     * @return void
     */
    private function formSet(Form $form, $data)
    {
        if ($data instanceof ActiveRecord) {
            $form->assignFrom($data);
        } elseif (is_array($data)) {
            $form->setAttributes($data);
        }
    }

}
