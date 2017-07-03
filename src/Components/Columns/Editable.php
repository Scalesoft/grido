<?php

/**
 * This file is part of the Grido (http://grido.bugyik.cz)
 *
 * Copyright (c) 2014 Petr Bugyík (http://petr.bugyik.cz)
 *
 * For the full copyright and license information, please view
 * the file LICENSE.md that was distributed with this source code.
 */

namespace Grido\Components\Columns;

use Grido\Exception;
use Nette;

/**
 * An inline editable column.
 *
 * @package     Grido
 * @subpackage  Components\Columns
 * @author      Jakub Kopřiva <kopriva.jakub@gmail.com>
 * @author      Petr Bugyík
 *
 * @property \Nette\Forms\IControl|callable $editableControlPrototype
 * @property callback                       $editableCallback
 * @property callback                       $editableValueCallback
 * @property callback                       $editableRowCallback
 * @property bool                           $editable
 * @property bool                           $editableDisabled
 */
abstract class Editable extends Column
{
    /** @var bool */
    protected $editable = FALSE;

    /** @var bool */
    protected $editableAutoInit = FALSE;

    /** @var callable */
    protected $disableEditable = NULL;

    /** @var bool */
    protected $editableDisabled = FALSE;

    /** @var \Nette\Forms\IControl|callable Custom control for inline editing */
    protected $editableControlPrototype;

    /** @var callback for custom handling with edited data; function($id, $newValue, $oldValue, Editable $column) {} */
    protected $editableCallback;

    /** @var callback for custom value; function($row, Columns\Editable $column) {} */
    protected $editableValueCallback;

    /** @var callback for getting row; function($row, Columns\Editable $column) {} */
    protected $editableRowCallback;

    /** @var Nette\Utils\Html */
    protected $inlineEditConfirmPrototype;

    public function __construct($grid, $name, $label)
    {
        parent::__construct($grid, $name, $label);

        $this->inlineEditConfirmPrototype = Nette\Utils\Html::el('button', ['data-confirm-inline-edit' => TRUE]);
    }

    /**
     * Sets column as editable.
     *
     * @param callback              $callback function($id, $newValue, $oldValue, Columns\Editable $column) {}
     * @param \Nette\Forms\IControl $control
     * @param bool                  $editableAutoInit
     * @param callable              $disableEditable
     *
     * @return static
     */
    public function setEditable($callback = NULL, $control = NULL, $editableAutoInit = FALSE, callable $disableEditable = NULL)
    {
        $this->editable = TRUE;
        $this->editableAutoInit = $editableAutoInit;
        $this->disableEditable = $disableEditable;
        $this->setClientSideOptions();

        $callback && $this->setEditableCallback($callback);
        $control && $this->setEditableControl($control);

        return $this;
    }

    /**
     * Sets control for inline editation.
     *
     * @param \Nette\Forms\IControl|callable $control
     *
     * @return static
     * @throws \InvalidArgumentException
     */
    public function setEditableControl($control)
    {
        if ($control instanceof \Nette\Forms\IControl || is_callable($control)) {
            $this->isEditable() ?: $this->setEditable();
            $this->editableControlPrototype = $control;
        }
        else {
            throw new \InvalidArgumentException('Parameter must be \Nette\Forms\IControl or callable');
        }

        return $this;
    }

    /**
     * Sets editable callback.
     * @param callback $callback function($id, $newValue, $oldValue, Columns\Editable $column) {}
     * @return static
     */
    public function setEditableCallback($callback)
    {
        $this->isEditable() ?: $this->setEditable();
        $this->editableCallback = $callback;

        return $this;
    }

    /**
     * Sets editable value callback.
     * @param callback $callback for custom value; function($row, Columns\Editable $column) {}
     * @return static
     */
    public function setEditableValueCallback($callback)
    {
        $this->isEditable() ?: $this->setEditable();
        $this->editableValueCallback = $callback;

        return $this;
    }

