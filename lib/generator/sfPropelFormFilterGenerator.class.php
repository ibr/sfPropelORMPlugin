<?php

/*
 * This file is part of the symfony package.
 * (c) Fabien Potencier <fabien.potencier@symfony-project.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Propel filter form generator.
 *
 * This class generates a Propel filter forms.
 *
 * @package    symfony
 * @subpackage generator
 * @author     Fabien Potencier <fabien.potencier@symfony-project.com>
 * @version    SVN: $Id: sfPropelFormFilterGenerator.class.php 24392 2009-11-25 18:35:39Z FabianLange $
 */
class sfPropelFormFilterGenerator extends sfPropelFormGenerator
{
  /**
   * Initializes the current sfGenerator instance.
   *
   * @param sfGeneratorManager $generatorManager A sfGeneratorManager instance
   */
  public function initialize(sfGeneratorManager $generatorManager)
  {
    parent::initialize($generatorManager);

    $this->setGeneratorClass('sfPropelFormFilter');
  }

  /**
   * Generates classes and templates in cache.
   *
   * @param array $params The parameters
   *
   * @return string The data to put in configuration cache
   */
  public function generate($params = array())
  {
    $this->params = $params;

    if (!isset($this->params['connection']))
    {
      throw new sfParseException('You must specify a "connection" parameter.');
    }

    if (!isset($this->params['model_dir_name']))
    {
      $this->params['model_dir_name'] = 'model';
    }

    if (!isset($this->params['filter_dir_name']))
    {
      $this->params['filter_dir_name'] = 'filter';
    }

    $this->loadBuilders();

    // create the project base class for all forms
    $file = sfConfig::get('sf_lib_dir').'/filter/BaseFormFilterPropel.class.php';
    if (!file_exists($file))
    {
      if (!is_dir($directory = dirname($file)))
      {
        mkdir($directory, 0777, true);
      }

      file_put_contents($file, $this->evalTemplate('sfPropelFormFilterBaseTemplate.php'));
    }

    // create a form class for every Propel class
    foreach ($this->dbMap->getTables() as $tableName => $table)
    {
      $behaviors = $table->getBehaviors();
      if (isset($behaviors['symfony']['filter']) && 'false' === $behaviors['symfony']['filter'])
      {
        continue;
      }

      $this->table = $table;

      // find the package to store filter forms in the same directory as the model classes
      $reflClass = new ReflectionClass($table->getClassname());
      $packages  = explode(DIRECTORY_SEPARATOR, $reflClass->getFileName());
      array_pop($packages);
      if (false === $pos = array_search($this->params['model_dir_name'], $packages))
      {
          $fileName = sfConfig::get('sf_root_dir') . DIRECTORY_SEPARATOR . 
            str_replace( '.', DIRECTORY_SEPARATOR, $table->getPackage()) . 
              DIRECTORY_SEPARATOR . $table->getClassname() . '.class.php';
          $packages  = explode(DIRECTORY_SEPARATOR, $fileName );
          array_pop($packages);

        if (false === $pos = array_search($this->params['model_dir_name'], $packages))
        {
          throw new InvalidArgumentException(
            sprintf('Unable to find the model dir name (%s) in the package %s.', $this->params['model_dir_name'], implode('.', $packages))
          );
        }
      }
      $packages[$pos] = $this->params['filter_dir_name'];
      $baseDir = implode(DIRECTORY_SEPARATOR, $packages);

      if (!is_dir($baseDir.'/base'))
      {
        mkdir($baseDir.'/base', 0777, true);
      }

      file_put_contents($baseDir.'/base/Base'.$table->getClassname().'FormFilter.class.php', $this->evalTemplate('sfPropelFormFilterGeneratedTemplate.php'));
      if (!file_exists($classFile = $baseDir.'/'.$table->getClassname().'FormFilter.class.php'))
      {
        file_put_contents($classFile, $this->evalTemplate('sfPropelFormFilterTemplate.php'));
      }
    }
  }

