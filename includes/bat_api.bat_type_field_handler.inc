<?php

/**
 * @file
 */

/**
 *
 */
interface BatTypeFieldHandlerInterface {

  /**
   * @return string
   */
  public function getFieldType();

  /**
   * @return array
   */
  public function getFieldInfo();

}

/**
 *
 */
abstract class BatTypeFieldHandlerBase implements BatTypeFieldHandlerInterface {

  /**
   *
   */
  protected $type;

  /**
   * @param BatType $type
   */
  public function __construct(BatType $type) {
    $this->type = $type;
  }

}
