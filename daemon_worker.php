#!/usr/bin/env php
<?php

/**
 * Заметки:
 * 1. Родительский и дочерний процесс не имеют общей памяти,
 * соответственно не имеют общего синглтона.
 * Общение нужно вести через сокеты.
 */
include './app/queue/Queue.php';

function clearLogsDir(string $dir): void
{
    foreach(scandir($dir) as $file) {
        if (in_array($file, ['.', '..']) === true) {
            continue;
        }

        unlink("$dir/$file");
    }
}

function addWorker(&$currentProcesses, $logDir, $taskTrackerLogFile)
{
    $stream = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    $pid = pcntl_fork();

    if ($pid == -1) {

        fclose($stream[0]);
        fclose($stream[1]);

        die('Не удалось породить дочерний процесс');
    
    }

    if ($pid) {
        
        fclose($stream[0]);
        stream_set_blocking($stream[1], false);

        $currentTime = new DateTime();
        
        $liveTime = (clone $currentTime)->add(new DateInterval('PT1M'));

        $currentProcesses[$pid] = [
            'stream' => $stream[1],
            'in_progress' => false,
            'started_at' => $currentTime->format('Y-m-d H:i:s'),
            'alive_until' => $liveTime->format('Y-m-d H:i:s'),
        ];
        
    }

    if ((bool) $pid === false) {
        
        $currentPid = getmypid();

        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);

        $STDIN = fopen('/dev/null', 'r');
        $STDOUT = fopen($logDir . '/output-worker-' . $currentPid . '.log', 'wb');
        $STDERR = fopen($logDir . '/error-process-' . $currentPid . '.log', 'wb');

        fclose($stream[1]);

        echo "Создан дочерний процесс #{$currentPid}\n";

        $resultText = "Выполнена задача #";

        while (feof($stream[0]) === false) {

            $line = fgets($stream[0]);

            $task = \trim($line);

            if ($task === 'exit') {
                
                break;
            }

            $task = unserialize($task);

            $result = $task->doSome();

            $message = $resultText . $task->id . "\nРезультат выполнения: " . $result . "\n";

            file_put_contents($taskTrackerLogFile, $message, FILE_APPEND | LOCK_EX);

            fwrite($stream[0], "finished:" . getmypid() . "\n");
        }
    
        fclose($stream[0]);

        echo "Выполнение остановлено родителем\n";

        exit(0);

    }
    
}

function sendToWorker(array &$currentProcesses, string $taskContent): ?string
{
    foreach ($currentProcesses as $key => &$process) {

        if ($process['in_progress'] === false) {
            
            fwrite($process['stream'], $taskContent . "\n");
            
            return $key;
        }

    }

    return null;
}

function checkWorkerStatuses(array &$currentProcesses, $logDir)
{
    $streams = [];

    foreach ($currentProcesses as $process) {
        $streams[] = $process['stream'];
    }

    if (stream_select($streams, $write , $except, 0) === 0) {
        return;
    }

    foreach ($streams as $socket) {
        $line = fgets($socket);
        $line = \trim($line);

        preg_match_all("/^finished\:(\d*+)\$/", $line, $matches);

        if (isset($matches[1][0]) === false) {
            continue;
        }

        $pid = $matches[1][0];

        $currentProcesses[$pid]['in_progress'] = false;

        echo "\033[36mОсвободился обработчик: #{$pid}\033[0m\n";
    }

    $currentTime = new DateTime();

    foreach ($currentProcesses as $key => $process) {
        $time = new DateTime($process['alive_until']);

        if ($currentTime > $time) {

            unset($currentProcesses[$key]);

            fwrite($process['stream'], "exit\n");

            file_put_contents($logDir . "/master.log", "Время жизни процесса #" . $key . " истекло\n", FILE_APPEND | LOCK_EX);
        }
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

$currentStep = 1;

$processId = getmypid();

$maxChildrenCount = 7;

$currentProcesses = [];

// смена вывода на вывод в файлы
/*
fclose(STDIN);
fclose(STDOUT);
fclose(STDERR);

$STDIN = fopen('/dev/null', 'r');
$STDOUT = fopen($logDir . '/output-process-' . $processId . '.log', 'wb');
$STDERR = fopen($logDir . '/error-process-' . $processId . '.log', 'wb');
*/

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

    if (count($currentProcesses) > 0) {
        checkWorkerStatuses($currentProcesses, $logDir);
    }

    if ($totalCount > 0) {
        echo "\033[32mНайдены файлы.\nВсего файлов: " . $totalCount . ".\nСписок файлов: \033[0m\n\n";
        
        foreach($tasks as $task) {
            
            echo "\033[33m$task\033[0m\n";

            $taskPath = $tasksDir . '/' . $task;
            
            $taskContent = file_get_contents($taskPath);

            if (empty($taskContent) === true) {
                echo "\033[31mФайл пуст\033[0m\n";
                unlink($taskPath);

                continue;
            }
            
            echo "\033[95mНайдена задача.\033[0m\n";
            echo "\033[39m" . $taskContent . "\033[0m\n";

            $worker = sendToWorker($currentProcesses, $taskContent);

            if ($worker !== null) {
                echo "\033[95mПередано обработчику: #{$worker}\033[0m\n\n";
                $currentProcesses[$worker]['in_progress'] = true;
                unlink($taskPath);

                continue;
            }

            if (count($currentProcesses) === $maxChildrenCount) {

                echo "\033[93mДостигнуто максимальное кол-во обработчиков.\nОжидание свободного обработчика\033[0m\n";
                break;

            }

            addWorker($currentProcesses, $logDir, $taskTrackerLogFile);

            $worker = sendToWorker($currentProcesses, $taskContent);
            
            echo "\033[95mПередано обработчику: #{$worker}\033[0m\n\n";
            $currentProcesses[$worker]['in_progress'] = true;

            unlink($taskPath);
        }

    }

    sleep(1);

    $currentStep++;
}