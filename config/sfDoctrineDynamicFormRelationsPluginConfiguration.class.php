<?php

/**
 * sfDoctrineDynamicFormRelationsPlugin configuration.
 * 
 * @package    sfDoctrineDynamicFormRelationsPlugin
 * @subpackage config
 * @author     Kris Wallsmith <kris.wallsmith@symfony-project.com>
 * @version    SVN: $Id$
 */
class sfDoctrineDynamicFormRelationsPluginConfiguration extends sfPluginConfiguration
{
  /**
   * @see sfPluginConfiguration
   */
  public function initialize()
  {
    new sfDoctrineDynamicFormRelations($this->dispatcher);
  }
}
