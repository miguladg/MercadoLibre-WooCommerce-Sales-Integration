<?php

// Función para registrar la hora de ejecución en el archivo storyCrons.txt
function logExecutionTime() {
    // Obtener la fecha y hora actual en formato ISO 8601 con milisegundos
    $currentDateTime = (new DateTime())->format('Y-m-d H:i:s.v');

    // Definir el nombre del archivo
    $filename = 'storyCrons.txt';

    // Crear el mensaje con la hora de ejecución
    $logMessage = "Execution Time: " . $currentDateTime . PHP_EOL;

    // Escribir o agregar el mensaje al archivo (APPEND asegura que no borre las ejecuciones anteriores)
    file_put_contents($filename, $logMessage, FILE_APPEND);

    echo "Execution time logged in storyCrons.txt" . PHP_EOL;
}

// Llamar a la función para registrar la hora de ejecución
logExecutionTime();

?>
