<?php

class Task
{
    public $id;

    public function __construct()
    {
        $this->id = rand(1e5, 9e5);
    }
    
    public function doSome(): void
    {
        sleep(1);
        echo "\033[32mЗадача выполнена\033[0m\n";
    }
}