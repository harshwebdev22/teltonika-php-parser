<?php

include('../vendor/autoload.php');

use Uro\TeltonikaFmParser\FmParser;
use Uro\TeltonikaFmParser\Protocol\Tcp\Reply;

function formatTimestamp($timestamp)
{
    $timestampInSeconds = $timestamp / 1000; // Convert to seconds
    return date('d-m-Y H:i:s', $timestampInSeconds);
}

$parser = new FmParser('tcp');
$socket = stream_socket_server("tcp://0.0.0.0:2021", $errno, $errstr);

if (!$socket) {
    throw new Exception("$errstr ($errno)");
}

stream_set_blocking($socket, false); // Set non-blocking for main socket
echo "Server started on 0.0.0.0:2021\n";

$filePath = 'packet_data.json';

// Initialize the JSON file if it doesn't exist
if (!file_exists($filePath)) {
    file_put_contents($filePath, json_encode([]));
}

// Helper function to calculate CRC-16
function calculateCRC16($data)
{
    $crc = 0xFFFF;
    for ($i = 0; $i < strlen($data); $i++) {
        $crc ^= ord($data[$i]) << 8;
        for ($j = 0; $j < 8; $j++) {
            if ($crc & 0x8000) {
                $crc = ($crc << 1) ^ 0x1021;
            } else {
                $crc <<= 1;
            }
        }
    }
    return $crc & 0xFFFF;
}

function createCommand($command)
{
    $commandHex = bin2hex($command);
    $commandSize = sprintf("%08X", strlen($command) / 2);
    $header = "00000000"; // Preamble
    $dataSize = sprintf("%08X", 1 + 1 + 4 + strlen($commandHex) / 2 + 1);
    $codecId = "0C";
    $quantity1 = "01";
    $type = "05";
    $quantity2 = "01";

    $rawData = $codecId . $quantity1 . $type . $commandSize . $commandHex . $quantity2;
    $crc = sprintf("%04X", calculateCRC16(hex2bin($rawData)));

    return $header . $dataSize . $rawData . $crc;
}

while (true) {
    $conn = @stream_socket_accept($socket, 1); // Accept connections with a timeout of 1 second

    if ($conn) {
        stream_set_timeout($conn, 10); // Set a timeout for the client connection
        echo "connection accepted!\n";

        try {
            // Read IMEI
            $payload = fread($conn, 10240);
            $imeiObject = $parser->decodeImei($payload);

            // Accept packet
            fwrite($conn, Reply::accept());

            // Read Data
            $payload = fread($conn, 10240);
            if (empty($payload)) {
                throw new Exception("No data received from the device.");
            }

            $packet = $parser->decodeData($payload);
            fwrite($conn, $parser->encodeAcknowledge($packet));

            $imei = $imeiObject->getImei();
            echo "Processing data for IMEI: $imei\n";

            // sending command to the device
            $command = createCommand("getinfo");
            fwrite($conn, hex2bin($command));

            echo "Sent $command command to IMEI: $imei\n";


            // Prepare AVL data
            $avlDataArray = [];
            foreach ($packet->getAvlDataCollection()->getAvlData() as $avlData) {
                $avlDataArray[] = [
                    'timestamp' => formatTimestamp($avlData->getTimestamp()),
                    'priority' => $avlData->getPriority(),
                    'gpsElement' => [
                        'longitude' => $avlData->getGpsElement()->getLongitude(),
                        'latitude' => $avlData->getGpsElement()->getLatitude(),
                        'altitude' => $avlData->getGpsElement()->getAltitude(),
                        'angle' => $avlData->getGpsElement()->getAngle(),
                        'satellites' => $avlData->getGpsElement()->getSatellites(),
                        'speed' => $avlData->getGpsElement()->getSpeed()
                    ],
                    'ioElement' => array_map(
                        fn($ioProperty) => $ioProperty->getValue()->toUnsigned(),
                        $avlData->getIoElement()->getProperties()
                    )
                ];
            }


            // Load existing data
            $existingData = json_decode(file_get_contents($filePath), true);

            // Append data for the current IMEI
            if (!isset($existingData[$imei])) {
                $existingData[$imei] = []; // Initialize IMEI key if not present
            }
            $existingData[$imei] = array_merge($existingData[$imei], $avlDataArray);

            // Save updated data
            if (file_put_contents($filePath, json_encode($existingData, JSON_PRETTY_PRINT))) {
                echo "Data successfully updated for IMEI: $imei\n";
            } else {
                echo "Error updating data for IMEI: $imei\n";
            }
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
        } finally {
            fclose($conn); // Close connection
        }
    }

    // Perform periodic maintenance (if needed)
}

fclose($socket);