  /**
   * Returns a sfWidgetForm class name for a given column.
   *
   * @param  ColumnMap  $column A ColumnMap object
   *
   * @return string    The name of a subclass of sfWidgetForm
   */
  public function getWidgetClassForColumn(ColumnMap $column)
  {
    switch ($column->getType())
    {
      case PropelColumnTypes::BOOLEAN:
      case PropelColumnTypes::BOOLEAN_EMU:
      case PropelColumnTypes::ENUM:
        $name = 'Choice';
        break;
      case PropelColumnTypes::DATE:
      case PropelColumnTypes::TIME:
      case PropelColumnTypes::TIMESTAMP:
        $name = 'FilterDate';
        break;
      default:
        $name = 'FilterInput';
    }

    if ($column->isForeignKey())
    {
      $name = 'PropelChoice';
    }

    switch ($column->getName())
    {
      case 'massnahmekategorie_id':
        $name = 'FilterPropelChoice';
        break;
      case 'aufn_nr':
        $name = 'TeilnehmerAutocompleter';
        break;
      default:
        //echo $column->getName();
    }

    if (strtolower(substr($column->getName(), 0, 3)) == 'is_') $name = 'Choice';
    return sprintf('sfWidgetForm%s', $name);
  }

  /**
   * Returns a PHP string representing options to pass to a widget for a given column.
   *
   * @param  ColumnMap $column  A ColumnMap object
   *
   * @return string    The options to pass to the widget as a PHP string
   */
  public function getWidgetOptionsForColumn(ColumnMap $column)
  {
    $options = array();

    $withEmpty = $column->isNotNull() && !$column->isForeignKey() ? array("'with_empty' => false") : array();
    switch ($column->getType())
    {
      case PropelColumnTypes::BOOLEAN:
      case PropelColumnTypes::BOOLEAN_EMU:
        $options[] = "'choices' => array('' => '', 1 => 'ja', 0 => 'nein')";
        break;
      case PropelColumnTypes::DATE:
      case PropelColumnTypes::TIME:
      case PropelColumnTypes::TIMESTAMP:
        $options[] = "'from_date' => new sfWidgetFormDate(), 'to_date' => new sfWidgetFormDate()";
        $options = array_merge($options, $withEmpty);
        break;
      case PropelColumnTypes::ENUM:
        $valueSet = $column->getValueSet();
        $choices = array_merge(array(''=>'all'), $valueSet);
        $options[] = sprintf("'choices' => %s", preg_replace('/\s+/', '', var_export($choices, true)));

        break;
      default:
        $options = array_merge($options, $withEmpty);
    }
    if (strtolower(substr($column->getName(), 0, 3)) == 'is_')
    {
        $options = array(); //ohne $withEmpty!
        $options[] = "'choices' => array('' => '', 1 => 'ja', 0 => 'nein')";
    }
    if ($column->isForeignKey())
    {
      $options[] = sprintf('\'model\' => \'%s\', \'add_empty\' => true', $this->getForeignTable($column)->getClassname());

      $refColumn = $this->getForeignTable($column)->getColumn($column->getRelatedColumnName());
      if (!$refColumn->isPrimaryKey())
      {
        $options[] = sprintf('\'key_method\' => \'get%s\'', $refColumn->getPhpName());
      }
    }

    return count($options) ? sprintf('array(%s)', implode(', ', $options)) : '';
  }

