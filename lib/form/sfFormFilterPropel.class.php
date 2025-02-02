<?php

/*
 * This file is part of the symfony package.
 * (c) Fabien Potencier <fabien.potencier@symfony-project.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * sfFormFilterPropel is the base class for filter forms based on Propel objects.
 *
 * @package    symfony
 * @subpackage form
 * @author     Fabien Potencier <fabien.potencier@symfony-project.com>
 * @version    SVN: $Id: sfFormFilterPropel.class.php 23018 2009-10-13 22:44:18Z Kris.Wallsmith $
 */
abstract class sfFormFilterPropel extends sfFormFilter
{
  /** merged forms list */
  protected $merged_forms = array();

  /**
   * Returns the current model name.
   *
   * @return string The model class name
   */
  abstract public function getModelName();

  /**
   * Returns the fields and their filter type.
   *
   * @return array An array of fields with their filter type
   */
  abstract public function getFields();

  /**
   * merge a form and add it to current merged forms
   * @see sfForm
   */
  public function mergeForm(sfForm $form)
  {
    parent::mergeForm($form);

    $this->merged_forms[] = $form;
  }

  /**
   * return the list of merged forms
   *
   * @return array
   */
  public function getMergedForms()
  {
    return $this->merged_forms;
  }

  /**
   * Returns a Propel Criteria based on the current values form the form.
   *
   * @return Criteria A Propel Criteria object
   */
  public function getCriteria()
  {
    if (!$this->isValid())
    {
      throw $this->getErrorSchema();
    }

    return $this->buildCriteria($this->getValues());
  }

  /**
   * Processes cleaned up values with user defined methods.
   *
   * To process a value before it is used by the buildCriteria() method,
   * you need to define an convertXXXValue() method where XXX is the PHP name
   * of the column.
   *
   * The method must return the processed value or false to remove the value
   * from the array of cleaned up values.
   *
   * @param  array An array of cleaned up values to process
   *
   * @return array An array of cleaned up values processed by the user defined methods
   */
  public function processValues($values)
  {
    // see if the user has overridden some column setter
    $originalValues = $values;
    foreach ($originalValues as $field => $value)
    {
      try
      {
        $method = sprintf('convert%sValue', call_user_func(array(constant($this->getModelName().'::PEER'), 'translateFieldName'), $field, BasePeer::TYPE_FIELDNAME, BasePeer::TYPE_PHPNAME));
      }
      catch (Exception $e)
      {
        // no a "real" column of this object
        continue;
      }

      if (method_exists($this, $method))
      {
        if (false === $ret = $this->$method($value))
        {
          unset($values[$field]);
        }
        else
        {
          $values[$field] = $ret;
        }
      }
    }

    return $values;
  }

  /**
   * Builds a Propel Criteria based on the passed values.
   *
   * @param  array    An array of parameters to build the Criteria object
   *
   * @return Criteria A Propel Criteria object
   */
  public function buildCriteria(array $values)
  {
    return $this->doBuildCriteria($this->processValues($values));
  }

  /**
   * Builds a Propel Criteria with processed values.
   *
   * Overload this method instead of {@link buildCriteria()} to avoid running
   * {@link processValues()} multiple times.
   *
   * @param  array $values
   *
   * @return Criteria
   */
  protected function doBuildCriteria(array $values)
  {
    $criteria = PropelQuery::from($this->getModelName());
    $peer = $criteria->getModelPeerName();

    $fields = $this->getFields();

    // add those fields that are not represented in getFields() with a null type
    $names = array_merge($fields, array_diff(array_keys($this->validatorSchema->getFields()), array_keys($fields)));
    $fields = array_merge($fields, array_combine($names, array_fill(0, count($names), null)));

    foreach ($fields as $field => $type)
    {
      if (!isset($values[$field]) || null === $values[$field] || '' === $values[$field])
      {
        continue;
      }

      try
      {
        $ucField = call_user_func(array($peer, 'translateFieldName'), $field, BasePeer::TYPE_FIELDNAME, BasePeer::TYPE_PHPNAME);
        $isReal = true;
      }
      catch (Exception $e)
      {
        $ucField = self::camelize($field);
        $isReal = false;
      }

      if (method_exists($this, $method = sprintf('add%sColumnCriteria', $ucField)))
      {
        // FormFilter::add[ColumnName]Criteria
        $this->$method($criteria, $field, $values[$field]);
      }
      elseif ($isReal && method_exists($this, $method = sprintf('add%sCriteria', $type)))
      {
        // FormFilter::add[ColumnType]Criteria
        $this->$method($criteria, $field, $values[$field]);
      }
      elseif (method_exists($criteria, $method = sprintf('filterBy%s', $ucField)))
      {
        // ModelCriteria::filterBy[ColumnName]
        $criteria->$method($values[$field]);
      }
      else
      {
        $processed = false;
        try
        {
          // is it an embedded flter ?
          $form = $this->getEmbeddedForm($field);
          $criteria->mergeWith($form->buildCriteria($values[$field]));
          $processed = true;
        }
        catch(InvalidArgumentException $e)
        {
        }

        if(!$processed)
        {
          // try with merged
          foreach ($this->getMergedForms() as $form)
          {
            if (array_key_exists($field, $form->getFields()))
            {
              $criteria->mergeWith($form->buildCriteria(array($field => $values[$field])));
              $processed = true;
            }
          }
        }

        if (!$processed)
        {
          throw new LogicException(sprintf('You must define a "%s" method in the %s class to be able to filter with the "%s" field.', sprintf('filterBy%s', $ucField), get_class($criteria), $field));
        }
      }
    }

    return $criteria;
  }