    /**
     * Sets editable row callback - it's required when used editable collumn with customRenderCallback
     * @param callback $callback for getting row; function($id, Columns\Editable $column) {}
     * @return static
     */
    public function setEditableRowCallback($callback)
    {
        $this->isEditable() ?: $this->setEditable();
        $this->editableRowCallback = $callback;

        return $this;
    }

    /**
     * @return static
     */
    public function disableEditable()
    {
        $this->editable = FALSE;
        $this->editableDisabled = TRUE;

        return $this;
    }

    /**
     * @throws Exception
     */
    protected function setClientSideOptions()
    {
        $options = $this->grid->getClientSideOptions();
        if (!isset($options['editable'])) { //only once
            $this->grid->setClientSideOptions(['editable' => TRUE]);
            $this->grid->onRender[] = function(\Grido\Grid $grid)
            {
                foreach ($grid->getComponent(Column::ID)->getComponents() as $column) {
                    if (!$column instanceof Editable || !$column->isEditable()) {
                        continue;
                    }

                    $colDb = $column->getColumn();
                    $colName = $column->getName();
                    $isMissing = function ($method) use ($grid) {
                        return $grid->model instanceof \Grido\DataSources\Model
                            ? !method_exists($grid->model->dataSource, $method)
                            : TRUE;
                    };

                    if (($column->editableCallback === NULL && (!is_string($colDb) || strpos($colDb, '.'))) ||
                        ($column->editableCallback === NULL && $isMissing('update'))
                    ) {
                        $msg = "Column '$colName' has error: You must define callback via setEditableCallback().";
                        throw new Exception($msg);
                    }

                    if ($column->editableRowCallback === NULL && $column->customRender && $isMissing('getRow')) {
                        $msg = "Column '$colName' has error: You must define callback via setEditableRowCallback().";
                        throw new Exception($msg);
                    }
                }
            };
        }
    }

    /**********************************************************************************************/

    /**
     * Returns header cell prototype (<th> html tag).
     * @return \Nette\Utils\Html
     */
    public function getHeaderPrototype()
    {
        $th = parent::getHeaderPrototype();

        if ($this->isEditable()) {
            $th->setAttribute('data-grido-editable-handler', $this->link('editable!'));
            $th->setAttribute('data-grido-editableControl-handler', $this->link('editableControl!'));
        }

        return $th;
    }

    /**
     * Returns cell prototype (<td> html tag).
     * @param mixed $row
     * @return \Nette\Utils\Html
     */
    public function getCellPrototype($row = NULL)
    {
        $td = parent::getCellPrototype($row);

        if ($this->isEditable() && $row !== NULL && $this->enableEditable($row)) {
            if (!in_array('editable', $td->class)) {
                $td->class[] = 'editable';
            }

            if ($this->isEditableAutoInit()) {
                if ( !in_array('editable-auto-init', $td->class)) {
                    $td->class[] = 'editable-auto-init';
                }
            }

            $value = $this->editableValueCallback === NULL
                ? $this->getValue($row)
                : call_user_func_array($this->editableValueCallback, [$row, $this]);

            $td->setAttribute('data-grido-editable-value', $value);
        }

        return $td;
    }

    public function getInlineEditConfirmPrototype()
    {
        return $this->inlineEditConfirmPrototype;
    }

    /**
     * Returns control for editation.
     *
     * @param \Nette\Forms\Container $container
     * @param null                   $name
     *
     * @return \Nette\Forms\Controls\BaseControl
     * @throws \RuntimeException
     */
    public function getEditableControl(\Nette\Forms\Container $container, $name)
    {
        $editableControl = NULL;

        if ($this->editableControlPrototype === NULL) {
            $editableControl = $container->addText($name);
            $editableControl->controlPrototype->class[] = 'form-control';

            return $editableControl;
        }
        else if ($this->editableControlPrototype instanceof \Nette\Forms\Controls\BaseControl) {
            $editableControl = clone $this->editableControlPrototype;
        }
        else if (is_callable($this->editableControlPrototype)) {
            $editableControl = call_user_func($this->editableControlPrototype, $container, $name);
        }

        if ( !$editableControl instanceof \Nette\Forms\Controls\BaseControl) {
            throw new \RuntimeException('Editable control is not instanceof \Nette\Forms\Controls\BaseControl');
        }

        return $editableControl;
    }

