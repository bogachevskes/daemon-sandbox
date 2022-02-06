#!/usr/bin/env php
<?php

include './app/MasterContainer.php';
include './tasks/Task.php';

function clearLogsDir(string $dir): void
{
    foreach(scandir($dir) as $file) {
        if (in_array($file, ['.', '..']) === true) {
            continue;
        }

        unlink("$dir/$file");
    }
}

function loop(string $taskTrackerLogFile, string $tasksDir, string $processId): void
{
    $currentStep = 1;
    
    $processId = getmypid();

    echo "Запущен дочерний процесс #" . $processId . "\n";
    
    while (true) {
        
        echo "\n=================================\n";
        echo "Процесс #" . $processId . "\n";
        echo "Использовано памяти в МБ: " . round(((memory_get_usage() / 1024) / 1024), 2) . "М\n";
        echo "loop step $currentStep\n";
    
        $tasks = scandir($tasksDir);
    
        $tasks = array_diff($tasks, array('.', '..'));
    
        $totalCount = count($tasks);
    
        if ($totalCount === 0) {
            echo "\033[31mФайлы не найдены, ожидание файлов\033[0m\n";
        }
    
        if ($totalCount > 0) {
            echo "\033[32mНайдены файлы.\nВсего файлов: " . $totalCount . ".\nСписок файлов: \033[0m\n\n";
            
            foreach($tasks as $task) {
                
                echo "\033[33m$task\033[0m\n";

                $taskPath = $tasksDir . '/' . $task;
                
                $taskContent = file_get_contents($taskPath);
                unlink($taskPath);
    
                if (empty($taskContent) === true) {
                    echo "\033[31mФайл пуст\033[0m\n";
    
                    continue;
                }
                
                echo "\033[95mНайдена задача. Выполняю:\033[0m\n";
                echo "\033[39m" . $taskContent . "\033[0m\n\n";

                $task = unserialize($taskContent);

                if ($task instanceof Task) {

                    MasterContainer::$messages[] = "check #" . getmypid();
                    
                    $task->doSome();

                    file_put_contents($taskTrackerLogFile, "Запрос {$task->id} обработан дочерним процессом #{$processId}\n", FILE_APPEND | LOCK_EX);
                }

            }
    
        }
    
        sleep(1);
    
        $currentStep++;
    }
}

$logDir = __DIR__ . '/runtime/logs';
$tasksDir = __DIR__ . '/runtime/queue';
$taskTrackerLogFile = "{$logDir}/task_tracker.log";
$masterLogFile = "{$logDir}/master.log";

if (is_dir($logDir) === true) {
    clearLogsDir($logDir);
}

if (is_dir($logDir) === false) {
    mkdir($logDir);
}

if (is_dir($tasksDir) === false) {
    mkdir($tasksDir);
}

if (file_exists($taskTrackerLogFile) === false) {
    touch($taskTrackerLogFile);
}

if (file_exists($masterLogFile) === false) {
    touch($masterLogFile);
}

MasterContainer::$messages[] = "Создан родительский процесс #" . getmypid();

for ($x = 1; $x <= 5; $x++) {
    $pid = pcntl_fork();

    if ($pid == -1) {

        die('Не удалось породить дочерний процесс');
    
    }

    if ($pid === 0) {
        
        /**
         * Для демонстрации того, что родительский
         * и дочерний процесс не имеют общего состояния.
         */
        MasterContainer::$messages[] = "Создан дочерний процесс #" . getmypid();
        
        // смена вывода на вывод в файлы
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
    
        $processId = getmypid();
    
        $STDIN = fopen('/dev/null', 'r');
        $STDOUT = fopen($logDir . '/output-process-' . $processId . '.log', 'wb');
        $STDERR = fopen($logDir . '/error-process-' . $processId . '.log', 'wb');
        
        loop($taskTrackerLogFile, $tasksDir, $processId);
    }

}

MasterContainer::$messages[] = "Родительский процесс #" . getmypid() . " запущен в режиме отслеживания";
    
while(true) {
    sleep(1);

    if (count(MasterContainer::$messages) > 0) {
        $content = implode("\n", MasterContainer::$messages) . "\n";

        MasterContainer::$messages = [];

        file_put_contents($masterLogFile, $content, FILE_APPEND | LOCK_EX);
    }

}

exit(0);