<?php

interface BatTypeFieldHandlerInterface {
  public function getFieldType();

  public function getFieldInfo();
}

abstract class BatTypeFieldHandlerBase implements BatTypeFieldHandlerInterface {
  protected $type;

  public function __construct(BatType $type) {
    $this->type = $type;
  }
}
