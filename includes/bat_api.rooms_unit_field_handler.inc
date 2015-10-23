<?php

interface RoomsUnitFieldHandlerInterface {
	public function getFieldType();

	public function getFieldInfo();
}

abstract class RoomsUnitFieldHandlerBase implements RoomsUnitFieldHandlerInterface {
	protected $unit;

	public function __construct(RoomsUnit $unit) {
		$this->unit = $unit;
	}
}
