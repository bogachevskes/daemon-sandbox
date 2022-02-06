#!/usr/bin/env php
<?php

include './app/tasks/Task.php';

$filesDir = __DIR__ . '/runtime/queue';

$task = new Task;

$taskFile = $filesDir . '/task-' . microtime() . '.dtask';

file_put_contents($taskFile, serialize($task));