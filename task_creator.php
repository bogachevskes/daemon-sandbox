#!/usr/bin/env php
<?php

include './app/queue/Queue.php';

$filesDir = __DIR__ . '/runtime/queue';

for ($i = 1; $i <= 1000; $i++) {
    $task = new Task;

    $taskFile = $filesDir . '/task-' . $task->id . '.qtask';
    
    file_put_contents($taskFile, serialize($task));
}