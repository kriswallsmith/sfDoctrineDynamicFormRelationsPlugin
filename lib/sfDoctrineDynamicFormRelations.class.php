<?php

/**
 * Adds a method to embed a relation that automatically adjusts for incoming data.
 *
 * This class extends sfForm so we can manipulate protected members of form objects.
 *
 * @package    sfDoctrineDynamicFormRelationsPlugin
 * @subpackage form
 * @author     Kris Wallsmith <kris.wallsmith@symfony-project.com>
 * @author     Christian Schaefer <caefer@ical.ly>
 */
class sfDoctrineDynamicFormRelations extends sfForm
{
  protected
    $dispatcher = null;

  /**
   * Constructor.
   *
   * Disables the parent sfForm constructor.
   */
  public function __construct(sfEventDispatcher $dispatcher)
  {
    $this->dispatcher = $dispatcher;

    $this->connect();
  }

  /**
   * Connects to the dispatcher.
   */
  public function connect()
  {
    $this->dispatcher->connect('form.method_not_found', array($this, 'listenForMethodNotFound'));
    $this->dispatcher->connect('form.filter_values', array($this, 'filterValues'));
  }

  /**
   * Disconnects the current object from the dispatcher.
   */
  public function disconnect()
  {
    $this->dispatcher->disconnect('form.method_not_found', array($this, 'listenForMethodNotFound'));
    $this->dispatcher->disconnect('form.filter_values', array($this, 'filterValues'));
  }

  /**
   * Adds the "embedDynamicRelation" method to sfForm.
   *
   * @param sfEvent $event A "form.method_not_found" event
   *
   * @return boolean Whether the event was processed
   *
   * @see embedDynamicRelation()
   */
  public function listenForMethodNotFound(sfEvent $event)
  {
    $form = $event->getSubject();

    if ($form instanceof sfFormDoctrine && 'embedDynamicRelation' == $event['method'])
    {
      call_user_func_array(array($this, 'embedDynamicRelation'), array_merge(array($form), $event['arguments']));
      return true;
    }

    return false;
  }

  /**
   * Filters form values before they're bound.
   *
   * Re-embeds all dynamically embedded relations to match up with the input values.
   *
   * @param sfEvent $event  A "form.filter_values" event
   * @param array   $values Tainted form values
   */
  public function filterValues(sfEvent $event, $values)
  {
    $form = $event->getSubject();

    $this->reEmbed($form, $values, true);

    return $values;
  }

  // protected

  /**
   * Re-embeds all dynamically embedded relations recursively to match up with the input values.
   *
   * @param sfForm $form   A form
   * @param array  $values Tainted form values
   */
  protected function reEmbed(sfForm $form, $values, $addListener = false)
  {
    if ($relations = $form->getOption('dynamic_relations'))
    {
      foreach (array_keys($relations) as $field)
      {
        $this->doEmbed($form, $field, isset($values[$field]) ? $values[$field] : array());
      }

      // add an event listener to process delete of relations
      if(true === $addListener)
      {
        $form->getObject()->addListener(new sfDoctrineDynamicFormRelationsListener($form));
      }

      // recursive re-embed down the line
      foreach ($form->getEmbeddedForm($field)->getEmbeddedForms() as $i => $embed)
      {
        $this->reEmbed($embed, $values[$field][$i]);
      }
    }
  }

  /**
   * Embeds a dynamic relation in a form.
   *
   * @param sfForm $form         A form
   * @param string $relationName A relation name and optional alias
   * @param string $formClass    A form class
   * @param array  $formArgs     Arguments for the form constructor
   */
  protected function embedDynamicRelation(sfForm $form, $relationName, $formClass = null, $formArgs = array())
  {
    // get relation and determine the field name to use for embedding
    if (false !== $pos = stripos($relationName, ' as '))
    {
      $relation = $form->getObject()->getTable()->getRelation(substr($relationName, 0, $pos));
      $field = substr($relationName, $pos + 4);
    }
    else
    {
      $relation = $form->getObject()->getTable()->getRelation($relationName);
      $field = sfInflector::underscore($relationName);
    }

    // validate relation type
    if (Doctrine_Relation::MANY != $relation->getType())
    {
      throw new LogicException(sprintf('The %s "%s" relation is not a MANY relation.', get_class($form->getObject()), $relation->getAlias()));
    }

    // use the default form class
    if (null === $formClass)
    {
      $formClass = $relation->getClass().'Form';
    }

    // store configuration for this relation to the form
    $config = $form->getOption('dynamic_relations');
    $form->setOption('dynamic_relations', array_merge($config ? $config : array(), array($field => array(
      'relation'  => $relation,
      'class'     => $formClass,
      'arguments' => $formArgs,
    ))));

    // do the actual embedding
    $this->doEmbed($form, $field, $form->getObject()->get($relation->getAlias()));
  }

  /**
   * Does the actual embedding.
   *
   * This method is called when a relation is dynamically embedded during form
   * configuration and again just before input values are validated.
   *
   * @param sfForm                    $form   A form
   * @param string                    $field  A field name to use for embedding
   * @param Doctrine_Collection|array $values An collection of values (objects or arrays) to use for embedding
   */
  protected function doEmbed(sfForm $form, $field, $values)
  {
    $relations = $form->getOption('dynamic_relations');
    $config = $relations[$field];

    $r = new ReflectionClass($config['class']);

    $parent = new BaseForm();
    foreach ($values as $i => $value)
    {
      if (is_object($value))
      {
        $child = $r->newInstanceArgs(array_merge(array($value), $config['arguments']));
      }
      elseif ($value['id'])
      {
        $child = $this->findEmbeddedFormById($form, $field, $value['id']);

        if (!$child)
        {
          throw new InvalidArgumentException(sprintf('Could not find a previously embedded form with id "%s".', $value['id']));
        }
      }
      else
      {
        $object = $config['relation']->getTable()->create();
        $object->fromArray($value);
        $form->getObject()->get($config['relation']->getAlias())->add($object);

        $child = $r->newInstanceArgs(array_merge(array($object), $config['arguments']));
      }

      // form must include PK widget
      if (!isset($child['id']))
      {
        throw new LogicException(sprintf('The %s form must include an "id" field to be embedded as dynamic relation.', get_class($child)));
      }

      $parent->embedForm($i, $child);
    }

    // workaround embedding despite being bound
    // this is why this class extends sfForm
    $wasBound = $form->isBound;
    $form->isBound = false;
    $form->embedForm($field, $parent);
    $form->isBound = $wasBound;
  }

  /**
   * Finds a form embedded in the supplied form based on id.
   *
   * @param sfForm $form  A form
   * @param string $field The field name used for embedding
   * @param mixed  $id    An id value
   *
   * @return sfForm|null The embedded form, if one is found
   */
  protected function findEmbeddedFormById(sfForm $form, $field, $id)
  {
    foreach ($form->getEmbeddedForm($field)->getEmbeddedForms() as $embed)
    {
      if ($id == $embed->getObject()->get('id'))
      {
        return $embed;
      }
    }
  }
}
