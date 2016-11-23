<?php

/**
 * @file
 */

/**
 *
 */
interface BatUnitFieldHandlerInterface {

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
abstract class BatUnitFieldHandlerBase implements BatUnitFieldHandlerInterface {

  /**
   *
   */
  protected $unit;

  /**
   * @param BatUnit $unit
   */
  public function __construct(BatUnit $unit) {
    $this->unit = $unit;
  }

}