  protected function addForeignKeyCriteria(Criteria $criteria, $field, $value)
  {
    $colname = $this->getColname($field);

    //T::log(__METHOD__.print_r($value, true));
    if (is_array($value) && isset($value['is_empty']) && $value['is_empty'])
    {
        //T::log('empty');
        //$criteria->add($colname, null, Criteria::ISNULL);
        $criterion = $criteria->getNewCriterion($colname, '');
        $criterion->addOr($criteria->getNewCriterion($colname, null, Criteria::ISNULL));
        $criteria->add($criterion);
    }
        //Bug bei addOr, deswegen elseif statt if, gleichzeitige Anzeige GS und Haupthaus nicht mgl!!
    elseif (is_array($value) && isset($value['id']))
    {
        if ($value['id']) {
            $criterion = $criteria->getNewCriterion($colname, '');
            $criterion->addOr($criteria->getNewCriterion($colname, $value['id']));
            $criteria->add($criterion);
        }
    }
    elseif (is_array($value))
    {
      //T::log('array');
      $values = $value;
      $value = array_pop($values);
      $criterion = $criteria->getNewCriterion($colname, $value);

      foreach ($values as $value)
      {
        $criterion->addOr($criteria->getNewCriterion($colname, $value));
      }

      $criteria->add($criterion);
    }
    else
    {
      //T::log('plain');
      $criteria->add($colname, $value);
    }
  }

  protected function addTextCriteria(Criteria $criteria, $field, $values)
  {
    $colname = $this->getColname($field);

    if (is_array($values) && isset($values['is_empty']) && $values['is_empty'])
    {
      $criterion = $criteria->getNewCriterion($colname, '');
      $criterion->addOr($criteria->getNewCriterion($colname, null, Criteria::ISNULL));
      $criteria->add($criterion);
    }
    else if (is_array($values) && isset($values['text']) && '' != $values['text'])
    {
      $criteria->add($colname, str_replace('*', '%', $values['text'].'%'), Criteria::LIKE);
    }
    else if (is_scalar($values) && '' != $values)
    {
      $criteria->add($colname, str_replace('*', '%', $values.'%'), Criteria::LIKE);
    }
  }

  protected function addNumberCriteria(Criteria $criteria, $field, $values)
  {
    $colname = $this->getColname($field);

    if (is_array($values) && isset($values['is_empty']) && $values['is_empty'])
    {
      $criterion = $criteria->getNewCriterion($colname, '');
      $criterion->addOr($criteria->getNewCriterion($colname, null, Criteria::ISNULL));
      $criteria->add($criterion);
    }
    else if (is_array($values) && isset($values['text']) && '' != $values['text'])
    {
      $criteria->add($colname, $values['text']);
    }
    else if (is_scalar($values) && '' != $values)
    {
      $criteria->add($colname, $values);
    }
  }

  protected function addBooleanCriteria(Criteria $criteria, $field, $value)
  {
    $criteria->add($this->getColname($field), $value);
  }

  protected function addDateCriteria(Criteria $criteria, $field, $values)
  {
    $colname = $this->getColname($field);

    if (isset($values['is_empty']) && $values['is_empty'])
    {
      $criteria->add($colname, null, Criteria::ISNULL);
    }
    else
    {
      $criterion = null;
      if (null !== $values['from'] && null !== $values['to'])
      {
        $criterion = $criteria->getNewCriterion($colname, $values['from'], Criteria::GREATER_EQUAL);
        $criterion->addAnd($criteria->getNewCriterion($colname, $values['to'], Criteria::LESS_EQUAL));
      }
      else if (null !== $values['from'])
      {
        $criterion = $criteria->getNewCriterion($colname, $values['from'], Criteria::GREATER_EQUAL);
      }
      else if (null !== $values['to'])
      {
        $criterion = $criteria->getNewCriterion($colname, $values['to'], Criteria::LESS_EQUAL);
      }

      if (null !== $criterion)
      {
        $criteria->add($criterion);
      }
    }
  }

  protected function getColName($field)
  {
    return call_user_func(array(constant($this->getModelName().'::PEER'), 'translateFieldName'), $field, BasePeer::TYPE_FIELDNAME, BasePeer::TYPE_COLNAME);
  }

  protected function camelize($text)
  {
    $text = preg_replace_callback('#/(.?)#', array($this, 'camelizeFirstMatch'), $text);
    $text = preg_replace_callback('/(^|_|-)+(.)/', array($this, 'camelizeSecondMatch'), $text);

    return $text;
  }

  protected function camelizeFirstMatch($matches)
  {
    return '::'.strtoupper($matches[1]);
  }

  protected function camelizeSecondMatch($matches)
  {
    return strtoupper($matches[2]);
  }
}