  /**
   * Returns a sfValidator class name for a given column.
   *
   * @param  ColumnMap $column  A ColumnMap object
   *
   * @return string    The name of a subclass of sfValidator
   */
  public function getValidatorClassForColumn(ColumnMap $column)
  {
    switch ($column->getType())
    {
      case PropelColumnTypes::BOOLEAN:
      case PropelColumnTypes::BOOLEAN_EMU:
      case PropelColumnTypes::ENUM:
        $name = 'Choice';
        break;
      case PropelColumnTypes::DOUBLE:
      case PropelColumnTypes::FLOAT:
      case PropelColumnTypes::NUMERIC:
      case PropelColumnTypes::DECIMAL:
      case PropelColumnTypes::REAL:
        $name = 'Number';
        break;
      case PropelColumnTypes::INTEGER:
      case PropelColumnTypes::SMALLINT:
      case PropelColumnTypes::TINYINT:
      case PropelColumnTypes::BIGINT:
        $name = 'Integer';
        break;
      case PropelColumnTypes::DATE:
      case PropelColumnTypes::TIME:
      case PropelColumnTypes::TIMESTAMP:
        $name = 'DateRange';
        break;
      default:
        $name = 'Pass';
    }
    if (strtolower(substr($column->getName(), 0, 3)) == 'is_') $name = 'Choice';

    if ($column->isPrimaryKey() || $column->isForeignKey())
    {
      $name = 'PropelChoice';
    }

    return sprintf('sfValidator%s', $name);
  }

  /**
   * Returns a PHP string representing options to pass to a validator for a given column.
   *
   * @param  ColumnMap $column  A ColumnMap object
   *
   * @return string    The options to pass to the validator as a PHP string
   */
  public function getValidatorOptionsForColumn(ColumnMap $column)
  {
    $options = array('\'required\' => false');

    if ($column->isForeignKey())
    {
      $options[] = sprintf('\'model\' => \'%s\', \'column\' => \'%s\'', $this->getForeignTable($column)->getClassname(), $this->translateColumnName($column, true));
    }
    else if ($column->isPrimaryKey())
    {
      $options[] = sprintf('\'model\' => \'%s\', \'column\' => \'%s\'', $column->getTable()->getClassname(), $this->translateColumnName($column));
    }
    else if (strtolower(substr($column->getName(), 0, 3)) == 'is_')
    {
        $options[] = "'choices' => array('', 1, 0)";
    }
    else
    {
      switch ($column->getType())
      {
        case PropelColumnTypes::BOOLEAN:
        case PropelColumnTypes::BOOLEAN_EMU:
          $options[] = "'choices' => array('', 1, 0)";
          break;
        case PropelColumnTypes::DATE:
        case PropelColumnTypes::TIME:
        case PropelColumnTypes::TIMESTAMP:
          $options[] = "'from_date' => new sfValidatorDate(array('required' => false)), 'to_date' => new sfValidatorDate(array('required' => false))";
          break;
        case PropelColumnTypes::ENUM:
          $valueSet = $column->getValueSet();
          $options[] = sprintf("'choices' => %s", preg_replace('/\s+/', '', var_export(array_keys($valueSet), true)));
          break;
      }
    }

    return count($options) ? sprintf('array(%s)', implode(', ', $options)) : '';
  }

  public function getValidatorForColumn($column)
  {
    $format = 'new %s(%s)';
    if (in_array($class = $this->getValidatorClassForColumn($column), array('sfValidatorInteger', 'sfValidatorNumber')))
    {
      $format = 'new sfValidatorSchemaFilter(\'text\', new %s(%s))';
    }

    return sprintf($format, $class, $this->getValidatorOptionsForColumn($column));
  }

  public function getType(ColumnMap $column)
  {
    if ($column->isForeignKey())
    {
      return 'ForeignKey';
    }
    if (strtolower(substr($column->getName(), 0, 3)) == 'is_')
    {
      return 'Boolean';
    }
    switch ($column->getType())
    {
      case PropelColumnTypes::BOOLEAN:
      case PropelColumnTypes::BOOLEAN_EMU:
        return 'Boolean';
      case PropelColumnTypes::DATE:
      case PropelColumnTypes::TIME:
      case PropelColumnTypes::TIMESTAMP:
        return 'Date';
      case PropelColumnTypes::DOUBLE:
      case PropelColumnTypes::FLOAT:
      case PropelColumnTypes::NUMERIC:
      case PropelColumnTypes::DECIMAL:
      case PropelColumnTypes::REAL:
      case PropelColumnTypes::INTEGER:
      case PropelColumnTypes::SMALLINT:
      case PropelColumnTypes::TINYINT:
      case PropelColumnTypes::BIGINT:
        return 'Number';
      default:
        return 'Text';
    }
  }
}
