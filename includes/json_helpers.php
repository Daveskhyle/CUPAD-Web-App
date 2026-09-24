<?php

/**
 * Safely reads and decodes a JSON file with exclusive file locking.
 *
 * @param string $file_path The absolute path to the JSON file.
 * @return array The decoded JSON data, or an empty array if the file is empty,
 *               does not exist, or is corrupted.
 */
function readJsonFile(string $file_path): array
{
    $data = [];
    $file_handle = fopen($file_path, 'c+'); // Open for reading and writing, create if not exists

    if ($file_handle === false) {
        error_log("Failed to open file for reading: " . $file_path);
        return [];
    }

    // Acquire exclusive lock
    if (flock($file_handle, LOCK_EX)) {
        clearstatcache(); // Clear file status cache
        if (filesize($file_path) > 0) {
            $json_content = fread($file_handle, filesize($file_path));
            $decoded_data = json_decode($json_content, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_data)) {
                $data = $decoded_data;
            } else {
                error_log("JSON decode error in " . $file_path . ": " . json_last_error_msg());
                // Optionally, attempt to repair or backup corrupted file
            }
        }
        flock($file_handle, LOCK_UN); // Release the lock
    } else {
        error_log("Failed to acquire read lock for file: " . $file_path);
    }

    fclose($file_handle);
    return $data;
}

/**
 * Safely encodes and writes data to a JSON file with exclusive file locking.
 *
 * @param string $file_path The absolute path to the JSON file.
 * @param array $data The data to encode and write.
 * @return bool True on success, false on failure.
 */
function writeJsonFile(string $file_path, array $data): bool
{
    $success = false;
    $file_handle = fopen($file_path, 'c+'); // Open for reading and writing, create if not exists

    if ($file_handle === false) {
        error_log("Failed to open file for writing: " . $file_path);
        return false;
    }

    // Acquire exclusive lock
    if (flock($file_handle, LOCK_EX)) {
        ftruncate($file_handle, 0); // Truncate file to 0 length
        rewind($file_handle); // Rewind to the beginning of the file

        $json_content = json_encode($data, JSON_PRETTY_PRINT);
        if ($json_content === false) {
            error_log("JSON encode error for " . $file_path . ": " . json_last_error_msg());
        } else {
            if (fwrite($file_handle, $json_content) !== false) {
                $success = true;
            } else {
                error_log("Failed to write to file: " . $file_path);
            }
        }
        flock($file_handle, LOCK_UN); // Release the lock
    } else {
        error_log("Failed to acquire write lock for file: " . $file_path);
    }

    fclose($file_handle);
    return $success;
}

/**
 * Backward-compatible wrapper: snake_case function name used across the codebase.
 */
function read_json_file($file_path) {
    // Maintain compatibility with older code that expects an array return
    return readJsonFile($file_path);
}

/**
 * Backward-compatible wrapper: snake_case function name used across the codebase.
 */
function write_json_file($file_path, $data) {
    return writeJsonFile($file_path, is_array($data) ? $data : (array)$data);
}

?>