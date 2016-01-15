<?php

interface BatUnitFieldHandlerInterface {
  public function getFieldType();

  public function getFieldInfo();
}

abstract class BatUnitFieldHandlerBase implements BatUnitFieldHandlerInterface {
  protected $unit;

  public function __construct(BatUnit $unit) {
    $this->unit = $unit;
  }
}
