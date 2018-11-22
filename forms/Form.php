<?php

namespace dkhlystov\forms;

use yii\base\Model;
use yii\db\ActiveRecord;
use yii\helpers\Html;
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
        return array_key_exists($name, $this->_forms) ? $this->_forms[$name] : parent::__get($name);
    }

    /**
     * @inheritdoc
     */
    public function __set($name, $value)
    {
        if (array_key_exists($name, $this->_forms)) {
            if ($this->_config[$name]['type'] == self::HAS_ONE) {
                $this->nestedFormSet($this->_forms[$name], $value);
            } else {
                $class = $this->_config[$name]['class'];
                $forms = [];
                foreach ($value as $key => $data) {
                    $form = new $class;
                    $form->formName = Html::getInputName($this, $name) . '[' . $key . ']';
                    $this->nestedFormSet($form, $data);
                    $forms[$key] = $form;
                }
                $this->_forms[$name] = $forms;
            }
        } else {
            parent::__set($name, $value);
        }
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        // Prepare config
        $config = [];
        foreach ($this->nestedForms() as $item) {
            $config[$item[0]] = [
                'type' => $item[1],
                'class' => $item[2],
            ];
        }
        $this->_config = $config;

        // Init nested forms
        $forms = [];
        foreach ($this->_config as $attribute => $config) {
            if ($config['type'] == self::HAS_ONE) {
                $forms[$attribute] = new $config['class'];
                $forms[$attribute]->formName = Html::getInputName($this, $attribute);
            } elseif ($config['type'] == self::HAS_MANY) {
                $forms[$attribute] = [];
            }
        }
        $this->_forms = $forms;
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        $rules = [];
        foreach ($this->_config as $attribute => $config) {
            $rules[] = [$attribute, FormValidator::classNAme(), 'type' => $config['type']];
        }
        return $rules;
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
    public function nestedForms()
    {
        return [];
    }

    /**
     * Assign form with active record object
     * @param ActiveRecord $object 
     * @return void
     */
    public function assign($object)
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
    private function nestedFormSet(Form $form, $data)
    {
        if ($data instanceof ActiveRecord) {
            $form->assign($data);
        } elseif (is_array($data)) {
            $form->setAttributes($data);
        }
    }

}