    /**
     * @return callback
     * @internal
     */
    public function getEditableCallback()
    {
        return $this->editableCallback;
    }

    /**
     * @return callback
     * @internal
     */
    public function getEditableValueCallback()
    {
        return $this->editableValueCallback;
    }

    /**
     * @return callback
     * @internal
     */
    public function getEditableRowCallback()
    {
        return $this->editableRowCallback;
    }

    /**
     * @return bool
     * @internal
     */
    public function isEditable()
    {
        return $this->editable;
    }

    /**
     * @return bool
     * @internal
     */
    public function isEditableAutoInit()
    {
        return $this->editableAutoInit;
    }

    /**
     * @return bool
     * @internal
     */
    public function isEditableDisabled()
    {
        return $this->editableDisabled;
    }

    /**********************************************************************************************/

    /**
     * @internal
     */
    public function handleEditable($id, $newValue, $oldValue)
    {
        $this->grid->onRender($this->grid);

        if (!$this->presenter->isAjax() || !$this->isEditable()) {
            $this->presenter->terminate();
        }

        $success = $this->editableCallback
            ? call_user_func_array($this->editableCallback, [$id, $newValue, $oldValue, $this])
            : $this->grid->model->update($id, [$this->getColumn() => $newValue], $this->grid->primaryKey);

        if (is_callable($this->customRender)) {
            $row = $this->editableRowCallback
                ? call_user_func_array($this->editableRowCallback, [$id, $this])
                : $this->grid->model->getRow($id, $this->grid->primaryKey);
            $html = call_user_func_array($this->customRender, [$row]);
        } else {
            $html = $this->formatValue($newValue);
        }

        $payload = ['updated' => (bool) $success, 'html' => (string) $html];
        $response = new \Nette\Application\Responses\JsonResponse($payload);
        $this->presenter->sendResponse($response);
    }

    /**
     * @internal
     */
    public function handleEditableControl($value)
    {
        $this->grid->onRender($this->grid);

        if (!$this->presenter->isAjax() || !$this->isEditable()) {
            $this->presenter->terminate();
        }

        $control = $this->getEditableControl($this->getForm(), 'edit' . $this->getName());
        $control->setValue($value);

        $response = new \Nette\Application\Responses\TextResponse($control->getControl()->render());
        $this->presenter->sendResponse($response);
    }

    public function render($row)
    {
        if ($this->editableAutoInit && $this->enableEditable($row)) {
            $replacements = $this->replacements;
            $this->replacements = [];
            $value = parent::render($row);
            $this->replacements = $replacements;

            $id = $this->getGrid()->getPropertyAccessor()->getValue($row, 'id');

            $control = $this->getEditableControl($this->getEditContainer('edit' . $this->getName()), $id);
            $control->setValue($value);

            return $control->getControl() . $this->inlineEditConfirmPrototype;
        }
        else {
            return parent::render($row);
        }
    }

    /**
     * @param $name
     *
     * @return \Nette\Forms\Container
     */
    protected function getEditContainer($name)
    {
        $form = $this->getForm();

        if ( !isset($form->components[$name])) {
            $form->addContainer($name);
        }

        return $form->components[$name];
    }

    protected function enableEditable($row)
    {
        if ( !$this->isEditable()) {
            return FALSE;
        }
        if (is_null($this->disableEditable)) {
            return TRUE;
        }

        return !call_user_func($this->disableEditable, $row);
    }
}
