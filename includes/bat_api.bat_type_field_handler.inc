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
   * @var BatType
   */
  protected $type;

  /**
   * @param BatType $type
   */
  public function __construct(BatType $type) {
    $this->type = $type;
  }

}
