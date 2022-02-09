<?php

class Task
{
    public $id;

    public function __construct()
    {
        $this->id = rand(1e5, 9e5);
    }
    
    public function doSome(): string
    {
        sleep(5);
        return "Задача {$this->id} выполнена";
    }
}